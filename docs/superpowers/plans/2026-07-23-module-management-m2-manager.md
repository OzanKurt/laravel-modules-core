# Module Management M2 - Manager Package (DB + resolution) - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** New package `ozankurt/laravel-modules-manager` that persists per-scope module state/feature/setting overrides in the DB and resolves the *effective* value through `scope -> global -> manifest default -> hard default`, exposed as a headless `ModuleManager` service + `Modules` facade. No REST API (that is M3).

**Architecture:** The manager consumes core's `ModuleRegistry` (manifest = source of truth for what exists and its defaults). A single `module_states` table holds overrides keyed by `(scope_type, scope_id, module, kind, key)`; global rows have null scope columns. `ModuleManager` reads registry + DB (through a cache) to answer `enabled/feature/setting`, and writes overrides (validated against the registry so undeclared keys cannot be set). Scope comes from a consumer-supplied `ScopeResolver` (defaulting to global), mirroring core's `UserResolver` seam.

**Tech Stack:** PHP 8.4 runtime (composer floor `^8.3`), Laravel 12 (`illuminate/*` ^12), `ozankurt/laravel-modules-core` ^1.1 (ships `ModuleManifest`/`ModuleRegistry`), `spatie/laravel-package-tools`, Pest 3 + Orchestra Testbench.

## Global Constraints

- Package: `ozankurt/laravel-modules-manager`, namespace `Kurt\Modules\Manager\`, repo `OzanKurt/laravel-modules-manager`, module slug `modules-manager`.
- composer floor `php ^8.3`; run tests on the Laragon 8.4 binary. Resolve core via a VCS `repositories` entry to `https://github.com/OzanKurt/laravel-modules-core`, constraint `^1.1`.
- `declare(strict_types=1);` at the top of every PHP file.
- Commits follow EpicAlgorithms git guidelines: **no AI attribution**.
- M2 is headless only: no routes/controllers, no Filament.

## Toolchain

`PHP84` = `C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe`; composer.phar = `C:/laragon/bin/composer/composer.phar`.
- Install: `"$PHP84" C:/laragon/bin/composer/composer.phar update --prefer-stable --no-interaction`
- Tests: `"$PHP84" vendor/bin/pest`  ·  Stan: `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G`

Create/clone the package at `D:\Code\Projects\laravel-modules-manager`.

## File Structure

- `composer.json`, `config/modules-manager.php`
- `src/Providers/ModulesManagerServiceProvider.php` - binds `ScopeResolver`, `ModuleManager`, facade accessor; loads migration.
- `src/Contracts/ScopeResolver.php` - `current(): ?Scope`.
- `src/Support/Scope.php` - immutable `(type, id)` value.
- `src/Support/NullScopeResolver.php` - default resolver, always global (`null`).
- `database/migrations/2026_07_23_000000_create_module_states_table.php`
- `src/Models/ModuleState.php`
- `src/ModuleManager.php` - resolution + writes + cache.
- `src/Facades/Modules.php`
- `tests/TestCase.php`, `tests/Pest.php`, `tests/Unit/*`, `tests/Feature/*`

---

## Task 1: Scaffold + Scope + ScopeResolver

**Files:**
- Create: `composer.json`, `config/modules-manager.php`, `src/Providers/ModulesManagerServiceProvider.php`, `src/Contracts/ScopeResolver.php`, `src/Support/Scope.php`, `src/Support/NullScopeResolver.php`, `tests/TestCase.php`, `tests/Pest.php`
- Test: `tests/Unit/ScopeTest.php`

**Interfaces:**
- Produces: `Kurt\Modules\Manager\Support\Scope` with `__construct(string $type, int|string $id)`, readonly props `$type`/`$id`, and `key(): string` returning `"{$type}:{$id}"`; `Kurt\Modules\Manager\Contracts\ScopeResolver` with `current(): ?Scope`; `Kurt\Modules\Manager\Support\NullScopeResolver implements ScopeResolver` returning `null`.

- [ ] **Step 1: Write `composer.json`**

