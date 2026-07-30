# laravel-auth-kit - Tasarım Dokümanı

- **Tarih:** 2026-07-22
- **Paket:** `epicalgorithms/laravel-auth-kit`
- **Durum:** Tasarım onaylandı, implementasyon planı bekliyor
- **Mimari:** Orchestrator / meta-paket

## 1. Amaç ve konum

EpicAlgorithms'in kendi **pure Laravel + Blade** app'leri (Stitch, hrConnectum, packr8 vb.) için Fortify+Breeze'in yerini alan, **vendor'da kalan ve bakımı yapılan** tek bir auth katmanı.

Çözdüğü problem: Laravel'in "install package → publish auth stuff → delete package" (Breeze) deseni kopyalanmış kod bırakır; kod bir daha güvenlik yaması almaz. Fortify bu boşluğu headless olarak dolduruyor ama bizim sağlamlaştırılmış tekil paketlerimizi ve stack'imizi bilmiyor. `laravel-auth-kit`, mevcut hardening'i tekrar yazmadan bu paketleri opinionated defaultlarla birbirine bağlar; view'lar publish edilip override edilir ama tüm mantık/güvenlik vendor'da kalır ve `composer update` ile yama alır.

Reddedilen alternatif: Breeze tarzı "stub publish et, paketi sil" - "maintained kalsın" hedefiyle çelişir.

## 2. Orchestrate edilen paketler

| Paket | Rol | Durum |
|-------|-----|-------|
| `ozankurt/laravel-modules-core` | Temel primitifler (HttpMode, UserResolver, ApiController) | mevcut |
| `laravel-auth-events` | Login journal / audit | mevcut, **default açık** |
| `laravel-2fa` | TOTP challenge, recovery codes, enforcement | mevcut |
| `laravel-auth-sessions` | Cihaz/oturum listesi, remote revoke | mevcut |
| `laravel-passwordless` | Magic-link / OTP login | **henüz yok** - v1'de kit içi contract'ın arkasında implemente edilir, ileride bağımsız pakete çıkarılırsa delege edilir |

Kit içinde implemente edilenler: register, login, logout, email verification, password reset, password confirmation, rate-limit/lockout, passwordless (v1).

## 3. Üç ayar ekseni

### 3.1 HttpMode (core'daki enum ile hizalı)

`config('auth-kit.http.mode')`:

- **`ui`** - Blade view + JSON (superset). Klasik Blade app deneyimi.
- **`api`** - sadece JSON, `ApiController` envelope'u (`{ "data", "meta" }` / `{ "message", "errors" }`).
- **`headless`** - route yok; sadece domain servisleri (`AuthKit::attempt()` vb.). Safe-by-default: geçersiz/eksik config → headless.

"Blade mı API mı" bir seçim değil; ikisi aynı paketin modları.

### 3.2 Feature flag'ler (`config('auth-kit.features.*')`)

Her akış bağımsız aç/kapa:

| Flag | Açtığı |
|------|--------|
| `registration` | Register akışı |
| `email_verification` | Email doğrulama |
| `password_reset` | Şifre sıfırlama |
| `password_confirmation` | Hassas işlem öncesi şifre teyidi |
| `two_factor` | TOTP challenge (`laravel-2fa`) |
| `otp_login` / `magic_link` | Passwordless (opsiyonel, kit içi contract) |
| `sessions` | Cihaz/oturum yönetimi (`laravel-auth-sessions`) |
| `login_journal` | Login audit (`laravel-auth-events`) - **default açık** |
| `lockout` | Brute-force lockout + rate limit |

### 3.3 Per-user capability gate'leri

Interface + default trait, config fallback'li. Feature flag "bu akış app'te var mı", gate "bu kullanıcı için geçerli mi" - iki ayrı katman.

```php
interface AuthKitUser {
    public function canEnableTwoFactor(): bool;
    public function isTwoFactorEnforced(): bool;
    public function canUseOtpLogin(): bool;
    public function canRegister(): bool;
}

// trait InteractsWithAuthKit her metoda config-tabanlı default verir:
public function isTwoFactorEnforced(): bool {
    return AuthKit::gate('two_factor.enforced', $this); // bool | closure(User): bool
}
```

Kullanım seviyeleri:
- **Basit:** `'two_factor' => ['enforced' => true]`
- **Dinamik:** `'enforced' => fn (User $u) => $u->is_admin`
- **Tam kontrol:** User modelinde metodu override et.

Örnek etkileşim: `two_factor` feature açık ama kullanıcı `canEnableTwoFactor() === false` → 2FA gösterilmez; `isTwoFactorEnforced() === true` → login'de zorlanır.

## 4. Controller'lar ve akış

Her akış tek controller; response modu content-negotiation + route grubunun HttpMode'u ile belirlenir (`Accept: application/json` veya `api` mode → JSON; aksi halde Blade).

Controller'lar **ince**; iş tek-sorumluluklu action servislerinde (`RegisterAction`, `LoginAction`, `VerifyEmailAction`, `ConfirmPasswordAction`, `SendMagicLinkAction` ...). Bu servisler UI / API / headless üç mod tarafından da çağrılır - çekirdek paylaşılır, davranış tutarlı.

View'lar `vendor:publish --tag=auth-kit-views` ile publish edilir; **sadece kozmetik**, controller/action/route/güvenlik vendor'da kalır.

## 5. Entegrasyon ve veri

- **`laravel-auth-events`:** login başarı/başarısızlık, logout, password reset, 2FA challenge, lockout → journal'a yazılır (default açık). Fail-open.
- **`laravel-2fa`:** `LoginAction`, `isTwoFactorEnforced()` gate'ine göre challenge'a yönlendirir; recovery code akışı 2fa'dan.
- **`laravel-auth-sessions`:** sessions feature açıksa aktif oturum tablosu + revoke ekranı/endpoint'i.
- **Passwordless (kit içi):** `auth_kit_login_tokens` tablosu, HMAC imzalı tek-kullanımlık token, TTL + resend cooldown, scanner-safe GET/POST. Tek migration.
- **User modeli:** kit kendi user tablosunu dayatmaz; `UserResolver` ile consumer'ın modeline bağlanır. Kit'e ait tek tablo passwordless token tablosu.
- **Config:** `config/auth-kit.php` → `http.mode`, `features.*`, `gates.*`, `lockout.*`, `passwordless.*`.

## 6. Hata yönetimi ve güvenlik

- **Fail-safe:** journal yazımı login'i bloklamaz (fail-open). Config parse hatası → feature default kapalı (core'un safe-by-default deseni).
- **Güvenlik:** timing-safe token karşılaştırma, generic hata mesajları (user enumeration yok), lockout throttle, CSRF (ui), rate-limit (api, core `ApiRateLimiter`), HMAC-keyed passwordless token, escalating resend cooldown.

## 7. Test stratejisi

- Pest, core'daki `PackageTestCase` üstünde.
- Her feature flag'in açık/kapalı hali; her gate'in bool/closure/override varyantı; üç HttpMode ayrı ayrı.
- Orchestrate edilen paketler unit test'te fake'lenir; gerçek 2fa/sessions/auth-events entegrasyonu ayrı integration testi.

## 8. Açık kararlar / v2'ye bırakılanlar

- `laravel-passwordless`'ın bağımsız pakete çıkarılması (v1'de kit içi).
- Social login (Socialite) - kapsam dışı, v2 adayı.
- WebAuthn / passkey - kapsam dışı, v2 adayı.
- Filament entegrasyonu - bu kit Blade-first; Filament panelleri ayrı bir katman olabilir.
