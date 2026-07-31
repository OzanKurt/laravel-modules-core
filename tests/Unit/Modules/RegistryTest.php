<?php

declare(strict_types=1);

use Kurt\Modules\Core\Contracts\ModuleRegistry;
use Kurt\Modules\Core\Modules\ModuleManifest;
use Kurt\Modules\Core\Modules\Registry;

it('registers and retrieves manifests keyed by slug', function () {
    $registry = new Registry;
    expect($registry)->toBeInstanceOf(ModuleRegistry::class);

    $blog = ModuleManifest::make('blog');
    $chat = ModuleManifest::make('chat');
    $registry->register($blog);
    $registry->register($chat);

    expect($registry->has('blog'))->toBeTrue()
        ->and($registry->has('chat'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse()
        ->and($registry->get('blog'))->toBe($blog)
        ->and($registry->get('missing'))->toBeNull()
        ->and(array_keys($registry->all()))->toBe(['blog', 'chat']);
});

it('overwrites a manifest registered under the same slug', function () {
    $registry = new Registry;
    $first = ModuleManifest::make('blog')->version('1.0.0');
    $second = ModuleManifest::make('blog')->version('2.0.0');

    $registry->register($first);
    $registry->register($second);

    expect($registry->all())->toHaveCount(1)
        ->and($registry->get('blog'))->toBe($second);
});
