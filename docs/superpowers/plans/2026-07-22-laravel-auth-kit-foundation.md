# laravel-auth-kit Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scaffold the `ozankurt/laravel-modules-auth-kit` package and build the three configuration axes (HttpMode, feature flags, per-user capability gates) that every later auth flow hangs off.

**Architecture:** Orchestrator meta-package sitting on `ozankurt/laravel-modules-core`. A singleton `AuthKitManager` (facade `AuthKit`) answers two questions: `feature($name)` = "is this flow enabled in the app?" (reads `config('auth-kit.features.*')`) and `gate($key, $user)` = "does it apply to this user?" (reads `config('auth-kit.gates.*')`, accepting `bool | Closure(User): bool`). A published `AuthKitUser` interface + `InteractsWithAuthKit` trait give the User model overridable, config-backed default capability methods.

**Tech Stack:** PHP 8.4, Laravel 12 (`illuminate/*` ^12), `spatie/laravel-package-tools`, Pest 3 + Orchestra Testbench, PHPStan/Larastan, Pint, Rector.

**Package identity:** composer `ozankurt/laravel-modules-auth-kit`, namespace `Kurt\Modules\AuthKit\`, repo `KurtModules-Auth-Kit`, module slug `auth-kit`.

---

## Toolchain (this machine)

Default `php` is 8.3; this package needs 8.4. Always use the Laragon 8.4 binary explicitly. For brevity below, `PHP84` refers to:

```
C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe
```

- Composer: `"$PHP84" C:/laragon/bin/composer/composer.phar <cmd>`
- Tests: `"$PHP84" vendor/bin/pest`
- Stan: `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G`
- Lint: `"$PHP84" vendor/bin/pint --test`

Commits follow EpicAlgorithms git guidelines: **no AI attribution** in messages.

---

## File Structure

New repo `KurtModules-Auth-Kit/`:

- `composer.json` - package manifest; requires core via VCS; PSR-4 `Kurt\Modules\AuthKit\`.
- `config/auth-kit.php` - `http`, `features`, `gates`, `lockout`, `passwordless` keys.
- `src/Providers/AuthKitServiceProvider.php` - extends core `PackageServiceProvider`; `module()` returns `auth-kit`; merges config; binds the manager singleton; publishes config.
- `src/AuthKitManager.php` - the singleton; `feature()` and `gate()` live here.
- `src/Facades/AuthKit.php` - facade over the `auth-kit` binding.
- `src/Contracts/AuthKitUser.php` - capability interface the User model implements.
- `src/Concerns/InteractsWithAuthKit.php` - trait giving config-backed default implementations.
- `tests/TestCase.php` - extends core `PackageTestCase`; registers `AuthKitServiceProvider`.
- `tests/Pest.php` - binds Pest to `TestCase`.
- `tests/Unit/*` - unit specs per task.

Only new files are created in this plan; nothing in `KurtModules-Core` is modified.

---

## Task 1: Package scaffold + manager singleton

**Files:**
- Create: `composer.json`
- Create: `config/auth-kit.php`
- Create: `src/Providers/AuthKitServiceProvider.php`
- Create: `src/AuthKitManager.php`
- Create: `src/Facades/AuthKit.php`
- Create: `tests/TestCase.php`
- Create: `tests/Pest.php`
- Test: `tests/Unit/ScaffoldTest.php`

- [ ] **Step 1: Write `composer.json`**

```json
{
  "name": "ozankurt/laravel-modules-auth-kit",
  "description": "Maintained, orchestrator auth kit for KurtModules Laravel apps.",
  "keywords": ["laravel", "auth", "2fa", "kurtmodules"],
  "license": "MIT",
  "authors": [{ "name": "Ozan Kurt", "email": "ozankurt2@gmail.com" }],
  "require": {
    "php": "^8.3",
    "illuminate/contracts": "^12.0",
    "illuminate/support": "^12.0",
    "ozankurt/laravel-modules-core": "^1.0",
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
  "autoload": { "psr-4": { "Kurt\\Modules\\AuthKit\\": "src/" } },
  "autoload-dev": { "psr-4": { "Kurt\\Modules\\AuthKit\\Tests\\": "tests/" } },
  "extra": {
    "laravel": {
      "providers": ["Kurt\\Modules\\AuthKit\\Providers\\AuthKitServiceProvider"]
    }
  },
  "config": {
    "sort-packages": true,
    "allow-plugins": { "pestphp/pest-plugin": true }
  },
  "scripts": {
    "test": "vendor/bin/pest",
    "lint": "vendor/bin/pint --test",
    "stan": "vendor/bin/phpstan analyse --memory-limit=2G"
  },
  "minimum-stability": "stable",
  "prefer-stable": true
}
```

- [ ] **Step 2: Write `config/auth-kit.php`** (full shape used by all later tasks; only `http`/`features`/`gates` are exercised in Foundation, the rest are declared so downstream milestones have a home)

```php
<?php

declare(strict_types=1);

return [
    'http' => [
        // headless | api | ui  (resolved via Kurt\Modules\Core\Http\HttpMode)
        'mode' => 'ui',
    ],

    'features' => [
        'registration' => true,
        'email_verification' => true,
        'password_reset' => true,
        'password_confirmation' => true,
        'two_factor' => true,
        'otp_login' => false,
        'magic_link' => false,
        'sessions' => true,
        'login_journal' => true,
        'lockout' => true,
    ],

    // Each leaf is bool | Closure(\Illuminate\Database\Eloquent\Model $user): bool.
    // Nested (not flat dotted keys) so config() dot-notation resolves them,
    // e.g. config('auth-kit.gates.two_factor.enforced').
    'gates' => [
        'two_factor' => [
            'enforced' => false,
            'can_enable' => true,
        ],
        'otp_login' => [
            'can_use' => false,
        ],
        'registration' => [
            'can_register' => true,
        ],
    ],

    'lockout' => [
        'max_attempts' => 5,
        'decay_seconds' => 900,
    ],

    'passwordless' => [
        'token_ttl' => 900,
        'resend_cooldown' => 60,
    ],
];
```

- [ ] **Step 3: Write `src/AuthKitManager.php`** (empty behavior for now; `feature()`/`gate()` added in Tasks 2-3 so this task stays a pure scaffold-boots check)

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit;

use Illuminate\Contracts\Config\Repository;

final class AuthKitManager
{
    public function __construct(private readonly Repository $config) {}

    /** Module slug used for config lookups. */
    public function module(): string
    {
        return 'auth-kit';
    }
}
```

- [ ] **Step 4: Write `src/Facades/AuthKit.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Facades;

