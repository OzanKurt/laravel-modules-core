<?php

declare(strict_types=1);

use Kurt\Modules\Core\Contracts\ModuleCache;
use Kurt\Modules\Core\Support\ModuleCacheFactory;

it('builds a module cache from the module config block', function () {
    config()->set('blog.cache', ['enabled' => true, 'store' => 'array', 'prefix' => 'blog', 'ttl' => 60]);

    $cache = app(ModuleCacheFactory::class)->for('blog');

    expect($cache)->toBeInstanceOf(ModuleCache::class)
        ->and($cache->enabled())->toBeTrue();

    // Prefixing is scoped per module: 'blog' cache key must not collide with 'chat'.
    $cache->remember('k', fn () => 'blog-value');
    config()->set('chat.cache', ['store' => 'array', 'prefix' => 'chat']);
    $chat = app(ModuleCacheFactory::class)->for('chat');
    expect($chat->remember('k', fn () => 'chat-value'))->toBe('chat-value');
});

it('applies safe defaults when the module declares no cache block', function () {
    // No 'events.cache' set at all.
    $cache = app(ModuleCacheFactory::class)->for('events');

    expect($cache)->toBeInstanceOf(ModuleCache::class)
        ->and($cache->enabled())->toBeTrue(); // default enabled
});

it('honours enabled=false from config', function () {
    config()->set('blog.cache', ['enabled' => false]);

    expect(app(ModuleCacheFactory::class)->for('blog')->enabled())->toBeFalse();
});
