<?php

declare(strict_types=1);

namespace Kivara\Cache\Stores;

use Closure;
use Kivara\Cache\Contracts\CacheStore;
use Kivara\Cache\Enums\Ttl;
use Kivara\Cache\Exceptions\CacheException;
use Kivara\Cache\Services\FileLock;
use Kivara\Cache\Services\Filesystem;
use Kivara\Cache\Traits\HasFileLocks;
use Override;

use function array_key_exists;
use function dirname;
use function file_exists;
use function file_put_contents;
use function fopen;
use function function_exists;
use function glob;
use function hash;
use function is_array;
use function is_dir;
use function is_int;
use function opcache_invalidate;
use function rename;
use function rmdir;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function substr;
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
        private Filesystem $filesystem = new Filesystem(),
    ) {
        foreach([$this->directory, $this->locksDirectoryPath()] as $dir) {
            $this->filesystem->ensureDirectoryExists($dir);
        }
    }

    #[Override]
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
    #[Override]
    public function put(string $key, mixed $callback, Ttl|int|null $ttl = null): void
    {
        if ($callback instanceof Closure) {
            throw new CacheException('Closures cannot be stored in the OPcache store.');
        }

        $ttlSeconds = $ttl instanceof Ttl ? $ttl->value : $ttl;

        $expiresAt = $ttlSeconds !== null
            ? time() + $ttlSeconds
            : null;

        $this->writeFile($this->dataPath($key), ['expires_at' => $expiresAt, 'value' => $callback]);
    }

    /**
     * @throws CacheException
     */
    #[Override]
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
    #[Override]
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
    #[Override]
    public function flush(): void
    {
        $pattern = sprintf('%s/*/*%s', $this->directoryPath(), $this->extension);

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

        $directories = glob(sprintf('%s/*', $this->directoryPath()));
        if ($directories !== false) {
            foreach ($directories as $dir) {
                if (is_dir($dir) === true && $dir !== $this->locksDirectoryPath()) {
                    @rmdir($dir);
                }
            }
        }

        $lockPattern = sprintf('%s/*/*.lock', $this->locksDirectoryPath());

        $lockFiles = glob($lockPattern);
        if ($lockFiles !== false) {
            foreach ($lockFiles as $lockFile) {
                $this->releaseLockFile($lockFile);
            }
        }

        $lockDirectories = glob(sprintf('%s/*', $this->locksDirectoryPath()));
        if ($lockDirectories !== false) {
            foreach ($lockDirectories as $dir) {
                if (is_dir($dir) === true) {
                    @rmdir($dir);
                }
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
        $hash = $this->keyHash($key);

        return sprintf('%s/%s/%s.lock', $this->locksDirectoryPath(), substr($hash, 0, 2), $hash);
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
        $directory = dirname($path);
        $this->filesystem->ensureDirectoryExists($directory);

        $content = sprintf("<?php\n\ndeclare(strict_types=1);\n\nreturn %s;\n", var_export($record, return: true));

        $temporaryPath = tempnam($directory, '.cache-');
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
        $hash = $this->keyHash($key);

        return sprintf('%s/%s/%s%s', $this->directoryPath(), substr($hash, 0, 2), $hash, $this->extension);
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
