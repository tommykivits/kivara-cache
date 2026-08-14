<?php

declare(strict_types=1);

namespace Kivara\Cache;

use Kivara\Cache\Contracts\CacheStore;
use Kivara\Cache\Exceptions\CacheException;

final readonly class Cache
{
    public function __construct(
        private CacheStore $store,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->store->get($key);
    }

    public function put(string $key, mixed $callback, ?int $ttl = null): void
    {
        $this->store->put($key, $callback, $ttl);
    }

    /**
     * @throws CacheException
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        if ($this->store->has($key)) {
            return $this->store->get($key);
        }

        $lock = $this->store->lock($key);
        $lock->acquire();

        try {
            if ($this->store->has($key) === true) {
                return $this->store->get($key);
            }

            $value = $callback();

            $this->store->put($key, $value, $ttl);

            return $value;
        } finally {
            $lock->release();
        }
    }

    public function has(string $key): bool
    {
        return $this->store->has($key);
    }

    public function forget(string $key): void
    {
        $this->store->forget($key);
    }

    public function flush(): void
    {
        $this->store->flush();
    }
}
