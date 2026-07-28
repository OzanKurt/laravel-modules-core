<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Kurt\Modules\Core\Support\ConfigModuleCache;

function moduleCache(bool $enabled = true, int $ttl = 3600): ConfigModuleCache
{
    return new ConfigModuleCache(new Repository(new ArrayStore), $enabled, 'blog', $ttl);
}

it('remembers a computed value and serves it from cache on the next call', function () {
    $cache = moduleCache();
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return 'value';
    };

    expect($cache->remember('k', $compute))->toBe('value')
        ->and($cache->remember('k', $compute))->toBe('value')
        ->and($calls)->toBe(1); // callback ran once
});

it('caches a null result via the sentinel (negative lookup)', function () {
    $cache = moduleCache();
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return null;
    };

    expect($cache->remember('missing', $compute))->toBeNull()
        ->and($cache->remember('missing', $compute))->toBeNull()
        ->and($calls)->toBe(1); // null was cached, not recomputed
});

it('bypasses the store entirely when disabled', function () {
    $cache = moduleCache(enabled: false);
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return 'x';
    };

    $cache->remember('k', $compute);
    $cache->remember('k', $compute);

    expect($calls)->toBe(2)                 // recomputed every time
        ->and($cache->enabled())->toBeFalse();
});

it('forgets a specific prefixed key', function () {
    $cache = moduleCache();
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return $calls;
    };

    expect($cache->remember('k', $compute))->toBe(1);
    $cache->forget('k');
    expect($cache->remember('k', $compute))->toBe(2); // recomputed after forget
});
