<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan;

use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Hosts\LentSurfaceHost;
use Jovian\Venusian\Vulkan\Hosts\MetalLayerHost;
use Surface\Contracts\Drawing\GPUAttachment;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\SurfaceKind;

/**
 * Surface's Vulkan driver. One context per engine instance; the provider
 * binds this class as a singleton behind gpu.vulkan.
 */
final class VulkanEngine implements GPUEngineDriver
{
    private ?VulkanContext $context = null;

    public function engine(): GPUEngine
    {
        return GPUEngine::VULKAN;
    }

    /** MoltenVK draws through a CAMetalLayer; everywhere else the host lends a VkSurfaceKHR. */
    public function surfaceKind(): SurfaceKind
    {
        return PHP_OS_FAMILY === 'Darwin' ? SurfaceKind::LAYER : SurfaceKind::VULKAN_SURFACE;
    }

    /**
     * The one context, keyed by its instance extension set (order-free).
     *
     * @param  list<string>  $instanceExtensions  [] boots headless
     */
    public function context(array $instanceExtensions = []): VulkanContext
    {
        $wanted = VulkanContext::extensionSet($instanceExtensions);
        if (is_null($this->context)) {
            $this->context = VulkanContext::boot($wanted);

            return $this->context;
        }

        if ($this->context->instanceExtensions !== $wanted) {
            throw VulkanDrawingException::instanceExtensionMismatch();
        }

        return $this->context;
    }

    public function attach(GPUHost $host): GPUAttachment
    {
        $width = max(1, (int) round($host->width * $host->scale));
        $height = max(1, (int) round($host->height * $host->scale));

        if (! is_null($host->vk)) {
            $surfaceHost = new LentSurfaceHost($host->vk);

            return new GPUAttachment(new VulkanExecutor($this->context($surfaceHost->instanceExtensions()), $surfaceHost, $width, $height));
        }

        if ($host->layer > 0) {
            $surfaceHost = MetalLayerHost::lent($host->layer, $width, $height, $host->scale);

            return new GPUAttachment(new VulkanExecutor($this->context($surfaceHost->instanceExtensions()), $surfaceHost, $width, $height));
        }

        $surfaceHost = MetalLayerHost::mint($width, $height, $host->scale);
        $context = $this->context($surfaceHost->instanceExtensions());

        return new GPUAttachment(
            new VulkanExecutor($context, $surfaceHost, $width, $height),
            $surfaceHost->layerPointer(),
            $surfaceHost->layerClass(),
        );
    }
}
