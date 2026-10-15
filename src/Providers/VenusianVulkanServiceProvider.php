<?php

namespace Jovian\Engines\Vulkan\Providers;

use Jovian\Engines\Vulkan\VulkanDevice;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Registers the 'vulkan' engine with the drawing manager: each renderer() call
 * builds a new engine over a new VulkanDevice from the shared arguments.
 */
class VenusianVulkanServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {
        self::extend($this->app->get('drawing'));
    }

    /** The engine's creator, on any drawing manager. */
    public static function extend(DrawingManager $drawing): void
    {
        $drawing->extend('vulkan', fn (array $args, DrawingManager $drawing): GpuRenderingEngine => GpuRenderingEngine::from(new VulkanDevice, $args, $drawing));
    }
}
