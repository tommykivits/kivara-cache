<?php

declare(strict_types=1);

namespace Kivara\Cache\Stores;

use Closure;
use Kivara\Cache\Contracts\CacheStore;
use Kivara\Cache\Exceptions\CacheException;
use Kivara\Cache\Services\FileLock;
use Kivara\Cache\Traits\HasFileLocks;

use function array_key_exists;
use function file_exists;
use function file_put_contents;
use function fopen;
use function function_exists;
use function glob;
use function hash;
use function is_array;
use function is_dir;
use function is_int;
use function mkdir;
use function opcache_invalidate;
use function rename;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function tempnam;
use function time;
use function unlink;
use function var_export;

use const LOCK_EX;

/**
 * A caching implementation utilizing PHP's OPcache for optimized performance.
 * Provides basic CRUD operations for managing cache entries with support for
 * expiration times and file locking mechanisms to ensure safe concurrent access.
 */
final readonly class OPCache implements CacheStore
{
    use HasFileLocks;

    /**
     * @throws CacheException
     */
    public function __construct(
        private string $directory,
        private string $extension = '.cache.php',
    ) {
        if (
            is_dir($this->directory) === false
            && mkdir($this->directory, 0755, recursive: true) === false
            && is_dir($this->directory) === false
        ) {
            throw new CacheException(sprintf('Cache directory [%s] could not be created.', $this->directory));
        }
    }

    /**
     * @throws CacheException
     */
    public function get(string $key): mixed
    {
        $record = $this->read($key);
        if ($record === null) {
            return null;
        }

        if ($this->isExpired($record)) {
            $this->forget($key);

            return null;
        }

        return $record['value'];
    }

    /**
     * @throws CacheException
     */
    public function put(string $key, mixed $callback, ?int $ttl = null): void
    {
        if ($callback instanceof Closure) {
            throw new CacheException('Closures cannot be stored in the OPcache store.');
        }

        $expiresAt = $ttl !== null
            ? time() + $ttl
            : null;

        $this->writeFile($this->dataPath($key), ['expires_at' => $expiresAt, 'value' => $callback]);
    }

    /**
     * @throws CacheException
     */
    public function has(string $key): bool
    {
        $record = $this->read($key);
        if ($record === null) {
            return false;
        }

        if ($this->isExpired($record)) {
            $this->forget($key);

            return false;
        }

        return true;
    }

    /**
     * @throws CacheException
     */
    public function forget(string $key): void
    {
        $path = $this->dataPath($key);
        if (file_exists($path) === true) {
            $this->invalidateOpcache($path);

            if (unlink($path) === false) {
                throw new CacheException(sprintf('Failed to delete cache file [%s].', $path));
            }
        }

        $this->releaseLockFile($this->lockPath($key));
    }

    /**
     * @throws CacheException
     */
    public function flush(): void
    {
        $pattern = sprintf('%s/*%s', $this->directoryPath(), $this->extension);

        $files = glob($pattern);
        if ($files !== false) {
            foreach ($files as $file) {
                if (str_ends_with($file, $this->extension) === false) {
                    continue;
                }

                $this->invalidateOpcache($file);

                if (unlink($file) === false) {
                    throw new CacheException(sprintf('Failed to delete cache file [%s].', $file));
                }
            }
        }

        $lockPattern = sprintf('%s/*.lock', $this->locksDirectoryPath());

        $lockFiles = glob($lockPattern);
        if ($lockFiles !== false) {
            foreach ($lockFiles as $lockFile) {
                $this->releaseLockFile($lockFile);
            }
        }
    }

    private function releaseLockFile(string $path): void
    {
        if (file_exists($path) === false) {
            return;
        }

        $handle = @fopen($path, 'cb');
        if ($handle === false) {
            return;
        }

        $lock = new FileLock($handle);

        if ($lock->tryAcquire() === true) {
            @unlink($path);
            $lock->release();
        }
    }

    protected function lockPath(string $key): string
    {
        return sprintf('%s/%s.lock', $this->locksDirectoryPath(), $this->keyHash($key));
    }

    /**
     * @return array{
     *     expires_at: int|null,
     *     value: mixed
     * }|null
     *
     * @throws CacheException
     */
    private function read(string $key): ?array
    {
        $path = $this->dataPath($key);
        if (file_exists($path) === false) {
            return null;
        }

        $record = require $path;

        if (
            is_array($record) === false
            || array_key_exists('expires_at', $record) === false
            || array_key_exists('value', $record) === false
        ) {
            throw new CacheException(sprintf('Invalid cache record [%s].', $path));
        }

        $expiresAt = $record['expires_at'];

        if ($expiresAt !== null && is_int($expiresAt) === false) {
            throw new CacheException(sprintf('Invalid cache expiration value [%s].', $path));
        }

        return [
            'expires_at' => $expiresAt,
            'value' => $record['value'],
        ];
    }

    /**
     * @param array{
     *     expires_at: int|null,
     *     value: mixed
     * } $record
     */
    private function isExpired(array $record): bool
    {
        $expiresAt = $record['expires_at'];

        return $expiresAt !== null && time() >= $expiresAt;
    }

    /**
     * @param array{
     *     expires_at: int|null,
     *     value: mixed
     * } $record
     *
     * @throws CacheException
     */
    private function writeFile(string $path, array $record): void
    {
        $content = sprintf("<?php\n\ndeclare(strict_types=1);\n\nreturn %s;\n", var_export($record, return: true));

        $temporaryPath = tempnam($this->directory, '.cache-');
        if ($temporaryPath === false) {
            throw new CacheException(sprintf('Unable to create temporary cache file for [%s].', $path));
        }

        try {
            if (file_put_contents($temporaryPath, $content, LOCK_EX) === false) {
                throw new CacheException(sprintf('Failed to write cache file [%s].', $path));
            }

            if (rename($temporaryPath, $path) === false) {
                throw new CacheException(sprintf('Failed to replace cache file [%s].', $path));
            }
        } finally {
            if (file_exists($temporaryPath) === true) {
                unlink($temporaryPath);
            }
        }

        $this->invalidateOpcache($path);
    }

    private function dataPath(string $key): string
    {
        return sprintf('%s/%s%s', $this->directoryPath(), $this->keyHash($key), $this->extension);
    }

    private function directoryPath(): string
    {
        return rtrim($this->directory, '/');
    }

    private function locksDirectoryPath(): string
    {
        return $this->directoryPath() . '/locks';
    }

    private function keyHash(string $key): string
    {
        return hash('sha256', $key);
    }

    private function invalidateOpcache(string $path): void
    {
        if (function_exists('opcache_invalidate') === false) {
            return;
        }

        opcache_invalidate($path, force: true);
    }
}
