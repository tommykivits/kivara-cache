<?php

declare(strict_types=1);

namespace Kivara\Cache\Services;

use Kivara\Cache\Contracts\CacheLock;
use Kivara\Cache\Exceptions\CacheException;
use Random\RandomException;
use Redis;

use function bin2hex;
use function random_bytes;
use function sprintf;
use function usleep;

final class RedisLock implements CacheLock
{
    private readonly string $token;

    private bool $acquired = false;

    /**
     * @throws RandomException
     */
    public function __construct(
        private readonly Redis $connection,
        private readonly string $key,
        private readonly int $ttlSeconds = 10,
        private readonly int $waitTimeoutMs = 5000,
    ) {
        $this->token = bin2hex(random_bytes(16));
    }

    /**
     * @throws CacheException
     */
    public function acquire(): void
    {
        $waitedUs = 0;
        $intervalUs = 50_000; // 50ms between attempts

        while ($this->tryAcquire() === false) {
            $waitedUs += $intervalUs;

            if ($waitedUs >= $this->waitTimeoutMs * 1000) {
                throw new CacheException(sprintf('Timed out waiting for lock [%s].', $this->key));
            }

            usleep($intervalUs);
        }
    }

    public function tryAcquire(): bool
    {
        $result = $this->connection->set($this->key, $this->token, ['NX', 'EX' => $this->ttlSeconds]);

        $this->acquired = $result !== false;

        return $this->acquired;
    }

    public function release(): void
    {
        if ($this->acquired === false) {
            return;
        }

        static $script = <<<'LUA'
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            end
            return 0
            LUA;

        $this->connection->eval($script, [$this->key, $this->token], 1);

        $this->acquired = false;
    }
}