```json
{
  "name": "ozankurt/laravel-modules-manager",
  "description": "Runtime module state/feature/setting management for KurtModules (headless).",
  "keywords": ["laravel", "kurtmodules", "modules", "feature-flags"],
  "license": "MIT",
  "authors": [{ "name": "Ozan Kurt", "email": "ozankurt2@gmail.com" }],
  "require": {
    "php": "^8.3",
    "illuminate/contracts": "^12.0",
    "illuminate/database": "^12.0",
    "illuminate/support": "^12.0",
    "ozankurt/laravel-modules-core": "^1.1",
    "spatie/laravel-package-tools": "^1.92"
  },
  "require-dev": {
    "larastan/larastan": "^3.0",
    "laravel/pint": "^1.18",
    "orchestra/testbench": "^10.0",
    "pestphp/pest": "^3.0",
    "pestphp/pest-plugin-laravel": "^3.0",
    "rector/rector": "^2.0"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/OzanKurt/laravel-modules-core" }
  ],
  "autoload": { "psr-4": { "Kurt\\Modules\\Manager\\": "src/" } },
  "autoload-dev": { "psr-4": { "Kurt\\Modules\\Manager\\Tests\\": "tests/" } },
  "extra": { "laravel": { "providers": ["Kurt\\Modules\\Manager\\Providers\\ModulesManagerServiceProvider"] } },
  "config": { "sort-packages": true, "allow-plugins": { "pestphp/pest-plugin": true } },
  "scripts": { "test": "vendor/bin/pest", "lint": "vendor/bin/pint --test", "stan": "vendor/bin/phpstan analyse --memory-limit=2G" },
  "minimum-stability": "stable",
  "prefer-stable": true
}
```

- [ ] **Step 2: Write `config/modules-manager.php`**

```php
<?php

declare(strict_types=1);

return [
    // headless | api | ui  (M2 uses headless only; M3 adds api)
    'http' => ['mode' => 'headless'],

    'cache' => [
        'enabled' => true,
        'store' => null,        // null = default cache store
        'prefix' => 'modules-manager',
        'ttl' => 3600,
    ],
];
```

- [ ] **Step 3: Write `src/Support/Scope.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\Manager\Support;

/** Immutable identifier of a management scope (e.g. tenant:5). */
final class Scope
{
    public function __construct(
        public readonly string $type,
        public readonly int|string $id,
    ) {}

    /** Stable cache/identity key for this scope. */
    public function key(): string
    {
        return "{$this->type}:{$this->id}";
    }
}
```

- [ ] **Step 4: Write `src/Contracts/ScopeResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\Manager\Contracts;

use Kurt\Modules\Manager\Support\Scope;

interface ScopeResolver
{
    /** The active scope, or null for the global scope. */
    public function current(): ?Scope;
}
```

- [ ] **Step 5: Write `src/Support/NullScopeResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\Manager\Support;

use Kurt\Modules\Manager\Contracts\ScopeResolver;

/** Default resolver: everything is global (no per-scope overrides). */
final class NullScopeResolver implements ScopeResolver
{
    public function current(): ?Scope
    {
        return null;
    }
}
```

- [ ] **Step 6: Write `src/Providers/ModulesManagerServiceProvider.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\Manager\Providers;

use Kurt\Modules\Manager\Contracts\ScopeResolver;
use Kurt\Modules\Manager\Support\NullScopeResolver;
use Kurt\Modules\Core\Providers\PackageServiceProvider;
use Spatie\LaravelPackageTools\Package;

final class ModulesManagerServiceProvider extends PackageServiceProvider
{
    protected function module(): string
    {
        return 'modules-manager';
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-modules-manager')
            ->hasConfigFile('modules-manager')
            ->hasMigration('2026_07_23_000000_create_module_states_table'); // spatie matches the on-disk filename (sans .php), so pass the full timestamped name
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ScopeResolver::class, fn () => new NullScopeResolver());
    }
}
```

- [ ] **Step 7: Write `tests/TestCase.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\Manager\Tests;

use Illuminate\Foundation\Application;
use Kurt\Modules\Manager\Providers\ModulesManagerServiceProvider;
use Kurt\Modules\Core\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /** @param  Application  $app @return array<int, class-string> */
    protected function modulePackageProviders($app): array
    {
        return [ModulesManagerServiceProvider::class];
    }

    // Testbench does NOT auto-run a package's provider-registered migrations, and
    // core's PackageTestCase only creates the users table — load ours explicitly.
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```

