<?php

declare(strict_types=1);

namespace Kivara\Cache\Contracts;

interface CacheStore
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $callback, ?int $ttl = null): void;

    public function has(string $key): bool;

    public function forget(string $key): void;

    public function flush(): void;

    public function lock(string $key): CacheLock;
}
