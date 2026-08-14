# Kivara Cache

A simple and robust caching component for the Kivara Framework, supporting multiple stores and atomic locks to prevent cache stampedes.

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
// Store an item for a specific amount of time (in seconds)
$cache->put('key', 'value', 3600);

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

Stores cache data in the local file system.

```php
use Kivara\Cache\Stores\File;

$store = new File('/path/to/cache/directory', '.php');
```

### OPCache Store

Stores cache data using PHP's OPCache. This is generally faster as it leverages memory-based storage for compiled PHP scripts.

```php
use Kivara\Cache\Stores\OPCache;

$store = new OPCache('/path/to/cache/directory', '.php');
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

Stores cache data in shared memory using APCu. Requires the `ext-apcu` extension. Note that locking for APCu still uses file-based locks.

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

class MyCustomStore implements CacheStore
{
    public function get(string $key): mixed { /* ... */ }
    public function put(string $key, mixed $value, ?int $ttl = null): void { /* ... */ }
    public function has(string $key): bool { /* ... */ }
    public function forget(string $key): void { /* ... */ }
    public function flush(): void { /* ... */ }
    public function lock(string $key): CacheLock { /* ... */ }
}
```
