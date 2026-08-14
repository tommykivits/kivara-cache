<?php

declare(strict_types=1);

namespace Kivara\Cache\Services;

use Kivara\Cache\Contracts\CacheLock;
use Kivara\Cache\Exceptions\CacheException;

use function fclose;
use function flock;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

final readonly class FileLock implements CacheLock
{
    public function __construct(
        private mixed $handle,
    ) {
    }

    /**
     * @throws CacheException
     */
    public function acquire(): void
    {
        if (flock($this->handle, LOCK_EX) === false) {
            throw new CacheException('Unable to acquire cache lock.');
        }
    }

    public function tryAcquire(): bool
    {
        return flock($this->handle, LOCK_EX | LOCK_NB);
    }

    public function release(): void
    {
        flock($this->handle, LOCK_UN);
    }

    public function __destruct()
    {
        fclose($this->handle);
    }
}