- [ ] **Step 8: Write `tests/Pest.php`**

```php
<?php

declare(strict_types=1);

use Kurt\Modules\Manager\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
```

- [ ] **Step 9: Write the failing test `tests/Unit/ScopeTest.php`**

```php
<?php

declare(strict_types=1);

use Kurt\Modules\Manager\Contracts\ScopeResolver;
use Kurt\Modules\Manager\Support\NullScopeResolver;
use Kurt\Modules\Manager\Support\Scope;

it('builds a scope key', function () {
    expect((new Scope('tenant', 5))->key())->toBe('tenant:5');
});

it('resolves the null (global) scope by default', function () {
    expect(app(ScopeResolver::class))->toBeInstanceOf(NullScopeResolver::class)
        ->and(app(ScopeResolver::class)->current())->toBeNull();
});
```

- [ ] **Step 10: Install and run**

Run: `"$PHP84" C:/laragon/bin/composer/composer.phar update --prefer-stable --no-interaction`
Then: `"$PHP84" vendor/bin/pest tests/Unit/ScopeTest.php`
Expected: PASS (2 passed). Resolves core v1.1.0 from the VCS repo.

- [ ] **Step 11: Commit**

```bash
git init && git add . && git commit -m "feat: scaffold laravel-modules-manager with scope resolution"
```

---

## Task 2: module_states migration + ModuleState model

**Files:**
- Create: `database/migrations/2026_07_23_000000_create_module_states_table.php`
- Create: `src/Models/ModuleState.php`
- Test: `tests/Feature/ModuleStateTest.php`

**Interfaces:**
- Produces: table `module_states` (`scope_type` nullable string, `scope_id` nullable string, `module` string, `kind` string, `key` nullable string, `value` json, timestamps; unique on the five identity columns). Model `Kurt\Modules\Manager\Models\ModuleState` with `$casts = ['value' => 'array']` and `$guarded = []`.

- [ ] **Step 1: Write the failing test `tests/Feature/ModuleStateTest.php`**

```php
<?php

declare(strict_types=1);

use Kurt\Modules\Manager\Models\ModuleState;

it('persists a module state row with a json value', function () {
    ModuleState::create([
        'scope_type' => null, 'scope_id' => null,
        'module' => 'blog', 'kind' => 'setting', 'key' => 'posts_per_page',
        'value' => ['v' => 15],
    ]);

    $row = ModuleState::firstOrFail();
    expect($row->module)->toBe('blog')
        ->and($row->kind)->toBe('setting')
        ->and($row->value)->toBe(['v' => 15]);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Feature/ModuleStateTest.php`
Expected: FAIL - table/model missing.

- [ ] **Step 3: Write the migration `database/migrations/2026_07_23_000000_create_module_states_table.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_states', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type')->nullable();
            $table->string('scope_id')->nullable();
            $table->string('module');
            $table->string('kind');            // state | feature | setting
            $table->string('key')->nullable(); // null for kind=state
            $table->json('value');
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'module', 'kind', 'key'], 'module_states_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_states');
    }
};
```

- [ ] **Step 4: Write `src/Models/ModuleState.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\Manager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $scope_type
 * @property string|null $scope_id
 * @property string $module
 * @property string $kind
 * @property string|null $key
 * @property mixed $value
 */
final class ModuleState extends Model
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['value' => 'array'];
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Feature/ModuleStateTest.php`
Expected: PASS (1 passed). (The migration runs because `tests/TestCase.php` loads it via `defineDatabaseMigrations()` -> `loadMigrationsFrom()` — Testbench does NOT auto-run provider-registered migrations.)

- [ ] **Step 6: Commit**

```bash
git add database/migrations src/Models/ModuleState.php tests/Feature/ModuleStateTest.php
git commit -m "feat: add module_states table and ModuleState model"
```

---

## Task 3: ModuleManager read resolution

