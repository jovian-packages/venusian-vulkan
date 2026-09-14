<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Providers;

use Jovian\Venusian\Vulkan\VulkanEngine;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Publishes the Vulkan GPU engine under the alias Surface looks for.
 */
class VenusianVulkanServiceProvider extends ServiceProvider
{
    /**
     * Bind the engine as a singleton behind 'gpu.vulkan'.
     *
     * Surface's GPUEngineManager resolves that string and nothing else, so
     * installing this package is the whole of what makes Vulkan drawing available.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(VulkanEngine::class);
        $this->app->alias(VulkanEngine::class, 'gpu.vulkan');
    }

    /**
     * Nothing to boot. The engine opens the loader when it is first asked to attach.
     * @return void
     */
    public function boot(): void {}
}
