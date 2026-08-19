<?php

declare(strict_types=1);

namespace Kivara\Cache\Services;

use Kivara\Cache\Exceptions\CacheException;

use function is_dir;
use function mkdir;
use function sprintf;

final readonly class Filesystem
{
    /**
     * @throws CacheException
     */
    public function ensureDirectoryExists(string $path): void
    {
        if (
            is_dir($path) === false
            && mkdir($path, 0755, recursive: true) === false
            && is_dir($path) === false
        ) {
            throw new CacheException(sprintf('Cache directory [%s] could not be created.', $path));
        }
    }
}
