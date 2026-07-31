<?php

declare(strict_types=1);

namespace Kurt\Modules\Core\Modules;

use Kurt\Modules\Core\Contracts\ModuleRegistry;
use Kurt\Modules\Core\Providers\PackageServiceProvider;

/**
 * In-memory module registry. Populated once per request during package boot
 * (see {@see PackageServiceProvider}); a later
 * same-slug registration overwrites the earlier one.
 */
final class Registry implements ModuleRegistry
{
    /** @var array<string, ModuleManifest> */
    private array $modules = [];

    public function register(ModuleManifest $manifest): void
    {
        $this->modules[$manifest->slug()] = $manifest;
    }

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $slug): ?ModuleManifest
    {
        return $this->modules[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return isset($this->modules[$slug]);
    }
}
