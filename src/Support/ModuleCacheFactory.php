<?php

declare(strict_types=1);

namespace Kurt\Modules\Core\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Kurt\Modules\Core\Contracts\ModuleCache;

/**
 * Builds a {@see ModuleCache} for a module from its `{slug}.cache` config block
 * ({enabled, store, prefix, ttl}), applying safe defaults when the block (or a
 * key) is absent. The store is selected by NAME so `config:cache` stays safe.
 */
final class ModuleCacheFactory
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
    ) {}

    public function for(string $module): ModuleCache
    {
        $cfg = $this->config->get("{$module}.cache", []);
        $cfg = is_array($cfg) ? $cfg : [];

        $store = $cfg['store'] ?? null;

        return new ConfigModuleCache(
            $this->cache->store(is_string($store) ? $store : null),
            (bool) ($cfg['enabled'] ?? true),
            (string) ($cfg['prefix'] ?? $module),
            (int) ($cfg['ttl'] ?? 3600),
        );
    }

    /** A generational cache over the module's {@see ModuleCache} (see ACL caching). */
    public function generationalFor(string $module): GenerationalModuleCache
    {
        return new GenerationalModuleCache($this->for($module));
    }
}
