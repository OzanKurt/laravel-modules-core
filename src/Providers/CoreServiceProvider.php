<?php

declare(strict_types=1);

namespace Kurt\Modules\Core\Providers;

use Kurt\Modules\Core\Contracts\ModuleRegistry;
use Kurt\Modules\Core\Contracts\UserResolver;
use Kurt\Modules\Core\Modules\Registry;
use Kurt\Modules\Core\Support\ConfigUserResolver;
use Kurt\Modules\Core\Support\ModuleCacheFactory;
use Spatie\LaravelPackageTools\Package;

final class CoreServiceProvider extends PackageServiceProvider
{
    protected function module(): string
    {
        return 'kurtmodules';
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-modules-core')
            ->hasConfigFile('kurtmodules');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(UserResolver::class, fn ($app) => new ConfigUserResolver($app['config']));
        $this->app->singleton(ModuleRegistry::class, fn () => new Registry);
        $this->app->singleton(ModuleCacheFactory::class, fn ($app) => new ModuleCacheFactory($app['cache'], $app['config']));
    }
}
