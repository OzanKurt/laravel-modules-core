# laravel-auth-kit M5 - Password Reset - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add forgot-password + reset-password to `ozankurt/laravel-modules-auth-kit`, dual-mode, gated by the `password_reset` feature flag, on top of M1-M4 (released v1.1.0).

**Architecture:** Two flows over Laravel's own `Password` broker (`Illuminate\Support\Facades\Password`), so tokens, expiry, and hashing come from the framework rather than being reinvented. **Forgot**: accept an email, call `Password::sendResetLink()`, and ALWAYS respond with the same generic success message (no account enumeration). **Reset**: validate `token` + `email` + confirmed password, call `Password::reset()` which verifies the token, sets the hashed password, fires `PasswordReset`, and regenerates the remember token. Controllers mirror M2-M4's dual-mode `wantsJson()` pattern; routes register only when the feature flag is on and `HttpMode` is `ui`/`api`.

**Tech Stack:** PHP 8.3+ (tests on Laragon 8.4), Laravel 12, `ozankurt/laravel-modules-core ^1.0` (v1.3.0). Pest 3 + Testbench. Reuses M2-M4 harness (`tests/Modes/*` mode cases, `createUser()`, `AuthKitTestUser`).

## Global Constraints

- Package `ozankurt/laravel-modules-auth-kit`, namespace `Kurt\Modules\AuthKit\`, slug `auth-kit`, branch `main`. `declare(strict_types=1);` everywhere. No AI attribution in commits.
- **Security (non-negotiable):**
  - **No enumeration on forgot-password:** a known email and an unknown email must produce the SAME response (same status message, same HTTP code). Never leak whether the account exists.
  - Reset tokens come from Laravel's broker (hashed at rest, expiring); never hand-roll token generation or comparison.
  - New password requires `confirmed` + `min:8`; stored via the hasher (the broker's callback uses `Hash::make`).
  - Both endpoints throttled (`throttle:6,1`) to blunt brute-force/spam.
- Gated by `AuthKit::feature('password_reset')` (routes absent when off) AND `HttpMode` (`headless` = no routes).
- Requires the framework's `password_reset_tokens` table; the package does NOT ship that migration (it is a host-app/Laravel concern). Tests must create it (Testbench: `$this->loadLaravelMigrations()` or an explicit schema call in the test harness).
- Build in `D:\Code\Projects\laravel-modules-auth-kit`. Toolchain `PHP84 = C:/laragon/bin/php/php-8.4.5-nts-Win32-vs17-x64/php.exe`. STUDY M2-M4 first (dual-mode `wantsJson()`, mode test cases, feature-gated route blocks) and mirror it.

## File Structure

- `src/Http/Requests/ForgotPasswordRequest.php` - email validation.
- `src/Http/Requests/ResetPasswordRequest.php` - token/email/password validation.
- `src/Http/Controllers/PasswordResetController.php` - `showLinkForm` / `sendLink` / `showResetForm` / `reset`, dual-mode.
- `resources/views/auth/forgot-password.blade.php`, `resources/views/auth/reset-password.blade.php`.
- `config/auth-kit.php` - MODIFY: `password_reset` block (`redirect_to`).
- `routes/web.php`, `routes/api.php` - MODIFY: routes gated by `feature('password_reset')`.
- Tests: `tests/Modes/Ui/PasswordResetTest.php`, `tests/Modes/Api/ApiPasswordResetTest.php`, plus a feature-off mode case.

---

## Task 1: Forgot password (send reset link, enumeration-safe)

**Files:**
- Create: `src/Http/Requests/ForgotPasswordRequest.php`, `src/Http/Controllers/PasswordResetController.php`
- Modify: `config/auth-kit.php`, `routes/web.php`, `routes/api.php`, test harness (password_reset_tokens table)
- Test: `tests/Modes/Ui/PasswordResetTest.php` (forgot half), `tests/Modes/Api/ApiPasswordResetTest.php` (forgot half)

**Interfaces:**
- Consumes: Laravel `Password` facade, Core `ApiController`/`HttpMode`, `AuthKit` facade.
- Produces: routes `auth-kit.password.request` (GET, ui), `auth-kit.password.email` (POST). `PasswordResetController::showLinkForm(): View` and `sendLink(ForgotPasswordRequest): RedirectResponse|JsonResponse`.

- [ ] **Step 1: Ensure the reset-token table exists in tests**

In `tests/TestCase.php::defineDatabaseMigrations()` (which already creates `users` and `email_verified_at`), also create the framework table so the broker works:

```php
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
```

(Test infrastructure only; the package ships no such migration.)

- [ ] **Step 2: Write the failing tests**

`tests/Modes/Ui/PasswordResetTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('sends a reset link for a known email', function () {
    Notification::fake();
    $user = createUser(['email' => 'a@b.com']);

    $this->post(route('auth-kit.password.email'), ['email' => 'a@b.com'])
        ->assertRedirect()
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
});

