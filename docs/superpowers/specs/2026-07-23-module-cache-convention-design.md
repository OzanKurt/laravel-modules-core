# Module Cache Convention - Tasarım Dokümanı

- **Tarih:** 2026-07-23
- **Paketler:** `ozankurt/laravel-modules-core` (abstraction) + `ozankurt/laravel-modules-blog` (pilot)
- **Durum:** Tasarım onaylandı, implementasyon planı bekliyor
- **Kapsam (v1):** Foundation (Core cache primitifleri) + güvenli pilot (Blog). ACL cache'i kapsam dışı (ayrı, güvenlik-tasarımlı faz).

## 1. Amaç

Aile modülleri cache'e ad hoc yöneliyor (manager inline yapıyor, Licensing'in `LicenseCache` contract'ı var, Library + MediaLibrary aynı per-request ACL memo'sunu ayrı ayrı yazmış). Bu drift'i durdurmak için **Core'da paylaşılan bir cache convention'ı**: standart config bloğu + config-driven bir cache wrapper + mevcut Observer/Event katmanına bağlanan invalidation. Modüller pahalı okuma yollarını tutarlı ve güvenli şekilde cache'ler.

v1, foundation'ı kurar ve **güvenli** bir modülde (Blog) kanıtlar. ACL cache'i (ailenin en pahalı okuması ama stale olursa güvenlik açığı) bilerek ertelenir.

## 2. Core abstraction (contract + impl + factory)

### 2.1 Contract

```php
namespace Kurt\Modules\Core\Contracts;

use Closure;

interface ModuleCache
{
    public function enabled(): bool;

    /** Remember a value under a module-prefixed key; null results are cached (negative sentinel). */
    public function remember(string $key, Closure $callback, ?int $ttl = null): mixed;

    public function forget(string $key): void;

    public function flush(): void;
}
```

### 2.2 Implementation

`Kurt\Modules\Core\Support\ConfigModuleCache implements ModuleCache`:
- Constructor: `(Repository $store, bool $enabled, string $prefix, int $ttl)`.
- `remember()`: `enabled=false` ise callback'i doğrudan çağırır (cache yok). Aksi halde `{$prefix}:{$key}` anahtarıyla store'dan okur/yazar; `null` sonucu bir **sentinel** ile cache'lenir (negative lookup da cache'lenir), okumada unwrap edilir.
- `forget()`/`flush()`: prefixli anahtarı/namespace'i temizler.
- Store yalnızca **isimle** seçilir (config:cache güvenli - closure taşınmaz).

### 2.3 Factory