use Illuminate\Support\Facades\Facade;
use Kurt\Modules\AuthKit\AuthKitManager;

/**
 * @method static string module()
 *
 * @see AuthKitManager
 */
final class AuthKit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'auth-kit';
    }
}
```

- [ ] **Step 5: Write `src/Providers/AuthKitServiceProvider.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Providers;

use Kurt\Modules\AuthKit\AuthKitManager;
use Kurt\Modules\Core\Providers\PackageServiceProvider;
use Spatie\LaravelPackageTools\Package;

final class AuthKitServiceProvider extends PackageServiceProvider
{
    protected function module(): string
    {
        return 'auth-kit';
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-modules-auth-kit')
            ->hasConfigFile('auth-kit');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton('auth-kit', fn ($app) => new AuthKitManager($app['config']));
        $this->app->alias('auth-kit', AuthKitManager::class);
    }
}
```

- [ ] **Step 6: Write `tests/TestCase.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Tests;

use Illuminate\Foundation\Application;
use Kurt\Modules\AuthKit\Providers\AuthKitServiceProvider;
use Kurt\Modules\Core\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function modulePackageProviders($app): array
    {
        return [AuthKitServiceProvider::class];
    }
}
```

- [ ] **Step 7: Write `tests/Pest.php`**

```php
<?php

