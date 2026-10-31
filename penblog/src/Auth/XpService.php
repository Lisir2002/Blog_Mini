<?php

declare(strict_types=1);

namespace PenBlog\Auth;

use PenBlog\Api\ApiException;
use PenBlog\Api\ValidationException;
use PenBlog\Kernel\EventBus;
use PenBlog\Kernel\Kernel;
use PenBlog\Kernel\OptionsStore;
use PenBlog\Notification\Events\CommentCreated;
use PenBlog\Notification\Events\LevelPromoted;
use PenBlog\Notification\Events\PostPublished;
use PenBlog\Repository\SqlDriver;
use Psr\Log\LoggerInterface;

/**
 * 经验系统(09 §2,默认关闭):options 存开关/规则/阈值,追加表 user_xp_events
 * 幂等记账(同 event_key 只计一次),per_day_cap 限当日累计量防刷;
 * 达到阈值自动晋升(只升不降,L0 永不自动授予),晋升派发 LevelPromoted → system 通知。
 * 红线:记账失败绝不阻断业务(事件监听方吞异常)。
 */
final class XpService
{
    public const OPTION_ENABLED = 'auth.xp.enabled';
    public const OPTION_RULES = 'auth.xp.rules';
    public const OPTION_THRESHOLDS = 'auth.xp.thresholds';

    /** 09 §2 指标白名单。 */
    public const METRICS = ['reading_minutes', 'days_visited', 'comments_posted', 'comments_received_like', 'posts_published'];

    /** @var list<array{metric:string, xp_per_unit:int, per_day_cap:?int}> */
    private const DEFAULT_RULES = [
        ['metric' => 'reading_minutes', 'xp_per_unit' => 1, 'per_day_cap' => 30],
        ['metric' => 'days_visited', 'xp_per_unit' => 20, 'per_day_cap' => null],
        ['metric' => 'comments_posted', 'xp_per_unit' => 15, 'per_day_cap' => null],
        ['metric' => 'comments_received_like', 'xp_per_unit' => 5, 'per_day_cap' => null],
        ['metric' => 'posts_published', 'xp_per_unit' => 40, 'per_day_cap' => null],
    ];

    /** 升到目标等级所需累计经验;键仅允许 1/2(L0 永不自动,L3 为起点)。 */
    private const DEFAULT_THRESHOLDS = [2 => 200, 1 => 1200];

    public function __construct(
        private readonly SqlDriver $driver,
        private readonly OptionsStore $options,
        private readonly UserRepository $users,
        private readonly EventBus $events,
    ) {
    }

