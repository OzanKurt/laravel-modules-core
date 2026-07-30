# laravel-auth-kit M3 - Registration - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add user registration to `ozankurt/laravel-modules-auth-kit`, dual-mode (Blade/JSON), gated by the `registration` feature flag, on top of M1 (manager/gates) + M2 (login/logout).

**Architecture:** A `Registrar` contract creates the user (so auth-kit never hardcodes a User model or its columns), with a default `EloquentRegistrar` over Core's `UserResolver` (config-driven fillable fields + hashed password). `RegisterAction` calls the registrar, fires Laravel's `Registered` event (the app's default listener sends email verification when the model implements `MustVerifyEmail`), and optionally logs the new user in. `RegisterController` mirrors M2's dual-mode `LoginController`. Registration routes register only when `feature('registration')` is on AND `HttpMode` is `ui`/`api`.

**Tech Stack:** PHP 8.3+ (tests on Laragon 8.4), Laravel 12, `ozankurt/laravel-modules-core ^1.0` (v1.3.0). Pest 3 + Testbench. Reuses M2's test harness (`tests/Modes/{Ui,Api,Headless}` mode cases, `createUser()` helper, `AuthKitTestUser`).

## Global Constraints

- Package `ozankurt/laravel-modules-auth-kit`, namespace `Kurt\Modules\AuthKit\`, slug `auth-kit`, repo `OzanKurt/laravel-modules-auth-kit` (branch `main`).
- `declare(strict_types=1);` atop every PHP file. No AI attribution in commits.
- Security: password stored via the hasher (`Hash::make`), never plain; validation requires `confirmed` + a min length; new-user creation goes through the `Registrar` seam only.
- Registration is gated by BOTH `AuthKit::feature('registration')` (route absent when off) and the module `HttpMode` (`headless` = no routes). The per-user `AuthKitUser::canRegister()` gate is for authenticated/admin-created-user scenarios and is NOT consulted for public self-registration (public registration has no acting user yet) — leave it unused here.
- Registration inherently reveals email existence via the `unique` rule; that is standard and accepted for a registration form (unlike login, which must stay enumeration-safe).
- Build in `D:\Code\Projects\laravel-modules-auth-kit`. Toolchain: `PHP84 = C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe`; tests `"$PHP84" vendor/bin/pest`; stan `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G`. STUDY M2's existing code first (`LoginController` dual-mode pattern, `wantsJson()`, the `StatefulGuard` binding in `packageRegistered()`, the mode test-case layout) and mirror it.

## File Structure

- `src/Contracts/Registrar.php` - user-creation seam.
- `src/Support/EloquentRegistrar.php` - default impl over `UserResolver`.
- `src/Actions/RegisterAction.php` - registrar + `Registered` event + optional auto-login.
- `src/Http/Requests/RegisterRequest.php` - validation.
- `src/Http/Controllers/RegisterController.php` - dual-mode `showForm`/`register`.
- `resources/views/auth/register.blade.php` - publishable form.
- `config/auth-kit.php` - MODIFY: add `register` block.
- `routes/web.php`, `routes/api.php` - MODIFY: add register routes, gated by `feature('registration')`.
- `src/Providers/AuthKitServiceProvider.php` - MODIFY: bind `Registrar`.
- Tests: `tests/Unit/Support/EloquentRegistrarTest.php`, `tests/Unit/Actions/RegisterActionTest.php`, `tests/Modes/Ui/RegisterTest.php`, `tests/Modes/Api/ApiRegisterTest.php`, `tests/Modes/Ui/RegistrationDisabledTest.php`.

---

## Task 1: Registrar contract + EloquentRegistrar + config + binding

**Files:**
- Create: `src/Contracts/Registrar.php`, `src/Support/EloquentRegistrar.php`
- Modify: `config/auth-kit.php`, `src/Providers/AuthKitServiceProvider.php`
- Test: `tests/Unit/Support/EloquentRegistrarTest.php`

**Interfaces:**
- Produces: `Kurt\Modules\AuthKit\Contracts\Registrar` with `register(array $data): Illuminate\Contracts\Auth\Authenticatable`. `Kurt\Modules\AuthKit\Support\EloquentRegistrar` implements it: instantiates `UserResolver::modelClass()`, assigns each config-listed field present in `$data`, sets `password` via the hasher, saves, returns the model. Bound to `Registrar::class` in the provider.

- [ ] **Step 1: Write the failing test `tests/Unit/Support/EloquentRegistrarTest.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Kurt\Modules\AuthKit\Contracts\Registrar;

it('creates a user with a hashed password and configured fields', function () {
    $user = app(Registrar::class)->register([
        'name' => 'Ada',
        'email' => 'ada@b.com',
        'password' => 'secret12',
    ]);

    expect($user)->toBeInstanceOf(Authenticatable::class)
        ->and($user->getAuthIdentifier())->not->toBeNull()   // persisted
        ->and($user->email)->toBe('ada@b.com')
        ->and($user->name)->toBe('Ada')
        ->and($user->password)->not->toBe('secret12')          // hashed
        ->and(Hash::check('secret12', $user->password))->toBeTrue();
});

it('ignores data keys not in the configured fields (mass-assignment safety)', function () {
    config()->set('auth-kit.register.fields', ['name', 'email']);

    $user = app(Registrar::class)->register([
        'name' => 'Ada', 'email' => 'ada@b.com', 'password' => 'secret12',
        'is_admin' => true, // not a configured field
    ]);

    expect($user->is_admin ?? null)->not->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Unit/Support/EloquentRegistrarTest.php`
Expected: FAIL — `Registrar` not bound / class missing.

- [ ] **Step 3: Write `src/Contracts/Registrar.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Creates a new user from validated registration data. The default binding is
 * {@see \Kurt\Modules\AuthKit\Support\EloquentRegistrar}; a host app can rebind
 * this to control exactly how its User model is created.
 */
interface Registrar
{
    /** @param  array<string, mixed>  $data  validated registration input */
    public function register(array $data): Authenticatable;
}
```

- [ ] **Step 4: Write `src/Support/EloquentRegistrar.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Hashing\Hasher;
use Kurt\Modules\AuthKit\Contracts\Registrar;
use Kurt\Modules\Core\Contracts\UserResolver;

final class EloquentRegistrar implements Registrar
{
    public function __construct(
        private readonly UserResolver $users,
        private readonly Hasher $hasher,
        private readonly Repository $config,
    ) {}

    public function register(array $data): Authenticatable
    {
        $class = $this->users->modelClass();

        /** @var \Illuminate\Database\Eloquent\Model&Authenticatable $user */
        $user = new $class;

        /** @var array<int, string> $fields */
        $fields = (array) $this->config->get('auth-kit.register.fields', ['name', 'email']);

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }

        $user->password = $this->hasher->make((string) $data['password']);
        $user->save();

        return $user;
    }
}
```

- [ ] **Step 5: Add the `register` config block to `config/auth-kit.php`**

```php
    'register' => [
        'fields' => ['name', 'email'],  // assignable from registration input (never mass-assign the rest)
        'login_after' => true,          // log the new user in immediately
        'redirect_to' => '/',           // UI redirect on success
    ],
```

- [ ] **Step 6: Bind `Registrar` in `AuthKitServiceProvider::packageRegistered()`**

Add imports and, alongside the existing bindings:

```php
use Kurt\Modules\AuthKit\Contracts\Registrar;
use Kurt\Modules\AuthKit\Support\EloquentRegistrar;
use Illuminate\Contracts\Hashing\Hasher;
use Kurt\Modules\Core\Contracts\UserResolver;
```

```php
        $this->app->singleton(Registrar::class, fn ($app) => new EloquentRegistrar(
            $app->make(UserResolver::class),
            $app->make(Hasher::class),
            $app['config'],
        ));
```

- [ ] **Step 7: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Unit/Support/EloquentRegistrarTest.php`
Expected: PASS (2 passed). (If the test User model's `$fillable`/`$guarded` blocks direct assignment, assign on the instance as the impl does — direct property set bypasses mass-assignment; the second test proves only configured fields are copied.)

- [ ] **Step 8: Commit**

```bash
git add src/Contracts/Registrar.php src/Support/EloquentRegistrar.php config/auth-kit.php src/Providers/AuthKitServiceProvider.php tests/Unit/Support/EloquentRegistrarTest.php
git commit -m "feat: add Registrar contract and EloquentRegistrar"
```

---

## Task 2: RegisterAction

**Files:**
- Create: `src/Actions/RegisterAction.php`
- Test: `tests/Unit/Actions/RegisterActionTest.php`

**Interfaces:**
- Consumes: `Registrar` (Task 1), `StatefulGuard` (bound in M2), the event dispatcher.
- Produces: `Kurt\Modules\AuthKit\Actions\RegisterAction` with `handle(array $data): Authenticatable` — registers via `Registrar`, dispatches `Illuminate\Auth\Events\Registered`, logs the user in when `auth-kit.register.login_after` is true, returns the user.

- [ ] **Step 1: Write the failing test `tests/Unit/Actions/RegisterActionTest.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Kurt\Modules\AuthKit\Actions\RegisterAction;

it('registers, fires Registered, and logs the user in by default', function () {
    Event::fake([Registered::class]);

    $user = app(RegisterAction::class)->handle(['name' => 'Ada', 'email' => 'ada@b.com', 'password' => 'secret12']);

    Event::assertDispatched(Registered::class);
    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->getAuthIdentifier());
});

it('does not log in when login_after is disabled', function () {
    config()->set('auth-kit.register.login_after', false);
    Event::fake([Registered::class]);

    app(RegisterAction::class)->handle(['name' => 'Ada', 'email' => 'ada@b.com', 'password' => 'secret12']);

    expect(Auth::check())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Unit/Actions/RegisterActionTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Write `src/Actions/RegisterAction.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Actions;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Kurt\Modules\AuthKit\Contracts\Registrar;

/**
 * Register a new user: create them via the {@see Registrar}, fire the standard
 * Registered event (the app's default listener sends email verification when
 * the model implements MustVerifyEmail), then optionally log them in.
 */
final class RegisterAction
{
    public function __construct(
        private readonly Registrar $registrar,
        private readonly StatefulGuard $guard,
        private readonly Dispatcher $events,
        private readonly Repository $config,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function handle(array $data): Authenticatable
    {
        $user = $this->registrar->register($data);

        $this->events->dispatch(new Registered($user));

        if ((bool) $this->config->get('auth-kit.register.login_after', true)) {
            $this->guard->login($user);
        }

        return $user;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Unit/Actions/RegisterActionTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add src/Actions/RegisterAction.php tests/Unit/Actions/RegisterActionTest.php
git commit -m "feat: add RegisterAction firing Registered and optional auto-login"
```

---

## Task 3: RegisterRequest + RegisterController + routes + view

**Files:**
- Create: `src/Http/Requests/RegisterRequest.php`, `src/Http/Controllers/RegisterController.php`, `resources/views/auth/register.blade.php`
- Modify: `routes/web.php`, `routes/api.php`
- Test: `tests/Modes/Ui/RegisterTest.php`, `tests/Modes/Api/ApiRegisterTest.php`, `tests/Modes/Ui/RegistrationDisabledTest.php`

**Interfaces:**
- Consumes: `RegisterAction` (Task 2), M2's `wantsJson()` dual-mode pattern + `ApiController` base + `AuthKit` facade.
- Produces: routes `auth-kit.register` (GET, ui) + `auth-kit.register.attempt` (POST) registered ONLY when `AuthKit::feature('registration')`. `RegisterController::showForm()` (view `auth-kit::auth.register`) + `register(RegisterRequest)` (dual-mode: redirect to `register.redirect_to` / JSON `data.registered=true` + `data.id`).

- [ ] **Step 1: Write the failing tests**

`tests/Modes/Ui/RegisterTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;

it('registers a user via the UI and logs them in', function () {
    $this->post(route('auth-kit.register.attempt'), [
        'name' => 'Ada', 'email' => 'ada@b.com',
        'password' => 'secret12', 'password_confirmation' => 'secret12',
    ])->assertRedirect();

    expect(Auth::check())->toBeTrue();
});

it('rejects a mismatched password confirmation', function () {
    $this->from(route('auth-kit.register'))->post(route('auth-kit.register.attempt'), [
        'name' => 'Ada', 'email' => 'ada@b.com',
        'password' => 'secret12', 'password_confirmation' => 'nope',
    ])->assertSessionHasErrors('password');

    expect(Auth::check())->toBeFalse();
});
```

`tests/Modes/Api/ApiRegisterTest.php`:
```php
<?php

declare(strict_types=1);

it('registers via the API with a JSON envelope', function () {
    $this->postJson(route('auth-kit.register.attempt'), [
        'name' => 'Ada', 'email' => 'ada@b.com',
        'password' => 'secret12', 'password_confirmation' => 'secret12',
    ])->assertCreated()->assertJsonPath('data.registered', true);
});
```

`tests/Modes/Ui/RegistrationDisabledTest.php` (a UI-mode case with `auth-kit.features.registration=false` set in `defineEnvironment`):
```php
<?php

declare(strict_types=1);

it('does not register the registration routes when the feature is off', function () {
    expect(app('router')->has('auth-kit.register.attempt'))->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/RegisterTest.php`
Expected: FAIL — routes undefined.

- [ ] **Step 3: Write `src/Http/Requests/RegisterRequest.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kurt\Modules\Core\Contracts\UserResolver;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $table = app(UserResolver::class)->table();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique($table, 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
```

- [ ] **Step 4: Write `src/Http/Controllers/RegisterController.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Kurt\Modules\AuthKit\Actions\RegisterAction;
use Kurt\Modules\AuthKit\Http\Requests\RegisterRequest;
use Kurt\Modules\Core\Http\Controllers\ApiController;
use Kurt\Modules\Core\Http\HttpMode;

final class RegisterController extends ApiController
{
    public function showForm(): View
    {
        return view('auth-kit::auth.register');
    }

    public function register(RegisterRequest $request, RegisterAction $action): RedirectResponse|JsonResponse
    {
        $user = $action->handle($request->validated());

        if ($this->wantsJson($request)) {
            return $this->respondCreated(['registered' => true, 'id' => $user->getAuthIdentifier()]);
        }

        return redirect()->intended(config('auth-kit.register.redirect_to', '/'));
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson()
            || HttpMode::forModule('auth-kit') === HttpMode::Api;
    }
}
```

(If M2 exposed `wantsJson()` as a shared trait/base method, reuse that instead of duplicating — check M2's `LoginController` and follow whatever it did.)

- [ ] **Step 5: Add register routes (gated by the feature flag) to `routes/web.php` and `routes/api.php`**

In `routes/web.php`, inside the existing `web` group:
```php
if (\Kurt\Modules\AuthKit\Facades\AuthKit::feature('registration')) {
    Route::get('register', [\Kurt\Modules\AuthKit\Http\Controllers\RegisterController::class, 'showForm'])->middleware('guest')->name('auth-kit.register');
    Route::post('register', [\Kurt\Modules\AuthKit\Http\Controllers\RegisterController::class, 'register'])->middleware('guest')->name('auth-kit.register.attempt');
}
```

In `routes/api.php`:
```php
if (\Kurt\Modules\AuthKit\Facades\AuthKit::feature('registration')) {
    Route::post('register', [\Kurt\Modules\AuthKit\Http\Controllers\RegisterController::class, 'register'])->name('auth-kit.register.attempt');
}
```

(No provider change needed — the route files are already required per `HttpMode` from M2. The `feature('registration')` check runs at boot when the file is required.)

- [ ] **Step 6: Write `resources/views/auth/register.blade.php`**

```blade
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Register</title></head>
<body>
    <form method="POST" action="{{ route('auth-kit.register.attempt') }}">
        @csrf
        @foreach (['name', 'email', 'password'] as $f)
            @error($f)<p role="alert">{{ $message }}</p>@enderror
        @endforeach
        <label>Name <input type="text" name="name" value="{{ old('name') }}" required></label>
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required></label>
        <label>Password <input type="password" name="password" required></label>
        <label>Confirm <input type="password" name="password_confirmation" required></label>
        <button type="submit">Register</button>
    </form>
</body>
</html>
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/RegisterTest.php tests/Modes/Api/ApiRegisterTest.php tests/Modes/Ui/RegistrationDisabledTest.php`
Expected: PASS. (The disabled-routes test needs a mode case whose `defineEnvironment` sets `auth-kit.http.mode=ui` AND `auth-kit.features.registration=false`; add it alongside M2's mode cases.)

- [ ] **Step 8: Full suite + static analysis**

Run: `"$PHP84" vendor/bin/pest` → all green (M1 + M2 + M3).
Run: `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G` → no errors.

- [ ] **Step 9: Commit**

```bash
git add src/Http resources/views/auth/register.blade.php routes tests/Modes
git commit -m "feat: dual-mode registration gated by the registration feature flag"
```

---

## Done Criteria

- `feature('registration')` on + `HttpMode=ui`: `GET/POST register` create a user (hashed password, configured fields only), fire `Registered`, auto-login (configurable), redirect. `api`: JSON `201 {data:{registered:true,id}}`. Feature off: routes absent. `headless`: no routes.
- User creation flows only through the rebindable `Registrar`; password confirmed + min length.
- Full Pest suite + PHPStan L8 green under PHP 8.4.

## Out of Scope (later milestones)

- Email verification flow (verify link/notification handling), password reset, password confirmation.
- Rate-limiting / lockout + `laravel-auth-events` journal.
- 2FA, sessions/devices, passwordless, token (Sanctum) API auth.
- After M3, auth-kit has register+login+logout — the first release-worthy cut; tag it then.
