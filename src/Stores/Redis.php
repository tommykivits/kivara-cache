<?php

declare(strict_types=1);

namespace Kivara\Cache\Stores;

use Closure;
use JsonException;
use Kivara\Cache\Contracts\CacheLock;
use Kivara\Cache\Contracts\CacheStore;
use Kivara\Cache\Enums\Ttl;
use Kivara\Cache\Exceptions\CacheException;
use Kivara\Cache\Services\RedisLock;
use Override;
use Random\RandomException;
use Redis as RedisConnection;
use RedisException;

use function is_int;
use function json_decode;
use function json_encode;
use function rtrim;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * A cache store implementation that uses Redis as the underlying storage engine.
 * Provides methods to interact with cached data, such as retrieving, storing,
 * deleting, and managing cache keys, as well as locking mechanisms.
 */
final readonly class Redis implements CacheStore
{
    private string $prefix;

    public function __construct(
        private RedisConnection $connection,
        string $namespace = 'cache',
        private int $lockTtl = 10,
        private int $lockWaitTimeoutMs = 5000,
    ) {
        $this->prefix = rtrim($namespace, ':') . ':';
    }

    #[Override]
    public function get(string $key): mixed
    {
        try {
            $raw = $this->connection->get($this->prefix . $key);
            if ($raw === false) {
                return null;
            }
        } catch (RedisException $exception) {
            throw new CacheException('Redis unavailable: ' . $exception->getMessage(), previous: $exception);
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CacheException(
                sprintf('Cache value for key [%s] cannot be decoded as JSON: %s', $key, $exception->getMessage()),
            );
        }
    }

    /**
     * @throws CacheException
     */
    #[Override]
    public function put(string $key, mixed $callback, Ttl|int|null $ttl = null): void
    {
        if ($callback instanceof Closure) {
            throw new CacheException('Closures cannot be stored in the Redis cache.');
        }

        try {
            $payload = json_encode($callback, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CacheException(
                sprintf('Cache value for key [%s] cannot be encoded as JSON: %s', $key, $exception->getMessage()),
            );
        }

        $ttlSeconds = $ttl instanceof Ttl ? $ttl->value : $ttl;

        try {
            if (is_int($ttlSeconds) && $ttlSeconds > 0) {
                $this->connection->set($this->prefix . $key, $payload, ['EX' => $ttlSeconds]);
            } else {
                $this->connection->set($this->prefix . $key, $payload);
            }
        } catch (RedisException $exception) {
            throw new CacheException('Redis unavailable: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @throws CacheException
     */
    #[Override]
    public function has(string $key): bool
    {
        try {
            return $this->connection->exists($this->prefix . $key) > 0;
        } catch (RedisException $exception) {
            throw new CacheException('Redis unavailable: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @throws CacheException
     */
    #[Override]
    public function forget(string $key): void
    {
        try {
            $this->connection->del($this->prefix . $key);
        } catch (RedisException $exception) {
            throw new CacheException('Redis unavailable: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @throws CacheException
     */
    #[Override]
    public function flush(): void
    {
        try {
            $iterator = null;

            do {
                $keys = $this->connection->scan($iterator, $this->prefix . '*', 100);
                if ($keys !== false && $keys !== []) {
                    $this->connection->del($keys);
                }
            } while ($iterator > 0);
        } catch (RedisException $exception) {
            throw new CacheException('Redis unavailable: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @throws RandomException
     */
    public function lock(string $key): CacheLock
    {
        return new RedisLock(
            $this->connection,
            $this->prefix . 'lock:' . $key,
            $this->lockTtl,
            $this->lockWaitTimeoutMs,
        );
    }
}
