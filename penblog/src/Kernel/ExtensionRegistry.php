<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

use PenBlog\Repository\SqlDriver;

/**
 * Extension 注册中心(02 §4):kind→class 映射,注册即持久化,查询即分页。
 */
final class ExtensionRegistry
{
    /** @var array<string, class-string<Extension>> */
    private array $typeMap = [];

    public function __construct(private readonly SqlDriver $driver)
    {
    }

    /** @param class-string<Extension> $class */
    public function registerType(string $kind, string $class): void
    {
        $this->typeMap[$kind] = $class;
    }

    /** 撤销类型注册(04 §2 插件 deactivate 回收 ExtensionKind)。 */
    public function unregisterType(string $kind): void
    {
        unset($this->typeMap[$kind]);
    }

    /** 注册即 upsert:同名同 kind 覆盖 spec,status,resourceVersion 递增。 */
    public function register(Extension $extension): Extension
    {
        $pdo = $this->driver->pdo();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $pdo->prepare('SELECT uid, resource_version, creation_timestamp FROM extensions WHERE kind = :kind AND name = :name');
        $stmt->execute(['kind' => $extension->metadata->kind, 'name' => $extension->metadata->name]);
        $existing = $stmt->fetch();

        $meta = $extension->metadata;
        if ($existing === false) {
            $data = [
                'uid' => $meta->uid !== '' ? $meta->uid : bin2hex(random_bytes(16)),
                'kind' => $meta->kind,
                'name' => $meta->name,
                'api_version' => $meta->apiVersion,
                'spec' => json_encode($extension->spec, JSON_UNESCAPED_UNICODE),
                'status' => $extension->status === [] ? null : json_encode($extension->status, JSON_UNESCAPED_UNICODE),
                'creation_timestamp' => $meta->creationTimestamp ?? $now,
                'update_timestamp' => $now,
                'resource_version' => 1,
            ];
            $this->driver->insertReturningId('extensions', $data);
            $extension->metadata = new Metadata(
                name: $meta->name, uid: $data['uid'], kind: $meta->kind, apiVersion: $meta->apiVersion,
                creationTimestamp: $data['creation_timestamp'], updateTimestamp: $now, resourceVersion: 1,
            );
        } else {
            $version = (int) $existing['resource_version'] + 1;
            $stmt = $pdo->prepare(
                'UPDATE extensions SET spec = :spec, status = :status, update_timestamp = :ts, resource_version = :v
                 WHERE kind = :kind AND name = :name'
            );
            $stmt->execute([
                'spec' => json_encode($extension->spec, JSON_UNESCAPED_UNICODE),
                'status' => $extension->status === [] ? null : json_encode($extension->status, JSON_UNESCAPED_UNICODE),
                'ts' => $now,
                'v' => $version,
                'kind' => $meta->kind,
                'name' => $meta->name,
            ]);
            $extension->metadata = new Metadata(
                name: $meta->name, uid: (string) $existing['uid'], kind: $meta->kind, apiVersion: $meta->apiVersion,
                creationTimestamp: (string) $existing['creation_timestamp'], updateTimestamp: $now, resourceVersion: $version,
            );
        }
        return $extension;
    }

    public function find(string $kind, string $name): ?Extension
    {
        $stmt = $this->driver->pdo()->prepare('SELECT * FROM extensions WHERE kind = :kind AND name = :name');
        $stmt->execute(['kind' => $kind, 'name' => $name]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    public function query(string $kind, ListQuery $query): Page
    {
        $pdo = $this->driver->pdo();
        $where = ['kind = :kind'];
        $bind = ['kind' => $kind];
        foreach ($query->filters as $key => $value) {
            if (str_ends_with($key, '_gte')) {
                $col = substr($key, 0, -4);
                $where[] = $this->driver->quoteIdentifier($col) . ' >= :f_' . $col . '_gte';
                $bind['f_' . $col . '_gte'] = $value;
            } elseif (str_ends_with($key, '_lte')) {
                $col = substr($key, 0, -4);
                $where[] = $this->driver->quoteIdentifier($col) . ' <= :f_' . $col . '_lte';
                $bind['f_' . $col . '_lte'] = $value;
            } else {
                $where[] = $this->driver->quoteIdentifier($key) . ' = :f_' . $key;
                $bind['f_' . $key] = $value;
            }
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM extensions WHERE {$whereSql}");
        $stmt->execute($bind);
        $total = (int) $stmt->fetch()['c'];

        $order = [];
        foreach ($query->sort as $field => $dir) {
            $order[] = $this->driver->quoteIdentifier($field) . ' ' . ($dir === 'desc' ? 'DESC' : 'ASC');
        }
        $sql = "SELECT * FROM extensions WHERE {$whereSql} ORDER BY " . implode(', ', $order)
            . ' LIMIT ' . $query->size . ' OFFSET ' . $query->offset();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $items = array_map($this->hydrate(...), $stmt->fetchAll());

        $offset = $query->offset();
        $next = $offset + count($items) < $total ? ListQuery::encodeCursor($offset + $query->size) : null;

        return new Page($items, (int) ($offset / $query->size) + 1, $query->size, $total, $next);
    }

    public function delete(string $kind, string $name): bool
    {
        $stmt = $this->driver->pdo()->prepare('DELETE FROM extensions WHERE kind = :kind AND name = :name');
        $stmt->execute(['kind' => $kind, 'name' => $name]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Extension
    {
        $class = $this->typeMap[(string) $row['kind']] ?? Extension::class;
        return $class::fromArray(
            [
                'name' => $row['name'],
                'uid' => $row['uid'],
                'kind' => $row['kind'],
                'apiVersion' => $row['api_version'],
                'creationTimestamp' => $row['creation_timestamp'],
                'updateTimestamp' => $row['update_timestamp'],
                'resourceVersion' => $row['resource_version'],
            ],
            json_decode((string) $row['spec'], true) ?: [],
            $row['status'] === null ? [] : (json_decode((string) $row['status'], true) ?: []),
        );
    }
}
