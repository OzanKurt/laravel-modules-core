<?php

declare(strict_types=1);

namespace Kurt\Modules\Core\Modules;

use Kurt\Modules\Core\Contracts\ModuleRegistry;

/**
 * A module's self-declaration: identity, dependencies, and the feature/setting
 * keys it exposes with their default values. Collected into the
 * {@see ModuleRegistry} at boot. Pure value object -
 * holds no runtime state and touches no DB.
 */
final class ModuleManifest
{
    private string $name;

    private ?string $version = null;

    private ?string $description = null;

    private bool $enabledByDefault = true;

    /** @var list<string> */
    private array $dependencies = [];

    /** @var array<string, bool> */
    private array $features = [];

    /** @var array<string, array{default: mixed, type: string}> */
    private array $settings = [];

    private function __construct(private readonly string $slug)
    {
        $this->name = $slug;
    }

    public static function make(string $slug): self
    {
        return new self($slug);
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function version(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function enabledByDefault(bool $enabled = true): self
    {
        $this->enabledByDefault = $enabled;

        return $this;
    }

    public function dependsOn(string ...$slugs): self
    {
        foreach ($slugs as $slug) {
            $this->dependencies[] = $slug;
        }

        return $this;
    }

    public function feature(string $key, bool $default = false): self
    {
        $this->features[$key] = $default;

        return $this;
    }

    public function setting(string $key, mixed $default = null, string $type = 'string'): self
    {
        $this->settings[$key] = ['default' => $default, 'type' => $type];

        return $this;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isEnabledByDefault(): bool
    {
        return $this->enabledByDefault;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /** @return array<string, bool> */
    public function features(): array
    {
        return $this->features;
    }

    public function hasFeature(string $key): bool
    {
        return array_key_exists($key, $this->features);
    }

    public function featureDefault(string $key): bool
    {
        return $this->features[$key] ?? false;
    }

    /** @return array<string, array{default: mixed, type: string}> */
    public function settings(): array
    {
        return $this->settings;
    }

    public function hasSetting(string $key): bool
    {
        return array_key_exists($key, $this->settings);
    }

    public function settingDefault(string $key): mixed
    {
        return $this->settings[$key]['default'] ?? null;
    }

    public function settingType(string $key): ?string
    {
        return $this->settings[$key]['type'] ?? null;
    }
}