**Files:**
- Create: `src/ModuleManager.php`
- Modify: `src/Providers/ModulesManagerServiceProvider.php` (bind `ModuleManager` singleton)
- Test: `tests/Feature/ModuleManagerReadTest.php`

**Interfaces:**
- Consumes: core `Kurt\Modules\Core\Contracts\ModuleRegistry` + `ModuleManifest`; `ScopeResolver`, `Scope`, `ModuleState` from Tasks 1-2.
- Produces: `Kurt\Modules\Manager\ModuleManager` with `enabled(string $slug, ?Scope $scope = null): bool`, `feature(string $slug, string $key, ?Scope $scope = null): bool`, `setting(string $slug, string $key, ?Scope $scope = null): mixed`. Resolution order per value: matching scope row -> global row -> manifest default -> hard default (`enabled` -> `manifest->isEnabledByDefault()`, unknown module -> false; `feature` -> `manifest->featureDefault($key)`; `setting` -> `manifest->settingDefault($key)`). When `$scope` is omitted, `ScopeResolver::current()` supplies it.

- [ ] **Step 1: Write the failing test `tests/Feature/ModuleManagerReadTest.php`**

```php
<?php

declare(strict_types=1);

use Kurt\Modules\Core\Contracts\ModuleRegistry;
use Kurt\Modules\Core\Modules\ModuleManifest;
use Kurt\Modules\Manager\Models\ModuleState;
use Kurt\Modules\Manager\ModuleManager;
use Kurt\Modules\Manager\Support\Scope;

beforeEach(function () {
    app(ModuleRegistry::class)->register(
        ModuleManifest::make('blog')
            ->enabledByDefault(true)
            ->feature('comments', default: true)
            ->setting('posts_per_page', default: 15, type: 'int')
    );
});

it('falls back to manifest defaults with no DB rows', function () {
    $m = app(ModuleManager::class);
    expect($m->enabled('blog'))->toBeTrue()
        ->and($m->feature('blog', 'comments'))->toBeTrue()
        ->and($m->setting('blog', 'posts_per_page'))->toBe(15);
});

it('unknown module is disabled and unknown keys use hard defaults', function () {
    $m = app(ModuleManager::class);
    expect($m->enabled('nope'))->toBeFalse()
        ->and($m->feature('blog', 'missing'))->toBeFalse()
        ->and($m->setting('blog', 'missing'))->toBeNull();
});

it('global row overrides the manifest default', function () {
    ModuleState::create(['scope_type' => null, 'scope_id' => null, 'module' => 'blog', 'kind' => 'state', 'key' => null, 'value' => ['v' => false]]);
    expect(app(ModuleManager::class)->enabled('blog'))->toBeFalse();
});

it('scope row overrides the global row', function () {
    ModuleState::create(['scope_type' => null, 'scope_id' => null, 'module' => 'blog', 'kind' => 'feature', 'key' => 'comments', 'value' => ['v' => false]]);
    ModuleState::create(['scope_type' => 'tenant', 'scope_id' => '5', 'module' => 'blog', 'kind' => 'feature', 'key' => 'comments', 'value' => ['v' => true]]);

    $m = app(ModuleManager::class);
    expect($m->feature('blog', 'comments'))->toBeFalse()                       // global
        ->and($m->feature('blog', 'comments', new Scope('tenant', 5)))->toBeTrue(); // scope wins
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Feature/ModuleManagerReadTest.php`
Expected: FAIL - `ModuleManager` missing.

