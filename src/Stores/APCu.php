<?php

declare(strict_types=1);

namespace Kivara\Cache\Stores;

use APCUIterator;
use Closure;
use Kivara\Cache\Contracts\CacheStore;
use Kivara\Cache\Exceptions\CacheException;
use Kivara\Cache\Services\FileLock;
use Kivara\Cache\Traits\HasFileLocks;

use function apcu_delete;
use function apcu_exists;
use function apcu_fetch;
use function apcu_store;
use function extension_loaded;
use function file_exists;
use function fopen;
use function hash;
use function ini_get;
use function preg_quote;
use function rtrim;
use function sprintf;
use function unlink;

/**
 * In-memory cache backed by APCu. Faster than File/OPCache since there is
 * no disk I/O or (de)serialization step involved, at the cost of being
 * scoped to a single server/process pool — the same limitation as OPCache.
 *
 * Locking still goes through flock() on disk (via HasFileLocks), since
 * APCu itself has no built-in atomic "acquire lock" primitive suitable for
 * remember()'s stampede protection.
 */
final readonly class APCu implements CacheStore
{
    use HasFileLocks;

    private string $prefix;

    /**
     * @throws CacheException
     */
    public function __construct(
        string $namespace,
        private string $lockDirectory,
    ) {
        if (extension_loaded('apcu') === false || ini_get('apc.enabled') === '0') {
            throw new CacheException('The APCu extension is not installed or enabled.');
        }

        $this->prefix = rtrim($namespace, ':') . ':';
    }

    public function get(string $key): mixed
    {
        $value = apcu_fetch($this->prefixed($key), $success);

        return $success ? $value : null;
    }

    /**
     * @throws CacheException
     */
    public function put(string $key, mixed $callback, ?int $ttl = null): void
    {
        if ($callback instanceof Closure) {
            throw new CacheException('Closures cannot be stored in the APCu cache.');
        }

        apcu_store($this->prefixed($key), $callback, $ttl ?? 0);
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->prefixed($key));
    }

    public function forget(string $key): void
    {
        apcu_delete($this->prefixed($key));

        $this->releaseLockFile($this->lockPath($key));
    }

    public function flush(): void
    {
        foreach (new APCUIterator('/^' . preg_quote($this->prefix, '/') . '/') as $entry) {
            apcu_delete($entry['key']);
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
        return sprintf('%s/%s.lock', rtrim($this->lockDirectory, '/'), hash('sha256', $this->prefixed($key)));
    }

    private function prefixed(string $key): string
    {
        return $this->prefix . $key;
    }
}
