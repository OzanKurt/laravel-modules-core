# ACL Generational Cache - Tasarım Dokümanı

- **Tarih:** 2026-07-29
- **Paketler:** `laravel-modules-core` (mekanizma) + `laravel-modules-resource-library` + `laravel-modules-media-library` (uygulama)
- **Durum:** Tasarım onaylandı, implementasyon bekliyor
- **Bağlam:** module-cache convention'ın güvenlik-hassas fazı. Önce [[2026-07-23-module-cache-convention-design]] (ModuleCache) shipping edildi.

## 1. Amaç ve risk

Library/MediaLibrary'de per-folder capability çözümlemesi (`PermissionResolver::highestCapability($subject, $folder)`) ancestor-chain'i yürür - ailenin en pahalı, en sık okuması, her folder/item view + her API endpoint'inde çalışır. Bugün yalnızca per-request memo var. Cross-request cache'e çıkarmak istiyoruz.

**Neden ayrı faz:** ACL cache'i yanlış invalidate edilirse **erişimi kalkmış biri görmeye/erişmeye devam eder = güvenlik açığı.** Üç staleness kaynağı: (1) permission düzenleme, (2) folder taşıma (subtree ancestry değişir), (3) **rol değişimi - bu modüllerin dışında, domain event yok.**

## 2. Core mekanizma: `GenerationalModuleCache`

Mevcut `ModuleCache`'i saran ince katman (generational / versioned caching):

```php
namespace Kurt\Modules\Core\Support;

use Closure;
use Illuminate\Support\Str;
use Kurt\Modules\Core\Contracts\ModuleCache;

final class GenerationalModuleCache
{
    public function __construct(private readonly ModuleCache $cache) {}

    /** Cache under a scope whose whole keyspace can be invalidated by bump(). */
    public function remember(string $scope, string $key, Closure $callback, ?int $ttl = null): mixed
    {
        $gen = $this->generation($scope);

        return $this->cache->remember("{$scope}:g{$gen}:{$key}", $callback, $ttl);
    }

    /** Current generation token for a scope; seeded fresh if absent. */
    public function generation(string $scope): string
    {
        return (string) $this->cache->remember("gen:{$scope}", fn () => Str::random(12));
    }

    /** Invalidate the entire scope: drop the token so the next read seeds a new one; every old key is orphaned. */
    public function bump(string $scope): void
    {
        $this->cache->forget("gen:{$scope}");
    }
}
```

- `bump()` = tek `forget`. Subject/subtree enumerate yok, tek tek key silme yok.
- Eski generation'lı key'ler store'da kalır ama **asla okunmaz** (token değişti) - TTL'de düşer. Zararsız.
- Generation token'ı süresiz benzeri (ModuleCache default TTL) tutulur; expire olursa fresh token → over-invalidation (soğuk cache), asla stale-grant değil - **güvenli taraf.**

Factory'ye kısayol: `ModuleCacheFactory::generationalFor(string $module): GenerationalModuleCache` (bir `ModuleCache` üretip sarar).

## 3. Library / MediaLibrary uygulaması

Her iki modül yapısal ikiz (aynı resolver + aynı per-request memo). Aynı Core helper'ı kullanır.

**Anahtar yapısı** (scope = `acl`):

```
key = subject:{subjectId} : roles:{rolesHash} : folder:{folderId}
```

- **`rolesHash`** = subject'in mevcut rollerinin deterministik hash'i (modülün `roles.resolver`'ından okunan roller). Rol değişince key değişir → **rol-staleline'ı otomatik, event'siz çözülür.**
- **`bump('acl')`** çağrılır: `FolderPermissionChanged` ve `FolderMoved` event'lerinde (mevcut event'ler). → tüm ACL keyspace soğur (global generation, kabul edilen tradeoff).
- **İki katman:** L1 = mevcut per-request memo (aynı request tekrar hesaplamaz), L2 = cross-request `GenerationalModuleCache`. Resolver: L1 → L2 → canlı hesap.
- **Kısa TTL taban:** `{slug}.cache.ttl` (ACL için düşük öneri, örn. 300s) - kaçan her şey için defense-in-depth.

## 4. Güvenlik invariant'ları

- **Girdi bütünlüğü:** capability sonucunu etkileyen HER girdi ya key'de (subjectId, rolesHash, folderId) ya da generation'da (permission/move) olmalı. Gizli girdi = stale-grant riski. `roles.resolver` dışında bir girdi varsa (örn. subject'in grup üyeliği) o da rolesHash'e katılmalı.
- **Fail-safe / fail-closed:** cache okuma/yazma hatası → **canlı çözümlemeye düş**, asla "granted"a fail-open olma. `enabled=false` de canlı çözümlemedir.
- **Move subtree:** `FolderMoved` bir alt-ağacın ancestry'sini değiştirir; global `bump('acl')` bunu zaten kapsar (tüm keyspace soğur) - subtree enumerate gerekmez.
- **Yalnızca capability cache'lenir, karar değil:** cache edilen `highestCapability` sonucudur; policy `can()` kararı her seferinde bu (taze veya cache'li ama doğru-invalidate) capability'den üretilir.

## 5. Test stratejisi

Core (M1): `GenerationalModuleCache` remember/generation/bump; bump sonrası eski scope key'i okunmaz (yeni token); generation token stabil; `enabled=false` (alttaki ModuleCache) bypass.

Library/MediaLibrary (M2/M3):
- **Kritik güvenlik testi:** bir folder'da subject'e capability ver → cache warm → permission REVOKE + `FolderPermissionChanged` → `bump` → sonraki okuma **reddediyor** (stale-grant YOK).
- Rol değişimi: rolesHash değişince farklı capability (eski entry okunmaz).
- Move: `FolderMoved` → `bump` → yeni ancestry'ye göre çözülüyor.
- Fail-safe: cache disabled/hatalı → canlı doğru sonuç.
- L1 memo: aynı request'te resolver iki kez çağrılmıyor.
- Pest + `PackageTestCase`, phpstan L8, coverage ≥80%.

## 6. Bölme

- **M1 (core):** `GenerationalModuleCache` + `ModuleCacheFactory::generationalFor()` + tests. → yeni minor core release.
- **M2 (resource-library):** ACL resolver'ı L2 generational cache'le sarma (L1 memo korunur) + `FolderPermissionChanged`/`FolderMoved` bump wiring + rolesHash + güvenlik testleri.
- **M3 (media-library):** aynı helper'ı yapısal ikiz olarak uygula.

## 7. Kapsam dışı / notlar

- Global generation kabul edildi: bir permission/move değişimi TÜM ACL cache'ini soğutur. Yüksek write-hacimli ACL senaryosunda per-subtree generation'a bölünebilir (v2) - ama YAGNI, ve global her zaman güvenli.
- Rol kaynağı `roles.resolver` dışına çıkarsa (ör. tenant/grup) rolesHash tanımı genişletilir.