- [ ] **Step 3: Write `src/ModuleManager.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\Manager;

use Kurt\Modules\Core\Contracts\ModuleRegistry;
use Kurt\Modules\Manager\Contracts\ScopeResolver;
use Kurt\Modules\Manager\Models\ModuleState;
use Kurt\Modules\Manager\Support\Scope;

final class ModuleManager
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ScopeResolver $scopes,
    ) {}

    public function enabled(string $slug, ?Scope $scope = null): bool
    {
        $manifest = $this->registry->get($slug);
        if ($manifest === null) {
            return false;
        }

        $override = $this->resolveOverride($slug, 'state', null, $scope);

        return $override === null ? $manifest->isEnabledByDefault() : (bool) $override;
    }

    public function feature(string $slug, string $key, ?Scope $scope = null): bool
    {
        $manifest = $this->registry->get($slug);
        if ($manifest === null || ! $manifest->hasFeature($key)) {
            return false;
        }

        $override = $this->resolveOverride($slug, 'feature', $key, $scope);

        return $override === null ? $manifest->featureDefault($key) : (bool) $override;
    }

    public function setting(string $slug, string $key, ?Scope $scope = null): mixed
    {
        $manifest = $this->registry->get($slug);
        if ($manifest === null || ! $manifest->hasSetting($key)) {
            return null;
        }

        $override = $this->resolveOverride($slug, 'setting', $key, $scope);

        return $override === null ? $manifest->settingDefault($key) : $override;
    }

    /**
     * The stored override value ({@see ModuleState::$value} unwrapped from its
     * `['v' => ...]` envelope), preferring the active scope's row over the
     * global row. Returns null when neither exists.
     */
    private function resolveOverride(string $slug, string $kind, ?string $key, ?Scope $scope): mixed
    {
        $scope ??= $this->scopes->current();

        if ($scope !== null) {
            $scoped = $this->row($slug, $kind, $key, $scope->type, (string) $scope->id);
            if ($scoped !== null) {
                return $scoped['v'] ?? null;
            }
        }

        $global = $this->row($slug, $kind, $key, null, null);

        return $global === null ? null : ($global['v'] ?? null);
    }

    /** @return array<string, mixed>|null */
    private function row(string $slug, string $kind, ?string $key, ?string $scopeType, ?string $scopeId): ?array
    {
        /** @var ModuleState|null $state */
        $state = ModuleState::query()
            ->where('module', $slug)
            ->where('kind', $kind)
            ->where('key', $key)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();

        return $state?->value;
    }
}
```

- [ ] **Step 4: Bind the manager in `ModulesManagerServiceProvider::packageRegistered()`**

Add imports and the binding:

```php
use Kurt\Modules\Core\Contracts\ModuleRegistry;
use Kurt\Modules\Manager\ModuleManager;
```

```php
    public function packageRegistered(): void
    {
        $this->app->singleton(ScopeResolver::class, fn () => new NullScopeResolver());
        $this->app->singleton(ModuleManager::class, fn ($app) => new ModuleManager(
            $app->make(ModuleRegistry::class),
            $app->make(ScopeResolver::class),
        ));
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Feature/ModuleManagerReadTest.php`
Expected: PASS (4 passed).

- [ ] **Step 6: Commit**

```bash
git add src/ModuleManager.php src/Providers/ModulesManagerServiceProvider.php tests/Feature/ModuleManagerReadTest.php
git commit -m "feat: resolve effective module state through scope/global/manifest"
```

---

## Task 4: Writes with registry validation

**Files:**
- Modify: `src/ModuleManager.php` (add setters)
- Create: `src/Exceptions/UnknownModuleTarget.php`
- Test: `tests/Feature/ModuleManagerWriteTest.php`

**Interfaces:**
- Produces: `ModuleManager::setEnabled(string $slug, bool $value, ?Scope $scope = null): void`, `setFeature(string $slug, string $key, bool $value, ?Scope $scope = null): void`, `setSetting(string $slug, string $key, mixed $value, ?Scope $scope = null): void`. Each validates against the registry (module registered; feature/setting declared in its manifest) and throws `Kurt\Modules\Manager\Exceptions\UnknownModuleTarget` otherwise. Writes upsert a `ModuleState` row keyed by the scope; value stored as `['v' => $value]`.

- [ ] **Step 1: Write the failing test `tests/Feature/ModuleManagerWriteTest.php`**

