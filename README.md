# laravel-modules-core

Shared bootstrap kit for [KurtModules](https://github.com/ozankurt) Laravel packages.

## Requirements

- PHP 8.4+
- Laravel 13.x
- (Optional) Filament 3, 4, or 5

## Installation

```bash
composer require ozankurt/laravel-modules-core
```

## What it provides

- `Kurt\Modules\Core\Providers\PackageServiceProvider` — abstract base every kurtmodules service provider extends. Wraps `spatie/laravel-package-tools` and dispatches to `registerFilamentV{3,4,5}` based on the installed Filament major.
- `Kurt\Modules\Core\Contracts\UserResolver` (+ `ConfigUserResolver`) — resolves the consumer's user model via `kurtmodules.user_model` config or `auth.providers.users.model` fallback.
- `Kurt\Modules\Core\Concerns\ResolvesUser` — trait that gives module models a `userBelongsTo()` helper.
- `Kurt\Modules\Core\Concerns\InteractsWithModuleConfig` — sugar for `config("{module}.key")` access.
- `Kurt\Modules\Core\Concerns\AbortsInProduction` — trait for Artisan commands; `abortIfProduction()` blocks demo/destructive commands in production unless `--force` is passed. Lets `*:demo` commands drop their hand-rolled guards.
- `Kurt\Modules\Core\Concerns\HasEnumLabels` — trait giving string-backed enums a Title-Case `getLabel()` and a null `getColor()` default, so downstream enums can satisfy Filament's `HasLabel`/`HasColor` without Core requiring Filament.
- `Kurt\Modules\Core\Support\FilamentVersion` — `::major()`, `::isAtLeast()`, `::isExactly()`.
- `Kurt\Modules\Core\Enums\{Approval,MediaKind,Visibility}` — generic cross-module enums.
- `Kurt\Modules\Core\Testing\PackageTestCase` — Testbench-backed base test case with an in-memory `users` table.
- **API kit** — `Http\HttpMode`, `Http\ApiRouteGroup`, `Http\Controllers\ApiController`, `Http\Concerns\HandlesApiQuery`, `Support\ApiRateLimiter`, plus the `PackageServiceProvider::registerModuleApi()` hook. The config-convention-driven REST foundation every module inherits (see [API kit](#api-kit)).

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag="kurtmodules-config"
```

```php
return [
    'user_model' => env('KURTMODULES_USER_MODEL'),
];
```

## Command & enum helpers

Guard demo/destructive Artisan commands so they cannot run in production without `--force`:

```php
use Illuminate\Console\Command;
use Kurt\Modules\Core\Concerns\AbortsInProduction;

class SeedDemoCommand extends Command
{
    use AbortsInProduction;

    protected $signature = 'blog:demo {--force}';

    public function handle(): int
    {
        if ($this->abortIfProduction()) {
            return self::FAILURE;
        }

        // ... seed demo data ...

        return self::SUCCESS;
    }
}
```

Give a string-backed enum Filament-ready labels/colors without Core depending on Filament (the enum declares the contracts, the trait supplies the bodies):

```php
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Kurt\Modules\Core\Concerns\HasEnumLabels;

enum Status: string implements HasColor, HasLabel
{
    use HasEnumLabels;

    case Draft = 'draft';
    case InReview = 'in_review'; // getLabel() => "In Review"
    case Published = 'published';

    // Optional: override getColor() per case; getLabel() keeps the default.
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Published => 'success',
            default => null,
        };
    }
}
```

## API kit

A config-convention-driven foundation for a module's out-of-the-box REST API.
It generalises the `loyalty.http.mode` pattern so every module exposes a JSON
API the same way, **safe-by-default**: nothing is registered until a consumer
opts in, and write routes require auth + a Policy.

### Config convention

Every consuming module reads these per-module keys (module slug = the value
returned by the provider's `module()`):

| Key | Default | Purpose |
|---|---|---|
| `{slug}.http.mode` | `headless` | `headless` \| `api` \| `ui`. API routes register only for `api`/`ui`. |
| `{slug}.http.prefix` | `api/{slug}` | URL prefix for the route group. |
| `{slug}.http.middleware` | `['api']` | Base middleware for every API route. |
| `{slug}.http.auth_middleware` | `['auth']` | Appended to **write** (authenticated) routes. |
| `{slug}.http.rate_limit` | `'60,1'` | `maxAttempts,decayMinutes` for the `{slug}-api` throttle. |

Every group is throttled by a named limiter `{slug}-api` (keyed by user id, or
client IP for guests). A module's `config/{slug}.php` therefore ships:

```php
'http' => [
    'mode' => env('BLOG_HTTP_MODE', 'headless'),
    'prefix' => 'api/blog',
    'middleware' => ['api'],
    'auth_middleware' => ['auth:sanctum'],
    'rate_limit' => '60,1',
],
```

### Base classes

- `Http\HttpMode` — `HttpMode::forModule($slug)` resolves `{slug}.http.mode`
  (unknown → `Headless`); `->apiEnabled()`, `->is(...)`.
- `Http\ApiRouteGroup` — `ApiRouteGroup::attributes($slug, $authenticated = false)`
  returns the `Route::group()` array (`prefix`, merged `middleware` + throttle
  [+ `auth_middleware` when `$authenticated`], `as` = `"{slug}.api."`).
- `Http\Controllers\ApiController` — abstract base (adds `AuthorizesRequests`,
  `ValidatesRequests`) with a consistent envelope:
  - `respond($data, array $meta = [], int $status = 200)` → `{ "data": …, "meta": … }` (`meta` omitted when empty).
  - `respondCreated($data)` (201), `respondNoContent()` (204, empty body).
  - `respondPaginated(LengthAwarePaginator $p, ?string $resourceClass = null)` → items as `data` (mapped through the resource class when given) + `meta.pagination` (`current_page`, `per_page`, `total`, `last_page`).
  - `fail(string $message, int $status = 422, array $errors = [])` → `{ "message": …, "errors": … }`.
- `Http\Concerns\HandlesApiQuery` — allow-list-driven query helpers (never
  interpolate raw input as SQL identifiers):
  - `applyApiSorts($q, $r, array $allowed)` — `?sort=field,-other` (leading `-` = desc); non-allowed fields dropped.
  - `applyApiFilters($q, $r, array $allowed)` — `?filter[field]=value`; `$allowed` is a list of exact fields (`['name']`) or a mode map (`['name' => 'like', 'status' => 'exact']`). `like` → `%value%`.
  - `apiPaginate($q, $r, int $default = 15, int $max = 100)` — `?per_page=` clamped to `[1, $max]`, standard `?page=`.
- `Support\ApiRateLimiter` — `ApiRateLimiter::register($slug)` registers the
  `{slug}-api` limiter from `{slug}.http.rate_limit`.

### Resources vs envelope helpers

The envelope's top-level key is `data`, matching Laravel API Resources. **Use
Laravel resources directly** (Core ships no resource base) and hand the *result*
of a resource to the envelope helpers so the payload is wrapped exactly once:

```php
// Single: pass the resource itself — nested resources are not re-wrapped.
return $this->respond(PostResource::make($post));

// Collection: map through the resource, then envelope.
return $this->respondPaginated($paginator, PostResource::class);
```

Do **not** return a bare `JsonResource` from a route *and* also call `respond()`
— that double-wraps as `{"data":{"data":…}}`.

### How a module exposes an API

1. **Config** — add the `http` block above to `config/{slug}.php`; keep
   `mode` defaulting to `headless`.
2. **Resources** — define `App`-style `JsonResource` classes for your models.
3. **Controller** — extend `ApiController`; keep controllers thin over domain
   services. `use HandlesApiQuery` on index controllers for sort/filter/paginate.
   Enforce a **Policy** on every write (`$this->authorize(...)`).
4. **Routes** — `routes/api.php`, read vs write split via
   `ApiRouteGroup::attributes()`:

   ```php
   use Illuminate\Support\Facades\Route;

   // Read (public) endpoints — base middleware + throttle.
   Route::get('posts', [PostController::class, 'index'])->name('posts.index');
   Route::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');

   // Write endpoints — add the module auth middleware.
   Route::group(['middleware' => config('blog.http.auth_middleware', ['auth'])], function () {
       Route::post('posts', [PostController::class, 'store'])->name('posts.store');
       Route::patch('posts/{post}', [PostController::class, 'update'])->name('posts.update');
       Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
   });
   ```

5. **Provider** — wire it in `packageBooted()`; it is a no-op in headless mode
   and registers the rate limiter + route group otherwise:

   ```php
   public function packageBooted(): void
   {
       parent::packageBooted();

       $this->registerModuleApi(__DIR__.'/../../routes/api.php');
   }
   ```

The outer group (prefix, base middleware, throttle, `"{slug}.api."` name prefix)
is applied by `registerModuleApi()`; the route file only distinguishes read vs
write.

## License

MIT © Ozan Kurt
