<?php

declare(strict_types=1);

namespace Kurt\Modules\Core\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Kurt\Modules\Core\Contracts\ModuleCache;

/**
 * Config-driven {@see ModuleCache}: wraps one resolved cache store, prefixes
 * every key with the module prefix, caches null via a sentinel, and bypasses
 * the store entirely when disabled.
 */
final class ConfigModuleCache implements ModuleCache
{
    /** Stored in place of a null result so negative lookups are cached. */
    private const NULL_SENTINEL = '__module_cache_null__';

    public function __construct(
        private readonly Repository $store,
        private readonly bool $enabled,
        private readonly string $prefix,
        private readonly int $ttl,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function remember(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        if (! $this->enabled) {
            return $callback();
        }

        $cached = $this->store->get($this->key($key));

        if ($cached !== null) {
            return $cached === self::NULL_SENTINEL ? null : $cached;
        }

        $value = $callback();

        $this->store->put(
            $this->key($key),
            $value ?? self::NULL_SENTINEL,
            $ttl ?? $this->ttl,
        );

        return $value;
    }

    public function forget(string $key): void
    {
        $this->store->forget($this->key($key));
    }

    private function key(string $key): string
    {
        return "{$this->prefix}:{$key}";
    }
}