```php
<?php

declare(strict_types=1);

use Kurt\Modules\Core\Contracts\ModuleRegistry;
use Kurt\Modules\Core\Modules\ModuleManifest;
use Kurt\Modules\Manager\Exceptions\UnknownModuleTarget;
use Kurt\Modules\Manager\ModuleManager;
use Kurt\Modules\Manager\Support\Scope;

beforeEach(function () {
    app(ModuleRegistry::class)->register(
        ModuleManifest::make('blog')->feature('comments', default: true)->setting('posts_per_page', default: 15, type: 'int')
    );
});

it('writes and reads back an override (global and scoped)', function () {
    $m = app(ModuleManager::class);

    $m->setEnabled('blog', false);
    $m->setFeature('blog', 'comments', false);
    $m->setSetting('blog', 'posts_per_page', 30, new Scope('tenant', 5));

    expect($m->enabled('blog'))->toBeFalse()
        ->and($m->feature('blog', 'comments'))->toBeFalse()
        ->and($m->setting('blog', 'posts_per_page', new Scope('tenant', 5)))->toBe(30)
        ->and($m->setting('blog', 'posts_per_page'))->toBe(15); // global still default
});

it('upserts rather than duplicating on repeated writes', function () {
    $m = app(ModuleManager::class);
    $m->setEnabled('blog', false);
    $m->setEnabled('blog', true);

    expect(\Kurt\Modules\Manager\Models\ModuleState::where('module', 'blog')->where('kind', 'state')->count())->toBe(1)
        ->and($m->enabled('blog'))->toBeTrue();
});

it('rejects writing to an undeclared module/feature/setting', function () {
    $m = app(ModuleManager::class);
    expect(fn () => $m->setEnabled('ghost', true))->toThrow(UnknownModuleTarget::class);
    expect(fn () => $m->setFeature('blog', 'nope', true))->toThrow(UnknownModuleTarget::class);
    expect(fn () => $m->setSetting('blog', 'nope', 1))->toThrow(UnknownModuleTarget::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Feature/ModuleManagerWriteTest.php`
Expected: FAIL - setters / exception missing.

- [ ] **Step 3: Write `src/Exceptions/UnknownModuleTarget.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\Manager\Exceptions;

use RuntimeException;

final class UnknownModuleTarget extends RuntimeException
{
    public static function module(string $slug): self
    {
        return new self("Module [{$slug}] is not registered.");
    }

    public static function feature(string $slug, string $key): self
    {
        return new self("Module [{$slug}] does not declare feature [{$key}].");
    }

    public static function setting(string $slug, string $key): self
    {
        return new self("Module [{$slug}] does not declare setting [{$key}].");
    }
}
```

- [ ] **Step 4: Add setters to `ModuleManager`**

Add `use Kurt\Modules\Manager\Exceptions\UnknownModuleTarget;` and these methods:

```php
    public function setEnabled(string $slug, bool $value, ?Scope $scope = null): void
    {
        if (! $this->registry->has($slug)) {
            throw UnknownModuleTarget::module($slug);
        }

        $this->put($slug, 'state', null, $value, $scope);
    }

    public function setFeature(string $slug, string $key, bool $value, ?Scope $scope = null): void
    {
        $manifest = $this->registry->get($slug);
        if ($manifest === null || ! $manifest->hasFeature($key)) {
            throw UnknownModuleTarget::feature($slug, $key);
        }

        $this->put($slug, 'feature', $key, $value, $scope);
    }

    public function setSetting(string $slug, string $key, mixed $value, ?Scope $scope = null): void
    {
        $manifest = $this->registry->get($slug);
        if ($manifest === null || ! $manifest->hasSetting($key)) {
            throw UnknownModuleTarget::setting($slug, $key);
        }

        $this->put($slug, 'setting', $key, $value, $scope);
    }

    private function put(string $slug, string $kind, ?string $key, mixed $value, ?Scope $scope): void
    {
        $scope ??= $this->scopes->current();

        ModuleState::query()->updateOrCreate(
            [
                'scope_type' => $scope?->type,
                'scope_id' => $scope === null ? null : (string) $scope->id,
                'module' => $slug,
                'kind' => $kind,
                'key' => $key,
            ],
            ['value' => ['v' => $value]],
        );
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Feature/ModuleManagerWriteTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Commit**

```bash
git add src/Exceptions/UnknownModuleTarget.php src/ModuleManager.php tests/Feature/ModuleManagerWriteTest.php
git commit -m "feat: validated writes for module state/feature/setting overrides"
```

---

## Task 5: Modules facade

**Files:**
- Create: `src/Facades/Modules.php`
- Modify: `src/Providers/ModulesManagerServiceProvider.php` (alias binding `modules-manager` -> ModuleManager)
- Test: `tests/Feature/ModulesFacadeTest.php`

**Interfaces:**
- Consumes: `ModuleManager` from Tasks 3-4.
- Produces: facade `Kurt\Modules\Manager\Facades\Modules` proxying `enabled/feature/setting/setEnabled/setFeature/setSetting` to the `ModuleManager` singleton (accessor string `modules-manager`).

- [ ] **Step 1: Write the failing test `tests/Feature/ModulesFacadeTest.php`**

```php
<?php