declare(strict_types=1);

use Kurt\Modules\AuthKit\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
```

- [ ] **Step 8: Write the failing test `tests/Unit/ScaffoldTest.php`**

```php
<?php

declare(strict_types=1);

use Kurt\Modules\AuthKit\AuthKitManager;
use Kurt\Modules\AuthKit\Facades\AuthKit;

it('boots the package and merges config', function () {
    expect(config('auth-kit.features.registration'))->toBeTrue();
});

it('resolves the manager singleton and facade', function () {
    expect(app('auth-kit'))->toBeInstanceOf(AuthKitManager::class)
        ->and(app('auth-kit'))->toBe(app(AuthKitManager::class))
        ->and(AuthKit::module())->toBe('auth-kit');
});
```

- [ ] **Step 9: Install dependencies**

Run: `"$PHP84" C:/laragon/bin/composer/composer.phar update --prefer-stable --prefer-dist --no-interaction`
Expected: resolves `ozankurt/laravel-modules-core` from the VCS repo, writes `composer.lock`, no platform errors.

- [ ] **Step 10: Run the test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Unit/ScaffoldTest.php`
Expected: PASS (2 passed). If the config assertion fails, the config file was not merged — check `hasConfigFile('auth-kit')` and the config filename.

- [ ] **Step 11: Commit**

```bash
git add composer.json config/auth-kit.php src/ tests/
git commit -m "feat: scaffold auth-kit package and manager singleton"
```

---

## Task 2: Feature-flag resolver (`AuthKit::feature()`)

**Files:**
- Modify: `src/AuthKitManager.php`
- Modify: `src/Facades/AuthKit.php` (add `@method` docblock line)
- Test: `tests/Unit/FeatureTest.php`

- [ ] **Step 1: Write the failing test `tests/Unit/FeatureTest.php`**

```php
<?php

declare(strict_types=1);

use Kurt\Modules\AuthKit\Facades\AuthKit;

it('reads a feature flag from config', function () {
    config()->set('auth-kit.features.registration', true);
    expect(AuthKit::feature('registration'))->toBeTrue();

    config()->set('auth-kit.features.registration', false);
    expect(AuthKit::feature('registration'))->toBeFalse();
});

it('treats an unknown feature as disabled', function () {
    expect(AuthKit::feature('does_not_exist'))->toBeFalse();
});

it('coerces truthy config values to a strict boolean', function () {
    config()->set('auth-kit.features.two_factor', 1);
    expect(AuthKit::feature('two_factor'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Unit/FeatureTest.php`
Expected: FAIL — `Method feature does not exist` / `BadMethodCallException`.

- [ ] **Step 3: Add `feature()` to `AuthKitManager`**

Add this method to the `AuthKitManager` class body:

```php
    /**
     * Whether an auth-kit flow is enabled in the host app.
     *
     * Unknown flags resolve to false (safe-by-default: a flow the app never
     * declared is off).
     */
    public function feature(string $name): bool
    {
        return (bool) $this->config->get("auth-kit.features.{$name}", false);
    }
```

- [ ] **Step 4: Add the `@method` line to `src/Facades/AuthKit.php`**

Update the class docblock so it reads:

```php
/**
 * @method static string module()
 * @method static bool feature(string $name)
 *
 * @see AuthKitManager
 */
```

- [ ] **Step 5: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Unit/FeatureTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Commit**

```bash
git add src/AuthKitManager.php src/Facades/AuthKit.php tests/Unit/FeatureTest.php
git commit -m "feat: add auth-kit feature-flag resolver"
```

---

## Task 3: Per-user gate resolution (`AuthKit::gate()`)