it('responds identically for an unknown email (no enumeration)', function () {
    Notification::fake();
    createUser(['email' => 'a@b.com']);

    $known = $this->post(route('auth-kit.password.email'), ['email' => 'a@b.com']);
    $unknown = $this->post(route('auth-kit.password.email'), ['email' => 'ghost@b.com']);

    expect($unknown->getStatusCode())->toBe($known->getStatusCode());
    $unknown->assertSessionHasNoErrors();
    $unknown->assertSessionHas('status');
});
```

`tests/Modes/Api/ApiPasswordResetTest.php` (forgot half):
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;

it('returns the same JSON envelope for known and unknown emails', function () {
    Notification::fake();
    createUser(['email' => 'a@b.com']);

    $known = $this->postJson(route('auth-kit.password.email'), ['email' => 'a@b.com'])->assertOk();
    $unknown = $this->postJson(route('auth-kit.password.email'), ['email' => 'ghost@b.com'])->assertOk();

    expect($unknown->json())->toBe($known->json());
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/PasswordResetTest.php`
Expected: FAIL — route `auth-kit.password.email` undefined.

- [ ] **Step 4: Add the `password_reset` config block to `config/auth-kit.php`**

```php
    'password_reset' => [
        'redirect_to' => '/',   // where a successful reset lands (UI)
    ],
```

- [ ] **Step 5: Write `src/Http/Requests/ForgotPasswordRequest.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Deliberately NO `exists` rule: that would leak whether the account exists.
        return ['email' => ['required', 'string', 'email']];
    }
}
```

- [ ] **Step 6: Write `src/Http/Controllers/PasswordResetController.php` (forgot half)**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Kurt\Modules\AuthKit\Http\Requests\ForgotPasswordRequest;
use Kurt\Modules\Core\Http\Controllers\ApiController;
use Kurt\Modules\Core\Http\HttpMode;

final class PasswordResetController extends ApiController
{
    public function showLinkForm(): View
    {
        return view('auth-kit::auth.forgot-password');
    }

    /**
     * Always reports the same generic outcome, whether or not the address
     * belongs to an account: revealing the difference would let an attacker
     * enumerate registered emails.
     */
    public function sendLink(ForgotPasswordRequest $request): RedirectResponse|JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        $status = trans('auth-kit::auth.password_reset_sent');

        return $this->wantsJson($request)
            ? $this->respond(['status' => $status])
            : back()->with('status', $status);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || HttpMode::forModule('auth-kit') === HttpMode::Api;
    }
}
```

Add to `lang/en/auth.php`: `'password_reset_sent' => 'If that email address exists in our system, we have sent a password reset link.'` and `'password_reset_success' => 'Your password has been reset.'` (used in Task 2).

- [ ] **Step 7: Add forgot routes to `routes/web.php` and `routes/api.php` (gated)**

`routes/web.php` (inside the web group):
```php
if (\Kurt\Modules\AuthKit\Facades\AuthKit::feature('password_reset')) {
    $p = \Kurt\Modules\AuthKit\Http\Controllers\PasswordResetController::class;
    Route::get('forgot-password', [$p, 'showLinkForm'])->middleware('guest')->name('auth-kit.password.request');
    Route::post('forgot-password', [$p, 'sendLink'])->middleware(['guest', 'throttle:6,1'])->name('auth-kit.password.email');
}
```

`routes/api.php`:
```php
if (\Kurt\Modules\AuthKit\Facades\AuthKit::feature('password_reset')) {
    $p = \Kurt\Modules\AuthKit\Http\Controllers\PasswordResetController::class;
    Route::post('forgot-password', [$p, 'sendLink'])->middleware('throttle:6,1')->name('auth-kit.password.email');
}
```

- [ ] **Step 8: Write `resources/views/auth/forgot-password.blade.php`**

```blade
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Forgot password</title></head>
<body>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    <form method="POST" action="{{ route('auth-kit.password.email') }}">
        @csrf
        @error('email')<p role="alert">{{ $message }}</p>@enderror
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required></label>
        <button type="submit">Email password reset link</button>
    </form>