    /** 事件接线(仿 NotificationService):既有领域事件 → 经验记账。 */
    public static function register(Kernel $kernel): void
    {
        $c = $kernel->container;
        $xp = static fn (): self => $c->get(self::class);
        $safe = static function (callable $fn) use ($c): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                $c->get(LoggerInterface::class)->warning('xp grant failed: ' . $e->getMessage());
            }
        };

        // 已过审评论 → comments_posted(待审不计,防灌水;guest 无 userId 不计)
        $kernel->events->listen(CommentCreated::class, static function (CommentCreated $e) use ($xp, $safe): void {
            if ($e->userId === null || $e->status !== 'approved') {
                return;
            }
            $safe(static fn () => $xp()->grant($e->userId, 'comments_posted', 1, 'comment:' . $e->commentId));
        });

        // 文章发布 → posts_published(作者账号存在才计)
        $kernel->events->listen(PostPublished::class, static function (PostPublished $e) use ($xp, $c, $safe): void {
            $safe(static function () use ($xp, $c, $e): void {
                $author = $c->get(UserRepository::class)->findByUsername($e->authorUsername);
                if ($author !== null) {
                    $xp()->grant($author->id(), 'posts_published', 1, 'post:' . $e->slug);
                }
            });
        });
    }

    public function isEnabled(): bool
    {
        return (bool) $this->options->get(self::OPTION_ENABLED, false);
    }

    /** @return array{enabled:bool, rules:list<array<string, mixed>>, thresholds:array<string, int>} */
    public function settings(): array
    {
        $rules = $this->options->get(self::OPTION_RULES);
        $thresholds = $this->options->get(self::OPTION_THRESHOLDS);
        return [
            'enabled' => $this->isEnabled(),
            'rules' => is_array($rules) && $rules !== [] ? array_values($rules) : self::DEFAULT_RULES,
            'thresholds' => is_array($thresholds) && $thresholds !== []
                ? self::normalizeThresholds($thresholds)
                : array_combine(array_map(strval(...), array_keys(self::DEFAULT_THRESHOLDS)), array_values(self::DEFAULT_THRESHOLDS)),
        ];
    }

    /**
     * 校验并整组落盘;返回保存后的设置。
     *
     * @param array<string, mixed> $raw
     * @return array{enabled:bool, rules:list<array<string, mixed>>, thresholds:array<string, int>}
     */
    public function saveSettings(array $raw): array
    {
        $enabled = ($raw['enabled'] ?? null) !== null ? (bool) $raw['enabled'] : $this->isEnabled();
        $rules = $this->validateRules($raw['rules'] ?? self::DEFAULT_RULES);
        $thresholds = $this->validateThresholds($raw['thresholds'] ?? self::DEFAULT_THRESHOLDS);

        $this->options->set(self::OPTION_ENABLED, $enabled);
        $this->options->set(self::OPTION_RULES, $rules);
        $this->options->set(self::OPTION_THRESHOLDS, $thresholds);
        return ['enabled' => $enabled, 'rules' => $rules, 'thresholds' => $thresholds];
    }

    /**
     * 幂等记账:同 eventKey 重复上报只计一次;per_day_cap 限当日该指标累计量;
     * 记账后按阈值检查自动晋升(只升不降,自动上限 L1,L0 永不自动)。
     *
     * @return array{status:string, xp:int, total:int, promoted:?array{from:int, to:int}}
     */
    public function grant(int $userId, string $metric, int $amount, string $eventKey): array
    {
        if (!in_array($metric, self::METRICS, true)) {
            throw new ValidationException([['field' => 'metric', 'reason' => 'invalid']], '经验指标需为 ' . implode('/', self::METRICS));
        }
        if ($eventKey === '' || mb_strlen($eventKey) > 64) {
            throw new ValidationException([['field' => 'eventId', 'reason' => 'invalid']], '事件 ID 需为 1-64 字符');
        }
        if (!$this->isEnabled()) {
            return ['status' => 'disabled', 'xp' => 0, 'total' => 0, 'promoted' => null];
        }
        $user = $this->users->findById($userId) ?? throw ApiException::notFound('User');

        $stmt = $this->driver->pdo()->prepare('SELECT 1 FROM user_xp_events WHERE event_key = :k');
        $stmt->execute(['k' => $eventKey]);
        if ($stmt->fetch() !== false) {
            return ['status' => 'skipped', 'xp' => 0, 'total' => $user->xp(), 'promoted' => null];
        }

        $amount = max(0, $amount);
        $rule = $this->ruleFor($metric);
        $xpPerUnit = $rule !== null ? (int) $rule['xp_per_unit'] : 0;
        $cap = $rule !== null && ($rule['per_day_cap'] ?? null) !== null ? (int) $rule['per_day_cap'] : null;
        if ($cap !== null) {
            $sum = $this->driver->pdo()->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM user_xp_events
                 WHERE user_id = :u AND metric = :m AND created_at LIKE :d",
            );
            $sum->execute(['u' => $userId, 'm' => $metric, 'd' => gmdate('Y-m-d') . '%']);
            $used = (int) $sum->fetchColumn();
            $amount = max(0, min($amount, $cap - $used));
        }
        $xp = $amount * $xpPerUnit;

        $this->driver->insertReturningId('user_xp_events', [
            'user_id' => $userId,
            'metric' => $metric,
            'amount' => $amount,
            'xp' => $xp,
            'event_key' => $eventKey,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        if ($xp > 0) {
            $this->driver->pdo()
                ->prepare('UPDATE users SET xp = xp + :x WHERE id = :i')
                ->execute(['x' => $xp, 'i' => $userId]);
        }
        $total = $this->lastXp($userId);

        $promoted = $this->checkPromotion($user, $total);
        return ['status' => 'granted', 'xp' => $xp, 'total' => $total, 'promoted' => $promoted];
    }

    /** @return array{metric:string, xp_per_unit:int, per_day_cap:?int}|null */
    private function ruleFor(string $metric): ?array
    {
        foreach ($this->settings()['rules'] as $rule) {
            if ((string) ($rule['metric'] ?? '') === $metric) {
                return $rule;
            }
        }
        return null;
    }

    private function lastXp(int $userId): int
    {
        $stmt = $this->driver->pdo()->prepare('SELECT xp FROM users WHERE id = :i');
        $stmt->execute(['i' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** 晋升:取经验已达阈值且高于当前级的最高等级(编号最小);L0 永不自动。 */
    private function checkPromotion(User $user, int $total): ?array
    {
        $thresholds = $this->settings()['thresholds'];
        $best = $user->level();
        foreach ($thresholds as $target => $need) {
            $target = (int) $target;
            if ($target >= 1 && $target < $best && $total >= (int) $need) {
                $best = $target;
            }
        }
        if ($best === $user->level()) {
            return null;
        }
        $old = $user->level();
        $this->users->update($user, ['level' => $best]);
        $event = new LevelPromoted($user->id(), $user->username(), $old, $best, $total);
        $this->events->dispatch($event);
        $this->events->doAction('user.level.promoted', $user, $old, $best);
        return ['from' => $old, 'to' => $best];
    }

    /** @param mixed $raw @return list<array<string, mixed>> */
    private function validateRules(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new ValidationException([['field' => 'rules', 'reason' => 'invalid']], '经验规则需为非空数组');
        }
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $i => $row) {
            $row = (array) $row;
            $metric = (string) ($row['metric'] ?? '');
            if (!in_array($metric, self::METRICS, true)) {
                throw new ValidationException([['field' => "rules.{$i}.metric", 'reason' => 'invalid']], "非法指标 {$metric}");
            }
            if (isset($seen[$metric])) {
                throw new ValidationException([['field' => "rules.{$i}.metric", 'reason' => 'duplicate']], "指标 {$metric} 重复");
            }
            $seen[$metric] = true;
            $perUnit = $row['xp_per_unit'] ?? null;
            if (!is_int($perUnit) || $perUnit < 0 || $perUnit > 1000) {
                throw new ValidationException([['field' => "rules.{$i}.xp_per_unit", 'reason' => 'invalid']], 'xp_per_unit 需为 0-1000 整数');
            }
            $cap = $row['per_day_cap'] ?? null;
            if ($cap !== null && (!is_int($cap) || $cap < 1 || $cap > 100000)) {
                throw new ValidationException([['field' => "rules.{$i}.per_day_cap", 'reason' => 'invalid']], 'per_day_cap 需为 1-100000 整数或 null');
            }
            $out[] = ['metric' => $metric, 'xp_per_unit' => $perUnit, 'per_day_cap' => $cap];
        }
        return $out;
    }

    /** @param mixed $raw @return array<string, int> */
    private function validateThresholds(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new ValidationException([['field' => 'thresholds', 'reason' => 'invalid']], '阈值需为对象:{"1":xp,"2":xp}');
        }
        return self::normalizeThresholds($raw, true);
    }

    /** @param array<array-key, mixed> $raw @return array<string, int> */
    private static function normalizeThresholds(array $raw, bool $strict = false): array
    {
        $out = [];
        foreach ($raw as $level => $need) {
            $level = (string) $level;
            if (!in_array($level, ['1', '2'], true)) {
                if ($strict) {
                    throw new ValidationException([['field' => "thresholds.{$level}", 'reason' => 'invalid']], '仅允许等级 1/2(L0 永不自动,L3 为起点)');
                }
                continue;
            }
            $need = (int) $need;
            if ($strict && $need < 0) {
                throw new ValidationException([['field' => "thresholds.{$level}", 'reason' => 'invalid']], '阈值需为非负整数');
            }
            $out[$level] = max(0, $need);
        }
        if ($strict && $out === []) {
            throw new ValidationException([['field' => 'thresholds', 'reason' => 'invalid']], '至少声明等级 1 或 2 的经验阈值');
        }
        return $out;
    }
}
