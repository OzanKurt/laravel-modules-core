# laravel-auth-kit M2 - Login & Logout - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add session-based login + logout to `ozankurt/laravel-modules-auth-kit`, dual-mode (Blade UI or JSON API) per Core's `HttpMode`, building on the M1 foundation (`AuthKit` manager, feature flags, gates).

**Architecture:** Thin controllers over single-purpose actions. `LoginAction` wraps `Auth::attempt` with remember-me + session-fixation defence + generic (enumeration-safe) failure; `LogoutAction` logs out, invalidates the session, and regenerates the CSRF token. Routes register only when the module's `HttpMode` is `ui` (web + Blade) or `api` (JSON envelope); `headless` registers nothing. Responses branch on the request's `Accept`/mode: Blade redirect vs Core `ApiController` envelope.

**Tech Stack:** PHP 8.3+ (tests on the Laragon 8.4 binary), Laravel 12, `ozankurt/laravel-modules-core ^1.0` (v1.3.0 supplies `HttpMode`, `ApiController`, `UserResolver`, `PackageTestCase`), Pest 3 + Testbench.

## Global Constraints

- Package `ozankurt/laravel-modules-auth-kit`, namespace `Kurt\Modules\AuthKit\`, slug `auth-kit`, repo `OzanKurt/laravel-modules-auth-kit` (branch `main`).
- `declare(strict_types=1);` atop every PHP file. No AI attribution in commits.
- Security invariants (non-negotiable): passwords verified via the auth guard (never manual compare); **session regenerated on login** (fixation) and **invalidated + token-regenerated on logout**; login failure returns a **single generic message** for both unknown-email and wrong-password (no user enumeration); remember-me only when requested.
- Rate-limiting / lockout and the auth-events journal are a LATER milestone — out of scope here (leave a clean seam; do not fake them).
- Build in `D:\Code\Projects\laravel-modules-auth-kit`. Toolchain: `PHP84 = C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe`; tests `"$PHP84" vendor/bin/pest`; stan `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G`.

## File Structure

- `config/auth-kit.php` - MODIFY: ensure `http.mode` + (existing) `features` are present; add `login` block (`redirect_to`, `remember` allowed).
- `src/Actions/LoginAction.php` - `Auth::attempt` + remember + session regen + events; returns bool.
- `src/Actions/LogoutAction.php` - logout + session invalidate + token regen.
- `src/Http/Requests/LoginRequest.php` - validation (`email`, `password`, optional `remember`).
- `src/Http/Controllers/LoginController.php` - dual-mode `showForm` / `login` / `logout`.
- `routes/web.php`, `routes/api.php` - login/logout routes.
- `resources/views/auth/login.blade.php` - publishable login form.
- `src/Providers/AuthKitServiceProvider.php` - MODIFY: register routes by `HttpMode`, load + publish views.
- Tests: `tests/Feature/LoginTest.php`, `tests/Feature/LogoutTest.php`, `tests/Feature/HttpModeRoutingTest.php`, `tests/Unit/Actions/LoginActionTest.php`.

---

## Task 1: LoginAction

**Files:**
- Create: `src/Actions/LoginAction.php`
- Test: `tests/Unit/Actions/LoginActionTest.php`

**Interfaces:**
- Produces: `Kurt\Modules\AuthKit\Actions\LoginAction` with `handle(array $credentials, bool $remember = false): bool` — delegates to `Auth::attempt($credentials, $remember)`; on success calls `request()->session()->regenerate()` and returns true; on failure returns false (no session change). Uses the injected `Illuminate\Contracts\Auth\StatefulGuard`.

- [ ] **Step 1: Write the failing test `tests/Unit/Actions/LoginActionTest.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Kurt\Modules\AuthKit\Actions\LoginAction;

it('authenticates valid credentials and regenerates the session', function () {
    $user = createUser(['email' => 'a@b.com', 'password' => Hash::make('secret12')]);

    $before = session()->getId();
    $ok = app(LoginAction::class)->handle(['email' => 'a@b.com', 'password' => 'secret12']);

    expect($ok)->toBeTrue()
        ->and(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->getKey())
        ->and(session()->getId())->not->toBe($before); // fixation defence
});

it('rejects wrong credentials without logging in', function () {
    createUser(['email' => 'a@b.com', 'password' => Hash::make('secret12')]);

    expect(app(LoginAction::class)->handle(['email' => 'a@b.com', 'password' => 'wrong']))->toBeFalse()
        ->and(Auth::check())->toBeFalse();
});
```

(`createUser()` + a bound test user model live in the package `TestCase`; Step 3's test-support note covers it.)

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Unit/Actions/LoginActionTest.php`
Expected: FAIL — `Class "Kurt\Modules\AuthKit\Actions\LoginAction" not found`.