`Kurt\Modules\Core\Support\ModuleCacheFactory` (Core'da singleton bind):

```php
public function for(string $module): ModuleCache
{
    $cfg = config("{$module}.cache", []);
    return new ConfigModuleCache(
        $this->cache->store($cfg['store'] ?? null),      // null = default store
        (bool)   ($cfg['enabled'] ?? true),
        (string) ($cfg['prefix'] ?? $module),
        (int)    ($cfg['ttl'] ?? 3600),
    );
}
```

Modül config'i yoksa güvenli defaultlar uygulanır (enabled, default store, slug prefix, 3600s). Modüller `app(ModuleCacheFactory::class)->for('blog')` ile (veya inject ederek) kendi scoped cache'ini alır.

### 2.4 Config convention (`{slug}.cache`)

Her modülün `config/{slug}.php`'sine eklenen standart blok:

```php
'cache' => [
    'enabled' => (bool) env('BLOG_CACHE_ENABLED', true),
    'store'   => env('BLOG_CACHE_STORE'),   // null = default
    'prefix'  => 'blog',
    'ttl'     => (int) env('BLOG_CACHE_TTL', 3600),
],
```

## 3. Pilot: Blog (yalnızca güvenli read'ler)

| Read | Kaynak | TTL / bust |
|------|--------|-----------|
| **Sitemap** | `Support/SitemapBuilder.php` | TTL + herhangi post/category/tag yazımında `forget('sitemap')` |
| **Feed** | `Support/FeedBuilder.php` | kısa TTL + publish/unpublish'te `forget('feed')` |
| **Post by-slug** | `Http/Controllers/Api/PostController.php::resolvePost()` | per-key `forget("post:{slug}")`, sadece gerçek edit/publish/archive/delete'te |

**view_count tuzağı:** Post `view_count` her görüntülemede artar. By-slug cache bunu da tutar. `PostObserver` **view-bump'ı içerik edit'inden ayırdığı** için by-slug yalnızca **gerçek edit'te** bust edilir; iki edit arası view_count bir tık stale kalır (yaklaşık sayı, kabul edilebilir). Sitemap/feed bu tuzağa girmez.

**Invalidation:** yeni altyapı yok. Blog'un mevcut `PostObserver`/`CategoryObserver`/`TagObserver`'larına (ve ilgili event'lere) `->forget(...)` çağrıları eklenir:
- `PostObserver` gerçek içerik değişiminde → `forget("post:{slug}")`, `forget('sitemap')`, `forget('feed')`. View-only bump'ta → hiçbir şey.
- `CategoryObserver`/`TagObserver` yazımında → `forget('sitemap')` (feed taksonomiye bağlıysa o da).

## 4. Hata yönetimi, güvenlik, robustluk

- **`enabled=false` bypass:** dev/test/CI'da veya bir modülde cache tamamen kapatılabilir; kod yolu aynı kalır.
- **TTL fallback:** bir bust kaçsa bile staleness TTL ile sınırlı - "robust by default".
- **Negative sentinel:** olmayan kayıt da cache'lenir (thundering herd'ü azaltır).
- **config:cache güvenli:** store isimle seçilir, closure config'e girmez.
- **Kapsam sınırı:** v1 yalnızca ACL/permission içermeyen, correctness-hassas olmayan read'leri cache'ler. Denormalized high-churn kolonlar (view_count/download_count) per-write değil TTL-only mantığıyla ele alınır.

## 5. Test stratejisi

- **Core:** `ConfigModuleCache` (remember hit/miss, `enabled=false` bypass, forget, flush, null/negative sentinel, ttl geçişi); `ModuleCacheFactory` config'i doğru okuyor (enabled/store/prefix/ttl), config'i olmayan modül → default.
- **Blog:** cache'li read ikinci çağrıda store'dan geliyor (query-count/spy ile); `PostObserver` gerçek edit'te bust ediyor; **view-only bump by-slug'ı bust ETMİYOR** (regression); `enabled=false` bypass edildiğinde out-of-band DB değişimi anında görünüyor.
- Pest + `PackageTestCase`, phpstan L8, aile coverage standardı (≥80%).

## 6. Milestone'lara bölme

- **M1 (core):** `ModuleCache` contract + `ConfigModuleCache` + `ModuleCacheFactory` + Core binding + config-convention dokümantasyonu. Tests. → `laravel-modules-core`, sonrasında yeni bir minor release (feature: geriye uyumlu).
- **M2 (blog):** Sitemap/feed/by-slug cache wiring + observer invalidation. Tests. → `laravel-modules-blog`; M1'i içeren core sürümüne bağlı.

## 7. Ertelenen / kapsam dışı

- **ACL cache'i (Library + MediaLibrary):** ailenin en pahalı okuması, ama stale ACL = güvenlik açığı. Kendi güvenlik-tasarımlı fazı: version/tag key (rol değişince flip eden) + kısa TTL + host rol-değişim hook'u; `FolderMoved` tüm alt-ağaç ancestry'sini bust etmeli. **Yalnızca modül-içi event'lere dayanan ACL cache'i ASLA shipping edilmez.**
- **Forum** (board taxonomy, thread-by-slug), **Loyalty** (yalnızca reporting aggregate'leri, canlı bakiye değil) — foundation kanıtlandıktan sonra aynı convention ile.
- **Atla:** Chat (real-time), i18n (admin editing tool), Interactions (`counters.driver=table` zaten cache'i).
