<?php

namespace Jovian\Engines\Vulkan\Providers;

use Jovian\Engines\Vulkan\VKLogicalDevice;
use Surface\Contracts\Graphics\Backend;
use Surface\Graphics\Gfx;
use Surface\Rendering\Recorders;
use Voyager\NutsAndBolts\ServiceProvider;

/** Registers the 'vulkan' engine with Graphics, on the GPU backend. */
class VenusianVulkanServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (extension_loaded('vulkan')) {
            Gfx::manager()->extend('vulkan', fn (Backend $backend) => new VKLogicalDevice($backend, recorder: fn () => Recorders::driver()->recorder()), Backend::GPU);
        }
    }
}