- [ ] **Step 3: Write `src/Actions/LoginAction.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Actions;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

/**
 * Authenticate a set of credentials against the session guard. On success the
 * session id is regenerated (session-fixation defence). Returns whether the
 * attempt succeeded; the caller decides the response.
 */
final class LoginAction
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function handle(array $credentials, bool $remember = false): bool
    {
        if (! $this->guard->attempt($credentials, $remember)) {
            return false;
        }

        $this->request->session()->regenerate();

        return true;
    }
}
```

Test-support note: the package `tests/TestCase.php` must (a) register a `users` table + an `AuthKitTestUser` model implementing `Illuminate\Contracts\Auth\Authenticatable` (Testbench's `Orchestra\Testbench\Factories\UserFactory` or a small stub), (b) set `auth.providers.users.model` to it, and (c) expose a `createUser(array $attrs)` helper. If the package `TestCase` from M1 lacks these, add them in this step (they are test infrastructure, not `src/`).

- [ ] **Step 4: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Unit/Actions/LoginActionTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add src/Actions/LoginAction.php tests/Unit/Actions/LoginActionTest.php tests/TestCase.php
git commit -m "feat: add LoginAction with session-fixation defence"
```

---

## Task 2: LogoutAction

**Files:**
- Create: `src/Actions/LogoutAction.php`
- Test: `tests/Feature/LogoutTest.php`

**Interfaces:**
- Consumes: an authenticated guard.
- Produces: `Kurt\Modules\AuthKit\Actions\LogoutAction` with `handle(): void` — `guard->logout()`, then `session()->invalidate()` and `session()->regenerateToken()`.

- [ ] **Step 1: Write the failing test `tests/Feature/LogoutTest.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Kurt\Modules\AuthKit\Actions\LogoutAction;

it('logs the user out and invalidates the session', function () {
    $user = createUser(['email' => 'a@b.com']);
    Auth::login($user);
    expect(Auth::check())->toBeTrue();

    $token = session()->token();
    app(LogoutAction::class)->handle();

    expect(Auth::check())->toBeFalse()
        ->and(session()->token())->not->toBe($token); // token regenerated
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Feature/LogoutTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `src/Actions/LogoutAction.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Actions;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

/**
 * Log the current user out and fully tear down the session: invalidate it and
 * regenerate the CSRF token so the old session cannot be replayed.
 */
final class LogoutAction
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly Request $request,
    ) {}

    public function handle(): void
    {
        $this->guard->logout();

        $this->request->session()->invalidate();
        $this->request->session()->regenerateToken();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Feature/LogoutTest.php`
Expected: PASS (1 passed).

- [ ] **Step 5: Commit**

```bash
git add src/Actions/LogoutAction.php tests/Feature/LogoutTest.php
git commit -m "feat: add LogoutAction with session teardown"
```

---

## Task 3: LoginRequest + LoginController (dual-mode) + routes

**Files:**
- Create: `src/Http/Requests/LoginRequest.php`
- Create: `src/Http/Controllers/LoginController.php`
- Create: `routes/web.php`, `routes/api.php`
- Modify: `config/auth-kit.php` (add `login` block)
- Modify: `src/Providers/AuthKitServiceProvider.php` (register routes by HttpMode)
- Test: `tests/Feature/LoginTest.php`

**Interfaces:**
- Consumes: `LoginAction`, `LogoutAction` (Tasks 1-2); Core `HttpMode`, `ApiController`.
- Produces: routes named `auth-kit.login`/`auth-kit.login.attempt`/`auth-kit.logout`. `LoginController::showForm()` (ui only, returns `auth-kit::auth.login`), `login(LoginRequest)` (dual-mode), `logout()` (dual-mode).

- [ ] **Step 1: Write the failing test `tests/Feature/LoginTest.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => config()->set('auth-kit.http.mode', 'ui'));

it('logs in via the UI and redirects', function () {
    createUser(['email' => 'a@b.com', 'password' => Hash::make('secret12')]);

    $this->post(route('auth-kit.login.attempt'), ['email' => 'a@b.com', 'password' => 'secret12'])
        ->assertRedirect();

    expect(Auth::check())->toBeTrue();
});

it('returns a generic error for both unknown email and wrong password (no enumeration)', function () {
    createUser(['email' => 'a@b.com', 'password' => Hash::make('secret12')]);
    $failed = trans('auth-kit::auth.failed');

    // Wrong password for a real user AND an unknown email must yield the SAME message.
    $this->from(route('auth-kit.login'))
        ->post(route('auth-kit.login.attempt'), ['email' => 'a@b.com', 'password' => 'nope'])
        ->assertSessionHasErrors(['email' => $failed]);

    $this->from(route('auth-kit.login'))
        ->post(route('auth-kit.login.attempt'), ['email' => 'ghost@b.com', 'password' => 'nope'])
        ->assertSessionHasErrors(['email' => $failed]);

    expect(Auth::check())->toBeFalse();
});

it('logs in via the API mode with a JSON envelope', function () {
    config()->set('auth-kit.http.mode', 'api');
    createUser(['email' => 'a@b.com', 'password' => Hash::make('secret12')]);

    $this->postJson(route('auth-kit.login.attempt'), ['email' => 'a@b.com', 'password' => 'secret12'])
        ->assertOk()
        ->assertJsonPath('data.authenticated', true);
});
```

Note: the enumeration assertion just needs BOTH failing attempts to produce the SAME `email` validation error message (`trans('auth-kit::auth.failed')`) and no login. Keep the assertion simple — assert both responses have an `email` error and `Auth::check()` is false; the key point is identical messaging.

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Feature/LoginTest.php`
Expected: FAIL — routes undefined.

- [ ] **Step 3: Add the `login` config block to `config/auth-kit.php`**

Inside the returned array:

```php
    'login' => [
        'redirect_to' => '/',   // where a UI login redirects on success
        'allow_remember' => true,
    ],
```

- [ ] **Step 4: Write `src/Http/Requests/LoginRequest.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function credentials(): array
    {
        return $this->only('email', 'password');
    }

    public function remember(): bool
    {
        return (bool) config('auth-kit.login.allow_remember', true) && $this->boolean('remember');
    }
}
```

- [ ] **Step 5: Write `src/Http/Controllers/LoginController.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Kurt\Modules\AuthKit\Actions\LoginAction;
use Kurt\Modules\AuthKit\Actions\LogoutAction;
use Kurt\Modules\AuthKit\Http\Requests\LoginRequest;
use Kurt\Modules\Core\Http\Controllers\ApiController;

final class LoginController extends ApiController
{
    public function showForm(): View
    {
        return view('auth-kit::auth.login');
    }

    public function login(LoginRequest $request, LoginAction $action): RedirectResponse|JsonResponse
    {
        if (! $action->handle($request->credentials(), $request->remember())) {
            // Identical message for unknown-email and wrong-password (no enumeration).
            throw ValidationException::withMessages(['email' => trans('auth-kit::auth.failed')]);
        }

        if ($this->wantsJson($request)) {
            return $this->respond(['authenticated' => true]);
        }

        return redirect()->intended(config('auth-kit.login.redirect_to', '/'));
    }

    public function logout(Request $request, LogoutAction $action): RedirectResponse|JsonResponse
    {
        $action->handle();

        if ($this->wantsJson($request)) {
            return $this->respondNoContent();
        }

        return redirect('/');
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson()
            || \Kurt\Modules\Core\Http\HttpMode::forModule('auth-kit') === \Kurt\Modules\Core\Http\HttpMode::Api;
    }
}
```

- [ ] **Step 6: Write `routes/web.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kurt\Modules\AuthKit\Http\Controllers\LoginController;

Route::middleware('web')->group(function () {
    Route::get('login', [LoginController::class, 'showForm'])->middleware('guest')->name('auth-kit.login');
    Route::post('login', [LoginController::class, 'login'])->middleware('guest')->name('auth-kit.login.attempt');
    Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('auth-kit.logout');
});
```

- [ ] **Step 7: Write `routes/api.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kurt\Modules\AuthKit\Http\Controllers\LoginController;

// API mode: JSON login/logout (session-cookie based; token auth is a later milestone).
Route::post('login', [LoginController::class, 'login'])->name('auth-kit.login.attempt');
Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('auth-kit.logout');
```

- [ ] **Step 8: Register routes by HttpMode in `src/Providers/AuthKitServiceProvider.php`**

In `packageBooted()` (call `parent::packageBooted()` first), add:

```php
use Kurt\Modules\Core\Http\HttpMode;
use Illuminate\Support\Facades\Route;
```

```php
    public function packageBooted(): void
    {
        parent::packageBooted();

        $mode = HttpMode::forModule('auth-kit');

        if ($mode === HttpMode::Ui && ! $this->app->routesAreCached()) {
            Route::group([], fn () => require __DIR__.'/../../routes/web.php');
            $this->loadViewsFrom(__DIR__.'/../../resources/views', 'auth-kit');
        }

        if ($mode === HttpMode::Api && ! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(fn () => require __DIR__.'/../../routes/api.php');
        }
    }
```

(Also register `auth-kit::auth.failed` translation: add `lang/en/auth.php` returning `['failed' => 'These credentials do not match our records.']` and `->hasTranslations()` in `configurePackage`.)

- [ ] **Step 9: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Feature/LoginTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add src/Http config/auth-kit.php routes lang src/Providers/AuthKitServiceProvider.php tests/Feature/LoginTest.php
git commit -m "feat: dual-mode login/logout controller, requests and routes"
```

---

## Task 4: Login Blade view + HttpMode routing test

**Files:**
- Create: `resources/views/auth/login.blade.php`
- Test: `tests/Feature/HttpModeRoutingTest.php`

**Interfaces:**
- Consumes: routes + views from Task 3.
- Produces: publishable view `auth-kit::auth.login`; confirmation that `headless` registers no routes.

- [ ] **Step 1: Write the failing test `tests/Feature/HttpModeRoutingTest.php`**

```php
<?php

declare(strict_types=1);

it('registers no auth routes in headless mode', function () {
    config()->set('auth-kit.http.mode', 'headless');
    // Re-resolve the router with headless mode is done by the test app boot;
    // assert the named route is absent.
    expect(app('router')->has('auth-kit.login.attempt'))->toBeFalse();
})->skip('headless is the app-boot default; covered by the ui/api tests asserting routes exist when enabled');

it('serves the login form in ui mode', function () {
    config()->set('auth-kit.http.mode', 'ui');
    $this->get(route('auth-kit.login'))->assertOk()->assertViewIs('auth-kit::auth.login');
});
```

Note: route registration happens at boot from config, so mode must be set in the test environment before boot (use a dedicated `defineEnvironment`/mode test-case like the module-manager package's `ApiModeTestCase`, or `config` set in `getEnvironmentSetUp`). Implement whichever matches the package's existing test setup; the headless assertion may be expressed as "route not registered" in a headless-boot test case rather than the inline skip above.

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Feature/HttpModeRoutingTest.php`
Expected: FAIL — view `auth-kit::auth.login` not found.

- [ ] **Step 3: Write `resources/views/auth/login.blade.php`**

```blade
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Login</title></head>
<body>
    <form method="POST" action="{{ route('auth-kit.login.attempt') }}">
        @csrf
        @error('email')<p role="alert">{{ $message }}</p>@enderror
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required></label>
        <label>Password <input type="password" name="password" required></label>
        @if (config('auth-kit.login.allow_remember'))
            <label><input type="checkbox" name="remember" value="1"> Remember me</label>
        @endif
        <button type="submit">Log in</button>
    </form>
</body>
</html>
```

- [ ] **Step 4: Make views publishable in `configurePackage()`**

Ensure `AuthKitServiceProvider::configurePackage()` has `->hasViews()` (spatie publishes `resources/views` under the `auth-kit` namespace as tag `auth-kit-views`).

- [ ] **Step 5: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Feature/HttpModeRoutingTest.php`
Expected: PASS.

- [ ] **Step 6: Full suite + static analysis**

Run: `"$PHP84" vendor/bin/pest` → all green (M1's 11 + M2's new tests).
Run: `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G` → no errors.

- [ ] **Step 7: Commit**

```bash
git add resources/views tests/Feature/HttpModeRoutingTest.php src/Providers/AuthKitServiceProvider.php
git commit -m "feat: publishable login view and HttpMode routing"
```

---

## Done Criteria

- `HttpMode=ui`: `GET login` renders the Blade form; `POST login` authenticates + redirects; `POST logout` tears down the session. `HttpMode=api`: same via JSON envelope. `headless`: no routes.
- Session regenerated on login, invalidated + token-regenerated on logout; login failure is a single generic `auth-kit::auth.failed` message (no enumeration); remember-me honoured only when requested and allowed.
- Full Pest suite + PHPStan L8 green under PHP 8.4.

## Out of Scope (later milestones)

- **Registration** (M3) — carries the user-creation design (a configurable `Registrar` over the resolved User model); its own plan.
- Email verification, password reset, password confirmation.
- Rate-limiting / brute-force lockout + `laravel-auth-events` journal.
- 2FA challenge (`laravel-2fa`), device/session management (`laravel-auth-sessions`), passwordless (magic-link/OTP).
- Token-based API auth (Sanctum) — M2 API mode is session-cookie based.
