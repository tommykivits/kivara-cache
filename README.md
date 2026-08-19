# Kivara Cache

A simple, robust, and PSR-6 compliant caching component for the Kivara Framework, supporting multiple stores and atomic locks to prevent cache stampedes.

## Features

- Multiple storage backends (File, OPCache, Redis, APCu).
- Atomic locks using `flock` or Redis `SET NX`.
- `remember` method with automatic locking.
- Simple and consistent API.

## Installation

This component is part of the Kivara Framework. Ensure you have the necessary dependencies installed via Composer.

```bash
composer require kivara/cache
```

## Basic Usage

### Initializing the Cache

You can initialize the cache by providing a store implementation.

```php
use Kivara\Cache\Cache;
use Kivara\Cache\Stores\File;

$store = new File(__DIR__ . '/cache', '.cache');
$cache = new Cache($store);
```

### Storing Items

```php
use Kivara\Cache\Enums\Ttl;

// Store an item for a specific amount of time (in seconds or using Ttl enum)
$cache->put('key', 'value', 3600);
$cache->put('key', 'value', Ttl::HOUR);

// Store an item indefinitely
$cache->put('key', 'value');
```

Note: Some stores (like Redis and APCu) do not support storing `Closure` objects.

### Retrieving Items

```php
$value = $cache->get('key');

if ($value === null) {
    // Item not found or expired
}
```

### Checking for Existence

```php
if ($cache->has('key')) {
    // ...
}
```

### Removing Items

```php
// Remove a specific item
$cache->forget('key');

// Remove all items from the cache
$cache->flush();
```

## Advanced Usage

### The `remember` Method

The `remember` method is a powerful way to retrieve an item from the cache, but also store it if it doesn't exist. It uses atomic locks to ensure that only one process generates the value if the cache is empty, preventing "cache stampedes".

```php
$value = $cache->remember('users.all', function () {
    return DB::table('users')->get();
}, 3600);
```

## Available Stores

### File Store

Stores cache data in the local file system. Cache files and lock files are automatically partitioned into two-character hex subdirectories to ensure scalability and high filesystem performance.

Lock files are stored inside the `locks/` folder of the specified cache directory (e.g., `<dir>/locks/c3/c3ab...lock`).

```php
use Kivara\Cache\Stores\File;

$store = new File('/path/to/cache/directory', '.cache');
```

### OPCache Store

Stores cache data using PHP's OPCache. This leverages memory-based storage for compiled PHP scripts. Lock files are placed within the `locks/` folder of the cache directory.

```php
use Kivara\Cache\Stores\OPCache;

$store = new OPCache('/path/to/cache/directory', '.cache.php');
```

### Redis Store

Stores cache data in a Redis server. Requires the `ext-redis` extension.

```php
use Kivara\Cache\Stores\Redis;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$store = new Redis($redis, 'my_app_cache');
```

### APCu Store

Stores cache data in shared memory using APCu. Requires the `ext-apcu` extension. Lock files are partitioned into subdirectories within the specified lock directory.

```php
use Kivara\Cache\Stores\APCu;

$store = new APCu('my_app_cache', '/path/to/lock/directory');
```

## Custom Stores

You can create your own cache store by implementing the `Kivara\Cache\Contracts\CacheStore` interface.

```php
namespace App\Cache;

use Kivara\Cache\Contracts\CacheLock;
use Kivara\Cache\Contracts\CacheStore;
use Kivara\Cache\Enums\Ttl;

class MyCustomStore implements CacheStore
{
    public function get(string $key): mixed { /* ... */ }
    public function put(string $key, mixed $callback, Ttl|int|null $ttl = null): void { /* ... */ }
    public function has(string $key): bool { /* ... */ }
    public function forget(string $key): void { /* ... */ }
    public function flush(): void { /* ... */ }
    public function lock(string $key): CacheLock { /* ... */ }
}
```
