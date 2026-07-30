# laravel-auth-kit M4 - Email Verification - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add email verification to `ozankurt/laravel-modules-auth-kit`, dual-mode, gated by the `email_verification` feature flag, reusing Laravel's built-in verification primitives. Builds on M1-M3 (register already fires `Registered`, which triggers the app's default verification-notification listener when the User model implements `MustVerifyEmail`).

**Architecture:** Three routes mirroring Laravel's own verification flow — a **notice** page ("check your email"), the **signed verify link** (`{id}/{hash}`), and a throttled **resend**. Verification reuses Laravel's framework request `Illuminate\Foundation\Auth\EmailVerificationRequest` (authorizes id+hash, `fulfill()` marks verified + fires `Verified`). A `auth-kit.verified` route-middleware alias (over Laravel's `EnsureEmailIsVerified`) lets host apps gate their own routes. Everything registers only when `feature('email_verification')` is on and `HttpMode` is `ui`/`api`.

**Tech Stack:** PHP 8.3+ (tests on Laragon 8.4), Laravel 12, `ozankurt/laravel-modules-core ^1.0`. Pest 3 + Testbench. Reuses M2/M3 harness (`tests/Modes/*`, `createUser()`, dual-mode `wantsJson()`).

## Global Constraints

- Package `ozankurt/laravel-modules-auth-kit`, namespace `Kurt\Modules\AuthKit\`, slug `auth-kit`, branch `main`. `declare(strict_types=1);` everywhere. No AI attribution.
- Security: verify link is **signed** + **throttled** (`throttle:6,1`); the id/hash are validated by `EmailVerificationRequest` (constant-time hash compare); resend + verify require `auth`. Never mark verified without a valid signature+hash.
- Gated by `feature('email_verification')` (routes absent when off) AND `HttpMode`. Verification only applies when the resolved User model implements `Illuminate\Contracts\Auth\MustVerifyEmail`; if it doesn't, the routes must not error — the notice simply reports "nothing to verify" / the middleware passes through.
- Build in `D:\Code\Projects\laravel-modules-auth-kit`. Toolchain `PHP84 = C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe`. STUDY M2/M3 first (dual-mode `wantsJson()`, mode test-case layout, `createUser()`, route-file-per-HttpMode wiring) and mirror it.

## File Structure

- `src/Http/Controllers/EmailVerificationController.php` - `notice` / `verify` / `resend`, dual-mode.
- `resources/views/auth/verify-email.blade.php` - notice page.
- `config/auth-kit.php` - MODIFY: `email_verification` block (`redirect_to`).
- `routes/web.php`, `routes/api.php` - MODIFY: verification routes gated by `feature('email_verification')`.
- `src/Providers/AuthKitServiceProvider.php` - MODIFY: alias route-middleware `auth-kit.verified`.
- Tests: `tests/Modes/Ui/EmailVerificationTest.php`, `tests/Modes/Api/ApiEmailVerificationTest.php`, plus test-infra: a `MustVerifyEmail` test user + `email_verified_at` column.

---

## Task 1: Verify + resend flow (routes, controller, config, test-infra)

**Files:**
- Create: `src/Http/Controllers/EmailVerificationController.php`
- Modify: `config/auth-kit.php`, `routes/web.php`, `routes/api.php`, `tests/TestCase.php` (test-infra)
- Test: `tests/Modes/Ui/EmailVerificationTest.php`, `tests/Modes/Api/ApiEmailVerificationTest.php`

**Interfaces:**
- Consumes: Laravel `EmailVerificationRequest`, Core `ApiController` + `HttpMode`, `AuthKit` facade, M2/M3 dual-mode `wantsJson()`.
- Produces: routes `auth-kit.verification.notice` (GET, ui), `auth-kit.verification.verify` (GET `{id}/{hash}`, signed+throttled), `auth-kit.verification.send` (POST, throttled). `EmailVerificationController::notice()/verify(EmailVerificationRequest)/resend(Request)`.

- [ ] **Step 1: Add test-infra for MustVerifyEmail**

The M2/M3 `AuthKitTestUser` extends `Illuminate\Foundation\Auth\User`. Verification needs a user implementing `Illuminate\Contracts\Auth\MustVerifyEmail`. In `tests/TestCase.php` (or a new fixture): ensure the shared `users` table has a nullable `email_verified_at` timestamp column, and provide a verifiable user — either make `AuthKitTestUser implements MustVerifyEmail use \Illuminate\Auth\MustVerifyEmail` (the trait), or add a `AuthKitVerifiableUser` fixture and point `auth.providers.users.model` at it for these tests. Keep `createUser()` able to set `email_verified_at` (default null = unverified). This is test infrastructure, not `src/`.

- [ ] **Step 2: Write the failing tests**

`tests/Modes/Ui/EmailVerificationTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

it('verifies the email via a valid signed link and fires Verified', function () {
    Event::fake([Verified::class]);
    $user = createUser(['email' => 'a@b.com', 'email_verified_at' => null]);
    $this->actingAs($user);

    $url = URL::temporarySignedRoute('auth-kit.verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->get($url)->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

it('rejects a tampered hash', function () {
    $user = createUser(['email' => 'a@b.com', 'email_verified_at' => null]);
    $this->actingAs($user);

    $url = URL::temporarySignedRoute('auth-kit.verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1('someone-elses-email'),
    ]);

    $this->get($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends the verification notification', function () {
    \Illuminate\Support\Facades\Notification::fake();
    $user = createUser(['email' => 'a@b.com', 'email_verified_at' => null]);
    $this->actingAs($user);

    $this->post(route('auth-kit.verification.send'))->assertRedirect();

    \Illuminate\Support\Facades\Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
});
```

`tests/Modes/Api/ApiEmailVerificationTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\URL;

it('verifies via the API and returns a JSON envelope', function () {
    $user = createUser(['email' => 'a@b.com', 'email_verified_at' => null]);
    $this->actingAs($user);

    $url = URL::temporarySignedRoute('auth-kit.verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->getJson($url)->assertOk()->assertJsonPath('data.verified', true);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/EmailVerificationTest.php`
Expected: FAIL — routes undefined.

- [ ] **Step 4: Add the `email_verification` config block to `config/auth-kit.php`**

```php
    'email_verification' => [
        'redirect_to' => '/',   // where a successful verification / already-verified lands
    ],
```

- [ ] **Step 5: Write `src/Http/Controllers/EmailVerificationController.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Http\Controllers;

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Kurt\Modules\Core\Http\Controllers\ApiController;
use Kurt\Modules\Core\Http\HttpMode;

final class EmailVerificationController extends ApiController
{
    public function notice(Request $request): View|JsonResponse|RedirectResponse
    {
        if ($this->verified($request)) {
            return $this->wantsJson($request)
                ? $this->respond(['verified' => true])
                : redirect()->intended(config('auth-kit.email_verification.redirect_to', '/'));
        }

        return $this->wantsJson($request)
            ? $this->respond(['verified' => false])
            : view('auth-kit::auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse|JsonResponse
    {
        // EmailVerificationRequest::authorize() already validated id + hash (403 otherwise).
        if (! $request->user()->hasVerifiedEmail()) {
            $request->fulfill(); // marks verified + fires Verified
        }

        return $this->wantsJson($request)
            ? $this->respond(['verified' => true])
            : redirect()->intended(config('auth-kit.email_verification.redirect_to', '/'));
    }

    public function resend(Request $request): RedirectResponse|JsonResponse
    {
        if (! $this->verified($request)) {
            $request->user()->sendEmailVerificationNotification();
        }

        return $this->wantsJson($request)
            ? $this->respondNoContent()
            : back()->with('status', 'verification-link-sent');
    }

    private function verified(Request $request): bool
    {
        $user = $request->user();

        return ! $user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail || $user->hasVerifiedEmail();
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || HttpMode::forModule('auth-kit') === HttpMode::Api;
    }
}
```

- [ ] **Step 6: Add verification routes to `routes/web.php` and `routes/api.php` (gated)**

`routes/web.php` (inside the web group):
```php
if (\Kurt\Modules\AuthKit\Facades\AuthKit::feature('email_verification')) {
    $c = \Kurt\Modules\AuthKit\Http\Controllers\EmailVerificationController::class;
    Route::get('verify-email', [$c, 'notice'])->middleware('auth')->name('auth-kit.verification.notice');
    Route::get('verify-email/{id}/{hash}', [$c, 'verify'])->middleware(['auth', 'signed', 'throttle:6,1'])->name('auth-kit.verification.verify');
    Route::post('email/verification-notification', [$c, 'resend'])->middleware(['auth', 'throttle:6,1'])->name('auth-kit.verification.send');
}
```

`routes/api.php`:
```php
if (\Kurt\Modules\AuthKit\Facades\AuthKit::feature('email_verification')) {
    $c = \Kurt\Modules\AuthKit\Http\Controllers\EmailVerificationController::class;
    Route::get('verify-email/{id}/{hash}', [$c, 'verify'])->middleware(['auth', 'signed', 'throttle:6,1'])->name('auth-kit.verification.verify');
    Route::post('email/verification-notification', [$c, 'resend'])->middleware(['auth', 'throttle:6,1'])->name('auth-kit.verification.send');
}
```

(Note: the API file must not double-register the `auth-kit.verification.verify` name if web is also loaded — only ONE HttpMode's routes load per boot, so there is no collision.)

- [ ] **Step 7: Run tests to verify they pass**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/EmailVerificationTest.php tests/Modes/Api/ApiEmailVerificationTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/EmailVerificationController.php config/auth-kit.php routes tests
git commit -m "feat: dual-mode email verification (verify link + resend)"
```

---

## Task 2: Notice view + `auth-kit.verified` middleware alias + suite

**Files:**
- Create: `resources/views/auth/verify-email.blade.php`
- Modify: `src/Providers/AuthKitServiceProvider.php` (alias middleware)
- Test: `tests/Modes/Ui/VerifiedMiddlewareTest.php`

**Interfaces:**
- Produces: view `auth-kit::auth.verify-email`; route-middleware alias `auth-kit.verified` (over Laravel's `Illuminate\Auth\Middleware\EnsureEmailIsVerified`, redirecting unverified users to `auth-kit.verification.notice`).

- [ ] **Step 1: Write the failing test `tests/Modes/Ui/VerifiedMiddlewareTest.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('_protected', fn () => 'ok')->middleware(['web', 'auth', 'auth-kit.verified']);
});

it('redirects an unverified user away from a verified-only route', function () {
    $this->actingAs(createUser(['email_verified_at' => null]));
    $this->get('_protected')->assertRedirect(route('auth-kit.verification.notice'));
});

it('lets a verified user through', function () {
    $this->actingAs(createUser(['email_verified_at' => now()]));
    $this->get('_protected')->assertOk()->assertSee('ok');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/VerifiedMiddlewareTest.php`
Expected: FAIL — middleware alias `auth-kit.verified` undefined.

- [ ] **Step 3: Register the alias in `AuthKitServiceProvider::packageBooted()`**

After the existing route/view wiring (only meaningful when the feature is on, but the alias is harmless to register always):

```php
$this->app->make(\Illuminate\Routing\Router::class)
    ->aliasMiddleware('auth-kit.verified', \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class.':auth-kit.verification.notice');
```

(The `:route-name` parameter makes `EnsureEmailIsVerified` redirect unverified users to our notice route.)

- [ ] **Step 4: Write `resources/views/auth/verify-email.blade.php`**

```blade
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Verify your email</title></head>
<body>
    <p>Please verify your email address by clicking the link we just emailed you.</p>
    @if (session('status') === 'verification-link-sent')
        <p role="status">A new verification link has been sent.</p>
    @endif
    <form method="POST" action="{{ route('auth-kit.verification.send') }}">
        @csrf
        <button type="submit">Resend verification email</button>
    </form>
</body>
</html>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/VerifiedMiddlewareTest.php`
Expected: PASS.

- [ ] **Step 6: Full suite + static analysis**

Run: `"$PHP84" vendor/bin/pest` → all green (M1-M3 + M4).
Run: `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G` → no errors (reuse the existing `view-string` ignore precedent for the new controller if larastan flags the runtime `auth-kit::` view).

- [ ] **Step 7: Commit**

```bash
git add resources/views/auth/verify-email.blade.php src/Providers/AuthKitServiceProvider.php tests/Modes/Ui/VerifiedMiddlewareTest.php
git commit -m "feat: verify-email notice view and auth-kit.verified middleware alias"
```

---

## Done Criteria

- `feature('email_verification')` on: a valid signed `{id}/{hash}` link marks the user verified + fires `Verified` (dual-mode); a tampered hash is 403; resend re-sends the notification (throttled); the notice page + `auth-kit.verified` middleware work. Feature off: routes absent. Non-`MustVerifyEmail` user: no errors (notice reports verified / middleware passes).
- Verify link stays signed + throttled; verification never happens without a valid signature+hash.
- Full Pest suite + PHPStan L8 green under PHP 8.4.

## Out of Scope (later milestones)

- Password reset, password confirmation.
- Rate-limiting/lockout + `laravel-auth-events` journal; 2FA; sessions/devices; passwordless; Sanctum token API auth.
- Release: fold M4 into the next auth-kit minor (`v1.1.0`) once password reset (M5) also lands, or tag `v1.1.0` after M4 alone — decide at release time.