declare(strict_types=1);

use Kurt\Modules\Core\Contracts\ModuleRegistry;
use Kurt\Modules\Core\Modules\ModuleManifest;
use Kurt\Modules\Manager\Facades\Modules;

it('proxies to the manager through the facade', function () {
    app(ModuleRegistry::class)->register(ModuleManifest::make('blog')->feature('comments', default: true));

    expect(Modules::feature('blog', 'comments'))->toBeTrue();
    Modules::setFeature('blog', 'comments', false);
    expect(Modules::feature('blog', 'comments'))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Feature/ModulesFacadeTest.php`
Expected: FAIL - facade / binding missing.

- [ ] **Step 3: Write `src/Facades/Modules.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\Manager\Facades;

use Illuminate\Support\Facades\Facade;
use Kurt\Modules\Manager\ModuleManager;

/**
 * @method static bool enabled(string $slug, ?\Kurt\Modules\Manager\Support\Scope $scope = null)
 * @method static bool feature(string $slug, string $key, ?\Kurt\Modules\Manager\Support\Scope $scope = null)
 * @method static mixed setting(string $slug, string $key, ?\Kurt\Modules\Manager\Support\Scope $scope = null)
 * @method static void setEnabled(string $slug, bool $value, ?\Kurt\Modules\Manager\Support\Scope $scope = null)
 * @method static void setFeature(string $slug, string $key, bool $value, ?\Kurt\Modules\Manager\Support\Scope $scope = null)
 * @method static void setSetting(string $slug, string $key, mixed $value, ?\Kurt\Modules\Manager\Support\Scope $scope = null)
 *
 * @see ModuleManager
 */
final class Modules extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'modules-manager';
    }
}
```

- [ ] **Step 4: Alias the accessor in `packageRegistered()`**

After binding `ModuleManager::class`, add:

```php
        $this->app->alias(ModuleManager::class, 'modules-manager');
```

- [ ] **Step 5: Run to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Feature/ModulesFacadeTest.php`
Expected: PASS (1 passed).

- [ ] **Step 6: Full suite + static analysis**

Run: `"$PHP84" vendor/bin/pest`  -> all green.
Run: `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G` -> no errors (add a `phpstan.neon` mirroring core's if absent).

- [ ] **Step 7: Commit**

```bash
git add src/Facades/Modules.php src/Providers/ModulesManagerServiceProvider.php tests/Feature/ModulesFacadeTest.php
git commit -m "feat: add Modules facade over the manager"
```

---

## Done Criteria

- `Modules::enabled/feature/setting` resolve `scope -> global -> manifest default -> hard default`; unknown module disabled; undeclared feature/setting -> hard default on read, `UnknownModuleTarget` on write.
- Writes upsert a single `module_states` row per identity; scoped overrides beat global.
- Full Pest suite + PHPStan green under PHP 8.4.

## Deferred

- **Caching** (spec Section 4): resolution is direct-DB in this plan for clarity. Add a cache decorator over `resolveOverride()` keyed by `(scopeKey, slug, kind, key)`, invalidated in `put()`, as the first task of a short M2.1 once read/write correctness is proven. Kept out here so the resolution semantics land first without cache-coherence noise.
- M3: REST API (`GET/PATCH /modules...`) + `EnsureModuleEnabled` middleware, gated by `HttpMode`.
- Rolling feature/setting declarations into each module's manifest (beyond identity) as modules define them.