</body>
</html>
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/PasswordResetTest.php tests/Modes/Api/ApiPasswordResetTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add src/Http config/auth-kit.php routes lang resources/views/auth/forgot-password.blade.php tests
git commit -m "feat: enumeration-safe forgot-password flow"
```

---

## Task 2: Reset password (consume token, set new password)

**Files:**
- Create: `src/Http/Requests/ResetPasswordRequest.php`, `resources/views/auth/reset-password.blade.php`
- Modify: `src/Http/Controllers/PasswordResetController.php`, `routes/web.php`, `routes/api.php`
- Test: `tests/Modes/Ui/PasswordResetTest.php` (reset half), `tests/Modes/Api/ApiPasswordResetTest.php` (reset half)

**Interfaces:**
- Consumes: Task 1's controller + `Password` broker.
- Produces: routes `auth-kit.password.reset` (GET `{token}`, ui) + `auth-kit.password.update` (POST). `PasswordResetController::showResetForm(Request, string $token): View` and `reset(ResetPasswordRequest): RedirectResponse|JsonResponse`.

- [ ] **Step 1: Write the failing tests (append to the same files)**

Append to `tests/Modes/Ui/PasswordResetTest.php`:
```php
it('resets the password with a valid token', function () {
    $user = createUser(['email' => 'a@b.com', 'password' => bcrypt('oldpass12')]);
    $token = Password::createToken($user);

    $this->post(route('auth-kit.password.update'), [
        'token' => $token,
        'email' => 'a@b.com',
        'password' => 'newpass12',
        'password_confirmation' => 'newpass12',
    ])->assertRedirect();

    expect(Hash::check('newpass12', $user->fresh()->password))->toBeTrue();
});

