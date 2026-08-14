<?php

declare(strict_types=1);

namespace Kivara\Cache\Stores;

use Closure;
use JsonException;
use Kivara\Cache\Contracts\CacheStore;
use Kivara\Cache\Exceptions\CacheException;
use Kivara\Cache\Services\FileLock;
use Kivara\Cache\Traits\HasFileLocks;

use function array_key_exists;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function getmypid;
use function glob;
use function hash;
use function is_array;
use function is_dir;
use function is_int;
use function json_decode;
use function json_encode;
use function mkdir;
use function rename;
use function rtrim;
use function sprintf;
use function time;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * This class provides a file-based caching mechanism implementing the CacheStore interface.
 * It uses a directory to store cache files and supports operations such as retrieve, store,
 * check existence, remove, and flush cache entries. Each cache entry is stored in a separate
 * file, with optional expiration times.
 */
final readonly class File implements CacheStore
{
    use HasFileLocks;

    /**
     * @throws CacheException
     */
    public function __construct(
        private string $directory,
        private string $extension = '.cache',
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
        $record = $this->readRecord($key);
        if ($record === null) {
            return null;
        }

        if ($this->isExpired($record) === true) {
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
            throw new CacheException('Closures cannot be stored in the file cache.');
        }

        $expiresAt = $ttl !== null ? time() + $ttl : null;

        $this->writeRecord($key, ['value' => $callback, 'expires_at' => $expiresAt]);
    }

    /**
     * @throws CacheException
     */
    public function has(string $key): bool
    {
        $record = $this->readRecord($key);
        if ($record === null) {
            return false;
        }

        if ($this->isExpired($record) === true) {
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
        if (file_exists($path) === true && unlink($path) === false) {
            throw new CacheException(sprintf('Failed to delete cache file [%s].', $path));
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
                if (file_exists($file) === true && unlink($file) === false) {
                    throw new CacheException(sprintf('Failed to delete cache file [%s].', $file));
                }
            }
        }

        $lockPattern = sprintf('%s/locks/*.lock', $this->directoryPath());

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
        return sprintf('%s/%s.lock', $this->directoryPath() . '/locks', hash('sha256', $key));
    }

    /**
     * @return array{value: mixed, expires_at: int|null}|null
     *
     * @throws CacheException
     */
    private function readRecord(string $key): ?array
    {
        $path = $this->dataPath($key);
        if (file_exists($path) === false) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new CacheException(sprintf('Failed to read cache file [%s].', $path));
        }

        try {
            $record = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CacheException(
                sprintf('Cache file [%s] contains invalid JSON: %s', $path, $exception->getMessage()),
            );
        }

        if (
            is_array($record) === false
            || array_key_exists('value', $record) === false
            || array_key_exists('expires_at', $record) === false
            || ($record['expires_at'] !== null && is_int($record['expires_at']) === false)
        ) {
            throw new CacheException(sprintf('Cache file [%s] contains invalid data.', $path));
        }

        return [
            'value' => $record['value'],
            'expires_at' => $record['expires_at'],
        ];
    }

    /**
     * @param array{value: mixed, expires_at: int|null} $record
     *
     * @throws CacheException
     */
    private function writeRecord(string $key, array $record): void
    {
        $path = $this->dataPath($key);
        $temporaryPath = sprintf('%s.tmp.%d', $path, getmypid());

        try {
            $contents = json_encode($record, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CacheException(
                sprintf('Cache value for key [%s] cannot be encoded as JSON: %s', $key, $exception->getMessage()),
            );
        }

        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new CacheException(sprintf('Failed to write cache file [%s].', $path));
        }

        if (rename($temporaryPath, $path) === false) {
            if (file_exists($temporaryPath) === true) {
                unlink($temporaryPath);
            }

            throw new CacheException(sprintf('Failed to move cache file [%s] into place.', $path));
        }
    }

    /**
     * @param array{value: mixed, expires_at: int|null} $record
     */
    private function isExpired(array $record): bool
    {
        $expiresAt = $record['expires_at'];

        return $expiresAt !== null && time() >= $expiresAt;
    }

    private function dataPath(string $key): string
    {
        return $this->basePath($key) . $this->extension;
    }

    private function basePath(string $key): string
    {
        return $this->directoryPath() . '/' . hash('sha256', $key);
    }

    private function directoryPath(): string
    {
        return rtrim($this->directory, '/');
    }
}
