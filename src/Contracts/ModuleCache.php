<?php

declare(strict_types=1);

namespace Kurt\Modules\Core\Contracts;

use Closure;
use Kurt\Modules\Core\Support\ModuleCacheFactory;

/**
 * A per-module cache facade: a small, config-driven wrapper over a Laravel
 * cache store, scoped by a module prefix. Modules resolve one via
 * {@see ModuleCacheFactory} and cache their
 * expensive read paths through it, busting specific keys from their existing
 * observers/events.
 */
interface ModuleCache
{
    public function enabled(): bool;

    /**
     * Return the cached value for $key, or compute it via $callback, store it
     * (under a module-prefixed key), and return it. A null result is cached too
     * (negative-lookup sentinel). When caching is disabled the callback runs
     * every time and nothing is stored.
     */
    public function remember(string $key, Closure $callback, ?int $ttl = null): mixed;

    public function forget(string $key): void;
}
