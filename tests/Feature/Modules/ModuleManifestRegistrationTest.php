<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Kurt\Modules\Core\Contracts\ModuleRegistry;
use Kurt\Modules\Core\Modules\ModuleManifest;
use Kurt\Modules\Core\Providers\PackageServiceProvider;
use Kurt\Modules\Core\Testing\PackageTestCase;
use Spatie\LaravelPackageTools\Package;

/** A throwaway module provider that declares a manifest. */
final class DemoModuleServiceProvider extends PackageServiceProvider
{
    protected function module(): string
    {
        return 'demo';
    }

    public function configurePackage(Package $package): void
    {
        $package->name('demo');
    }

    protected function moduleManifest(): ?ModuleManifest
    {
        return ModuleManifest::make('demo')->name('Demo')->feature('thing', default: true);
    }
}

final class ModuleManifestRegistrationTest extends PackageTestCase
{
    /** @param  Application  $app */
    protected function modulePackageProviders($app): array
    {
        return [DemoModuleServiceProvider::class];
    }

    public function test_core_binds_the_registry_as_a_singleton(): void
    {
        $this->assertInstanceOf(ModuleRegistry::class, $this->app->make(ModuleRegistry::class));
        $this->assertSame($this->app->make(ModuleRegistry::class), $this->app->make(ModuleRegistry::class));
    }

    public function test_a_module_provider_declares_its_manifest_into_the_registry(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        $this->assertTrue($registry->has('demo'));
        $this->assertSame('Demo', $registry->get('demo')->getName());
        $this->assertTrue($registry->get('demo')->featureDefault('thing'));
    }

    public function test_a_provider_without_a_manifest_registers_nothing(): void
    {
        // Core itself declares no manifest; only 'demo' should be present.
        $this->assertSame(['demo'], array_keys($this->app->make(ModuleRegistry::class)->all()));
    }
}
