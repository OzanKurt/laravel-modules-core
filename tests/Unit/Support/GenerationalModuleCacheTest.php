<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Kurt\Modules\Core\Support\ConfigModuleCache;
use Kurt\Modules\Core\Support\GenerationalModuleCache;

function genCache(bool $enabled = true): GenerationalModuleCache
{
    return new GenerationalModuleCache(new ConfigModuleCache(new Repository(new ArrayStore), $enabled, 'lib', 3600));
}

it('remembers a value under a scope and serves it from cache', function () {
    $cache = genCache();
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return 'v';
    };

    expect($cache->remember('acl', 'k', $compute))->toBe('v')
        ->and($cache->remember('acl', 'k', $compute))->toBe('v')
        ->and($calls)->toBe(1);
});

it('bump() invalidates the entire scope in one call', function () {
    $cache = genCache();
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return $calls;
    };

    expect($cache->remember('acl', 'a', $compute))->toBe(1)
        ->and($cache->remember('acl', 'b', $compute))->toBe(2);

    $cache->bump('acl'); // whole scope invalidated at once

    // Both keys recompute under the new generation.
    expect($cache->remember('acl', 'a', $compute))->toBe(3)
        ->and($cache->remember('acl', 'b', $compute))->toBe(4);
});

it('keeps a stable generation token until bumped', function () {
    $cache = genCache();

    $first = $cache->generation('acl');
    expect($cache->generation('acl'))->toBe($first); // stable

    $cache->bump('acl');
    expect($cache->generation('acl'))->not->toBe($first); // fresh token after bump
});

it('does not serve a stale entry under the new generation after bump', function () {
    $cache = genCache();
    $cache->remember('acl', 'k', fn () => 'granted');

    $cache->bump('acl');

    // After a bump the old value is orphaned: the fresh compute wins.
    expect($cache->remember('acl', 'k', fn () => 'revoked'))->toBe('revoked');
});