it('rejects an invalid token and leaves the password unchanged', function () {
    $user = createUser(['email' => 'a@b.com', 'password' => bcrypt('oldpass12')]);

    $this->from(route('auth-kit.password.request'))->post(route('auth-kit.password.update'), [
        'token' => 'not-a-real-token',
        'email' => 'a@b.com',
        'password' => 'newpass12',
        'password_confirmation' => 'newpass12',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('oldpass12', $user->fresh()->password))->toBeTrue();
});
```

(Add `use Illuminate\Support\Facades\Password;` and `use Illuminate\Support\Facades\Hash;` at the top of the file.)

Append to `tests/Modes/Api/ApiPasswordResetTest.php`:
```php
it('resets via the API and returns a JSON envelope', function () {
    $user = createUser(['email' => 'a@b.com', 'password' => bcrypt('oldpass12')]);
    $token = \Illuminate\Support\Facades\Password::createToken($user);

    $this->postJson(route('auth-kit.password.update'), [
        'token' => $token,
        'email' => 'a@b.com',
        'password' => 'newpass12',
        'password_confirmation' => 'newpass12',
    ])->assertOk()->assertJsonPath('data.reset', true);

    expect(\Illuminate\Support\Facades\Hash::check('newpass12', $user->fresh()->password))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/PasswordResetTest.php`
Expected: FAIL — route `auth-kit.password.update` undefined.

- [ ] **Step 3: Write `src/Http/Requests/ResetPasswordRequest.php`**

```php
<?php

declare(strict_types=1);

namespace Kurt\Modules\AuthKit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
```

- [ ] **Step 4: Add the reset methods to `PasswordResetController`**

Add imports:
```php
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kurt\Modules\AuthKit\Http\Requests\ResetPasswordRequest;
```

Add methods:
```php
    public function showResetForm(Request $request, string $token): View
    {
        return view('auth-kit::auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * The broker verifies the (hashed, expiring) token itself; we only set the
     * new password and let it fire PasswordReset + invalidate the token.
     */
    public function reset(ResetPasswordRequest $request): RedirectResponse|JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (CanResetPassword $user, string $password): void {
                /** @var \Illuminate\Database\Eloquent\Model $user */
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => trans($status)]);
        }

        $message = trans('auth-kit::auth.password_reset_success');

        return $this->wantsJson($request)
            ? $this->respond(['reset' => true, 'status' => $message])
            : redirect(config('auth-kit.password_reset.redirect_to', '/'))->with('status', $message);
    }
```

- [ ] **Step 5: Add reset routes (gated, alongside the forgot routes)**

`routes/web.php`, in the same `feature('password_reset')` block:
```php
    Route::get('reset-password/{token}', [$p, 'showResetForm'])->middleware('guest')->name('auth-kit.password.reset');
    Route::post('reset-password', [$p, 'reset'])->middleware(['guest', 'throttle:6,1'])->name('auth-kit.password.update');
```

`routes/api.php`, in the same block:
```php
    Route::post('reset-password', [$p, 'reset'])->middleware('throttle:6,1')->name('auth-kit.password.update');
```

- [ ] **Step 6: Write `resources/views/auth/reset-password.blade.php`**

```blade
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Reset password</title></head>
<body>
    <form method="POST" action="{{ route('auth-kit.password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        @foreach (['email', 'password'] as $f)
            @error($f)<p role="alert">{{ $message }}</p>@enderror
        @endforeach
        <label>Email <input type="email" name="email" value="{{ old('email', $email) }}" required></label>
        <label>New password <input type="password" name="password" required></label>
        <label>Confirm <input type="password" name="password_confirmation" required></label>
        <button type="submit">Reset password</button>
    </form>
</body>
</html>
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `"$PHP84" vendor/bin/pest tests/Modes/Ui/PasswordResetTest.php tests/Modes/Api/ApiPasswordResetTest.php`
Expected: PASS.

- [ ] **Step 8: Add a feature-off test**

Mirroring M3's `RegistrationDisabled` / M4's `VerificationDisabled` mode cases, add a mode case booting with `auth-kit.features.password_reset=false` and a test asserting `app('router')->has('auth-kit.password.email')` is false. Place it in its own `tests/Modes/<Name>/` directory and register it in `tests/Pest.php` (matching the established convention).

- [ ] **Step 9: Full suite + static analysis**

Run: `"$PHP84" vendor/bin/pest` → all green (M1-M4's 40 + M5's new).
Run: `"$PHP84" vendor/bin/phpstan analyse --memory-limit=2G` → no errors (reuse the existing per-controller `view-string` ignore precedent if larastan flags the runtime `auth-kit::` views).

- [ ] **Step 10: Commit**

```bash
git add src/Http resources/views/auth/reset-password.blade.php routes tests
git commit -m "feat: password reset flow over the Laravel broker"
```

---

## Done Criteria

- `feature('password_reset')` on + `ui`: forgot form + POST sends a link (identical generic response for known/unknown email); `GET reset-password/{token}` renders the form; POST resets the password (hashed), fires `PasswordReset`, redirects. `api`: same via JSON envelope. Feature off: routes absent. `headless`: no routes.
- Tokens are the framework broker's (hashed, expiring); an invalid token errors and leaves the password unchanged; both endpoints throttled.
- Full Pest suite + PHPStan L8 green under PHP 8.4.

## Out of Scope (later milestones)

- Password confirmation (re-auth before sensitive actions).
- Rate-limiting/lockout + `laravel-auth-events` journal; 2FA; sessions/devices; passwordless; Sanctum token API auth.
- Release: tag `v1.2.0` after M5 lands.