**Files:**
- Modify: `src/AuthKitManager.php`
- Modify: `src/Facades/AuthKit.php` (add `@method` line)
- Test: `tests/Unit/GateTest.php`

- [ ] **Step 1: Write the failing test `tests/Unit/GateTest.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Kurt\Modules\AuthKit\Facades\AuthKit;

/** Minimal stand-in user for gate resolution. */
class GateUserStub extends Model
{
    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
}

it('resolves a boolean gate', function () {
    config()->set('auth-kit.gates.two_factor.enforced', true);
    expect(AuthKit::gate('two_factor.enforced', new GateUserStub))->toBeTrue();

    config()->set('auth-kit.gates.two_factor.enforced', false);
    expect(AuthKit::gate('two_factor.enforced', new GateUserStub))->toBeFalse();
});

it('resolves a closure gate against the given user', function () {
    config()->set('auth-kit.gates.two_factor.enforced', fn (Model $u) => $u->is_admin === true);

    expect(AuthKit::gate('two_factor.enforced', new GateUserStub(['is_admin' => true])))->toBeTrue();
    expect(AuthKit::gate('two_factor.enforced', new GateUserStub(['is_admin' => false])))->toBeFalse();
});

it('treats an unset gate as false', function () {
    expect(AuthKit::gate('missing.gate', new GateUserStub))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Unit/GateTest.php`
Expected: FAIL — `Method gate does not exist`.

- [ ] **Step 3: Add `gate()` to `AuthKitManager`**

Add to the top of the file (after the existing `use` line):

```php
use Closure;
use Illuminate\Database\Eloquent\Model;
```

Add this method to the class body:

```php
    /**
     * Resolve a per-user capability gate.
     *
     * The config value at "auth-kit.gates.{$key}" is either a bool (static
     * answer) or a Closure(Model $user): bool (dynamic answer). Anything else,
     * or an unset key, resolves to false.
     */
    public function gate(string $key, Model $user): bool
    {
        $value = $this->config->get("auth-kit.gates.{$key}", false);

        if ($value instanceof Closure) {
            return (bool) $value($user);
        }

        return (bool) $value;
    }
```

- [ ] **Step 4: Add the `@method` line to `src/Facades/AuthKit.php`**

Docblock becomes:

```php
/**
 * @method static string module()
 * @method static bool feature(string $name)
 * @method static bool gate(string $key, \Illuminate\Database\Eloquent\Model $user)
 *
 * @see AuthKitManager
 */
```

- [ ] **Step 5: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Unit/GateTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Commit**

```bash
git add src/AuthKitManager.php src/Facades/AuthKit.php tests/Unit/GateTest.php
git commit -m "feat: add per-user gate resolution to auth-kit"
```

---

## Task 4: `AuthKitUser` contract + `InteractsWithAuthKit` trait

**Files:**
- Create: `src/Contracts/AuthKitUser.php`
- Create: `src/Concerns/InteractsWithAuthKit.php`
- Test: `tests/Unit/AuthKitUserTest.php`

- [ ] **Step 1: Write the failing test `tests/Unit/AuthKitUserTest.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Kurt\Modules\AuthKit\Concerns\InteractsWithAuthKit;
use Kurt\Modules\AuthKit\Contracts\AuthKitUser;

class TraitUserStub extends Model implements AuthKitUser
{
    use InteractsWithAuthKit;

    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
}

/** A user that overrides a default to prove override wins over config. */
class OverridingUserStub extends TraitUserStub
{
    public function isTwoFactorEnforced(): bool
    {
        return true;
    }
}

it('delegates capability methods to the matching config gate', function () {
    config()->set('auth-kit.gates.two_factor.can_enable', true);
    config()->set('auth-kit.gates.two_factor.enforced', false);
    config()->set('auth-kit.gates.otp_login.can_use', false);
    config()->set('auth-kit.gates.registration.can_register', true);

    $user = new TraitUserStub;

    expect($user->canEnableTwoFactor())->toBeTrue()
        ->and($user->isTwoFactorEnforced())->toBeFalse()
        ->and($user->canUseOtpLogin())->toBeFalse()
        ->and($user->canRegister())->toBeTrue();
});

it('resolves a closure gate with the user itself', function () {
    config()->set('auth-kit.gates.two_factor.enforced', fn (Model $u) => $u->getKey() === 7);

    $user = new TraitUserStub(['id' => 7]);
    expect($user->isTwoFactorEnforced())->toBeTrue();
});

it('lets a model override the trait default', function () {
    config()->set('auth-kit.gates.two_factor.enforced', false);
    expect((new OverridingUserStub)->isTwoFactorEnforced())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Unit/AuthKitUserTest.php`
