<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan;

use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
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

    public function surfaceKind(): SurfaceKind
    {
        return SurfaceKind::LAYER;
    }

    public function context(string $wsi = ''): VulkanContext
    {
        if (is_null($this->context)) {
            $this->context = VulkanContext::boot($wsi);

            return $this->context;
        }

        if ($this->context->wsi !== $wsi) {
            throw VulkanDrawingException::instanceExtensionMismatch();
        }

        return $this->context;
    }

    public function attach(GPUHost $host): GPUAttachment
    {
        $width = max(1, (int) round($host->width * $host->scale));
        $height = max(1, (int) round($host->height * $host->scale));
        $surfaceHost = MetalLayerHost::mint($width, $height, $host->scale);
        $context = $this->context($surfaceHost->wsiExtension());

        return new GPUAttachment(
            new VulkanExecutor($context, $surfaceHost, $width, $height),
            $surfaceHost->layerPointer(),
            $surfaceHost->layerClass(),
        );
    }
}
