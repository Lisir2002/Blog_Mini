<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

use Psr\Container\ContainerInterface;

/**
 * 轻量 DI 容器:显式注册,懒解析,不做自动装配(见 02 §1)。
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable(self): object> */
    private array $factories = [];

    /** @var array<string, bool> */
    private array $singletonFlags = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function singleton(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        $this->singletonFlags[$id] = true;
        unset($this->instances[$id]);
    }

    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->singletonFlags[$id], $this->instances[$id]);
    }

    public function instance(string $id, object $obj): void
    {
        $this->instances[$id] = $obj;
        unset($this->factories[$id], $this->singletonFlags[$id]);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new NotFoundException(sprintf('Service "%s" is not registered.', $id));
        }
        $object = ($this->factories[$id])($this);
        if ($this->singletonFlags[$id] ?? false) {
            $this->instances[$id] = $object;
        }
        return $object;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }
}
