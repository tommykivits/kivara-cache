<?php

declare(strict_types=1);

namespace Kivara\Cache\Traits;

use Kivara\Cache\Exceptions\CacheException;
use Kivara\Cache\Services\FileLock;

use function dirname;
use function fopen;
use function is_dir;
use function mkdir;
use function sprintf;

trait HasFileLocks
{
    /**
     * @throws CacheException
     */
    public function lock(string $key): FileLock
    {
        $path = $this->lockPath($key);
        $directory = dirname($path);
        if (is_dir($directory) === false && @mkdir($directory, 0755, true) === false && is_dir($directory) === false) {
            throw new CacheException(sprintf('Unable to create lock directory "%s".', $directory));
        }

        $handle = fopen($path, 'cb');
        if ($handle === false) {
            throw new CacheException(sprintf('Unable to open lock file "%s".', $path));
        }

        return new FileLock($handle);
    }

    abstract protected function lockPath(string $key): string;
}
