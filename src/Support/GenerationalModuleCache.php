<?php

declare(strict_types=1);

namespace Kurt\Modules\Core\Support;

use Closure;
use Illuminate\Support\Str;
use Kurt\Modules\Core\Contracts\ModuleCache;

/**
 * Generational (versioned) cache over a {@see ModuleCache}. Every entry is
 * stored under a scope whose whole keyspace is invalidated in O(1) by
 * {@see bump()}: bumping drops the scope's generation token, so the next read
 * seeds a fresh one and every previously stored key is orphaned (never read
 * again, expires by TTL). This avoids enumerating individual keys — the pattern
 * behind safe ACL caching, where a permission or move change must invalidate an
 * unbounded, hard-to-enumerate set of derived entries at once.
 *
 * Callers still put invalidation-relevant inputs INTO the key (e.g. a role
 * fingerprint) so changes that have no bump signal self-invalidate.
 */
final class GenerationalModuleCache
{
    public function __construct(private readonly ModuleCache $cache) {}

    public function remember(string $scope, string $key, Closure $callback, ?int $ttl = null): mixed
    {
        $generation = $this->generation($scope);

        return $this->cache->remember("{$scope}:g{$generation}:{$key}", $callback, $ttl);
    }

    /** Current generation token for a scope, seeded fresh (and stored) if absent. */
    public function generation(string $scope): string
    {
        return (string) $this->cache->remember("gen:{$scope}", fn () => Str::random(12));
    }

    /**
     * Invalidate the entire scope. Dropping the generation token means the next
     * {@see generation()} seeds a new one, so every key written under the old
     * token is orphaned at once. Over-invalidation on token loss is safe (cold
     * cache); it never serves a stale entry under the new token.
     */
    public function bump(string $scope): void
    {
        $this->cache->forget("gen:{$scope}");
    }
}
