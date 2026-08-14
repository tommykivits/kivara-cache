<?php

declare(strict_types=1);

namespace Kivara\Cache\Contracts;

interface CacheLock
{
    public function acquire(): void;

    public function tryAcquire(): bool;

    public function release(): void;
}
