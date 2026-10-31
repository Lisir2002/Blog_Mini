<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit\Auth;

use PenBlog\Api\ValidationException;
use PenBlog\Auth\UserRepository;
use PenBlog\Auth\XpService;
use PenBlog\Notification\Events\LevelPromoted;
use PenBlog\Tests\Integration\KernelTestCase;

/**
 * 09 §2 经验引擎:默认关闭、幂等记账、每日上限、晋升阶梯(L0 永不自动)、设置校验。
 */
final class XpServiceTest extends KernelTestCase
{
    private XpService $xp;
    private UserRepository $users;
    private int $bobId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->xp = $this->kernel->container->get(XpService::class);
        $this->users = $this->kernel->container->get(UserRepository::class);
        $this->bobId = $this->users->create('bob', 'bob@example.com', 'password123', 3)->id();
    }

    public function testDisabledByDefault(): void
    {
        self::assertFalse($this->xp->settings()['enabled']);
        $r = $this->xp->grant($this->bobId, 'comments_posted', 1, 'c1');
        self::assertSame('disabled', $r['status']);
        self::assertSame(0, self::countEvents());
    }

    public function testGrantIdempotentAndAccumulate(): void
    {
        $this->xp->saveSettings(['enabled' => true]);
        $first = $this->xp->grant($this->bobId, 'comments_posted', 1, 'c1');
        self::assertSame('granted', $first['status']);
        self::assertSame(15, $first['xp']);
        self::assertSame(15, $first['total']);

        $dup = $this->xp->grant($this->bobId, 'comments_posted', 1, 'c1');
        self::assertSame('skipped', $dup['status']);
        self::assertSame(15, $dup['total'], '同事件 ID 只计一次');

        $second = $this->xp->grant($this->bobId, 'comments_posted', 1, 'c2');
        self::assertSame(30, $second['total']);
    }

    public function testDailyCapLimitsAmount(): void
    {
        $this->xp->saveSettings([
            'enabled' => true,
            'rules' => [['metric' => 'reading_minutes', 'xp_per_unit' => 1, 'per_day_cap' => 30]],
        ]);
        $big = $this->xp->grant($this->bobId, 'reading_minutes', 50, 'r1');
        self::assertSame(30, $big['xp'], '当日累计量封顶 30');
        $more = $this->xp->grant($this->bobId, 'reading_minutes', 10, 'r2');
        self::assertSame('granted', $more['status']);
        self::assertSame(0, $more['xp'], '封顶后当日不再增长');
        self::assertSame(30, $more['total']);
    }

    public function testPromotionLadderNeverReachesL0(): void
    {
        $this->xp->saveSettings([
            'enabled' => true,
            'thresholds' => ['2' => 100, '1' => 1000],
        ]);
        $toL2 = $this->xp->grant($this->bobId, 'comments_posted', 10, 'c1'); // 150 xp
        self::assertSame(['from' => 3, 'to' => 2], $toL2['promoted']);
        self::assertSame(2, $this->users->findById($this->bobId)->level());

        $toL1 = $this->xp->grant($this->bobId, 'comments_posted', 60, 'c2'); // 1050 xp
        self::assertSame(['from' => 2, 'to' => 1], $toL1['promoted']);

        $huge = $this->xp->grant($this->bobId, 'comments_posted', 100, 'c3'); // 2550 xp
        self::assertNull($huge['promoted'], 'L0 永不自动授予');
        self::assertSame(1, $this->users->findById($this->bobId)->level());
    }

    public function testAdminLevelUnchangedByXp(): void
    {
        $this->xp->saveSettings(['enabled' => true, 'thresholds' => ['2' => 10]]);
        $adminId = $this->users->findByUsername('admin')->id();
        $r = $this->xp->grant($adminId, 'posts_published', 5, 'p1');
        self::assertNull($r['promoted'], 'L0 不参与自动晋升');
        self::assertSame(0, $this->users->findById($adminId)->level());
    }

    public function testPromotionDispatchesEvent(): void
    {
        $seen = [];
        $this->kernel->events->listen(LevelPromoted::class, static function (LevelPromoted $e) use (&$seen): void {
            $seen[] = $e;
        });
        $this->xp->saveSettings(['enabled' => true, 'thresholds' => ['2' => 20]]);
        $this->xp->grant($this->bobId, 'comments_posted', 2, 'c1'); // 30 xp → L2
        self::assertCount(1, $seen);
        self::assertSame($this->bobId, $seen[0]->userId);
        self::assertSame(3, $seen[0]->oldLevel);
        self::assertSame(2, $seen[0]->newLevel);
        self::assertSame('bob', $seen[0]->username);
    }

    public function testInvalidMetricRejected(): void
    {
        $this->xp->saveSettings(['enabled' => true]);
        $this->expectException(ValidationException::class);
        $this->xp->grant($this->bobId, 'sword_fishing', 1, 'x1');
    }

    public function testSettingsValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->xp->saveSettings(['rules' => [['metric' => 'bogus', 'xp_per_unit' => 1]]]);
    }

    public function testDuplicateMetricRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->xp->saveSettings(['rules' => [
            ['metric' => 'comments_posted', 'xp_per_unit' => 1],
            ['metric' => 'comments_posted', 'xp_per_unit' => 2],
        ]]);
    }

    public function testThresholdLevelZeroRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->xp->saveSettings(['thresholds' => ['0' => 9999]]);
    }

    private function countEvents(): int
    {
        return (int) $this->kernel->db()->pdo()->query('SELECT COUNT(*) FROM user_xp_events')->fetchColumn();
    }
}