Expected: FAIL — `Interface "Kurt\Modules\AuthKit\Contracts\AuthKitUser" not found`.

- [ ] **Step 3: Write `src/Contracts/AuthKitUser.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Contracts;

/**
 * Per-user auth capabilities. A host User model implements this (usually via
 * {@see \Kurt\Modules\AuthKit\Concerns\InteractsWithAuthKit}) so auth-kit can
 * ask "is this allowed for this user?" independently of whether the feature is
 * enabled app-wide.
 */
interface AuthKitUser
{
    public function canEnableTwoFactor(): bool;

    public function isTwoFactorEnforced(): bool;

    public function canUseOtpLogin(): bool;

    public function canRegister(): bool;
}
```

- [ ] **Step 4: Write `src/Concerns/InteractsWithAuthKit.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Concerns;

use Kurt\Modules\AuthKit\Facades\AuthKit;

/**
 * Config-backed default implementations of {@see \Kurt\Modules\AuthKit\Contracts\AuthKitUser}.
 *
 * Each method delegates to the matching gate in `config('auth-kit.gates.*')`.
 * A host model may override any method for full per-user control; the override
 * wins because it shadows the trait method.
 */
trait InteractsWithAuthKit
{
    public function canEnableTwoFactor(): bool
    {
        return AuthKit::gate('two_factor.can_enable', $this);
    }

    public function isTwoFactorEnforced(): bool
    {
        return AuthKit::gate('two_factor.enforced', $this);
    }

    public function canUseOtpLogin(): bool
    {
        return AuthKit::gate('otp_login.can_use', $this);
    }

    public function canRegister(): bool
    {
        return AuthKit::gate('registration.can_register', $this);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Unit/AuthKitUserTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Run the full suite + static analysis**

Run: `"$PHP84" vendor/bin/pest`
Expected: PASS (all tasks green).

Run: `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G`
Expected: no errors. (Install/config Larastan via a `phpstan.neon` copied from KurtModules-Core if not present; level should match Core.)

- [ ] **Step 7: Commit**

```bash
git add src/Contracts/AuthKitUser.php src/Concerns/InteractsWithAuthKit.php tests/Unit/AuthKitUserTest.php
git commit -m "feat: add AuthKitUser contract and InteractsWithAuthKit trait"
```

---

## Done Criteria

- `AuthKit::feature($name)` reads `config('auth-kit.features.*')`, unknown → false.
- `AuthKit::gate($key, $user)` resolves `bool | Closure(Model): bool`, unset → false.
- `AuthKitUser` interface + `InteractsWithAuthKit` trait give a User model four config-backed, overridable capability methods.
- `HttpMode::forModule('auth-kit')` works out of the box (provided by Core; no code needed here — exercised in Milestone 2 when routes are registered).
- Full Pest suite + PHPStan green under PHP 8.4.

## Next Milestone

Milestone 2 (Core identity: register / login / logout, dual-mode, lockout, journal) gets its own plan. It will register routes via the Core `registerModuleApi()` / a UI route file gated by `HttpMode`, and wire `laravel-auth-events` — whose actual API must be inspected when that plan is written.
