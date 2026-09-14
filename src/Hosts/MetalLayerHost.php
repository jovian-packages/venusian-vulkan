<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Hosts;

use Jovian\Bindings\Metal\QuartzCore\CAMetalLayer;
use Jovian\Bindings\Metal\Runtime\Bridge as MetalBridge;
use Jovian\Bindings\Metal\Values\CGSize;
use Jovian\Bindings\Vulkan\Ext\EXTMetalSurface;
use Jovian\Bindings\Vulkan\Ext\KHRSurface;
use Jovian\Bindings\Vulkan\Structs\VkMetalSurfaceCreateInfoEXT;
use Jovian\Venusian\Vulkan\Contracts\SurfaceHost;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Support\Blocks;

/**
 * The only jovian/metal importer. Mints a bare CAMetalLayer; MoltenVK
 * owns device, pixelFormat, and framebufferOnly. jovian/metal is a
 * suggest (macOS): without it or ext-metal, mint() and lent() throw
 * noHostForPlatform before any Metal symbol is touched.
 */
final class MetalLayerHost implements SurfaceHost
{
    private float $contentsScale = 1.0;

    private function __construct(
        private ?CAMetalLayer $layer,
    ) {}

    public static function mint(int $width, int $height, float $scale = 1.0): self
    {
        if (! self::metalAvailable()) {
            throw VulkanDrawingException::noHostForPlatform();
        }

        $layer = CAMetalLayer::init();
        if (is_null($layer)) {
            throw VulkanDrawingException::noHostForPlatform();
        }

        $host = new self($layer);
        $host->contentsScale = $scale > 0.0 ? $scale : 1.0;
        $host->setDrawableSize($width, $height);

        return $host;
    }

    /**
     * A layer the Surface host owns and shows (an SDL Metal view). Adopted
     * into ext-metal's registry — each side keeps its own retain — and sized
     * like a minted one. The engine hands no layer back for it.
     */
    public static function lent(int $pointer, int $width, int $height, float $scale = 1.0): self
    {
        if (! self::metalAvailable()) {
            throw VulkanDrawingException::noHostForPlatform();
        }

        $layer = CAMetalLayer::box(MetalBridge::adopt('CAMetalLayer', $pointer));
        if (is_null($layer)) {
            throw VulkanDrawingException::noHostForPlatform();
        }

        $host = new self($layer);
        $host->contentsScale = $scale > 0.0 ? $scale : 1.0;
        $host->setDrawableSize($width, $height);

        return $host;
    }

    /** ext-metal loaded and jovian/metal installed. The class check autoloads; a missing package answers false, never fatals. */
    private static function metalAvailable(): bool
    {
        return extension_loaded('metal') && class_exists(CAMetalLayer::class);
    }

    public function wsiExtension(): string
    {
        return 'VK_EXT_metal_surface';
    }

    /** @return list<string> */
    public function instanceExtensions(): array
    {
        return ['VK_KHR_surface', $this->wsiExtension()];
    }

    public function layerPointer(): int
    {
        if (is_null($this->layer)) {
            return 0;
        }

        return MetalBridge::pointerOf($this->layer->handle);
    }

    public function layerClass(): string
    {
        return is_null($this->layer) ? '' : 'CAMetalLayer';
    }

    public function setDrawableSize(int $width, int $height): void
    {
        if (is_null($this->layer)) {
            return;
        }

        $this->applyContentsScale($this->contentsScale);
        $this->layer->setDrawableSize(new CGSize(
            (float) max(1, $width),
            (float) max(1, $height),
        ));
    }

    public function setContentsScale(float $scale): void
    {
        $this->contentsScale = $scale > 0.0 ? $scale : 1.0;
        $this->applyContentsScale($this->contentsScale);
    }

    public function createSurface(int $instance): int
    {
        $blocks = new Blocks;
        $info = $blocks->keep((new VkMetalSurfaceCreateInfoEXT(
            pLayer: $this->layerPointer(),
        ))->pack());
        $surface = $blocks->create(
            'vkCreateMetalSurfaceEXT',
            static fn (int $out) => EXTMetalSurface::vkCreateMetalSurfaceEXT($instance, $info, 0, $out),
        );
        $blocks->release();

        return $surface;
    }

    public function destroySurface(int $instance, int $surface): void
    {
        KHRSurface::vkDestroySurfaceKHR($instance, $surface, 0);
    }

    public function release(): void
    {
        $this->layer = null;
    }

    /**
     * MoltenVK's currentExtent is bounds × contentsScale. A layer we mint
     * starts at 1.0; without this, a Retina view reports the point size.
     * ext-metal does not bind contentsScale (it lives on CALayer), so the
     * call goes through libobjc. A missing FFI is a no-op — SwapchainChoice
     * still prefers host pixels on a 2×/3× mismatch.
     */
    private function applyContentsScale(float $scale): void
    {
        $pointer = $this->layerPointer();
        if ($pointer === 0 || ! extension_loaded('ffi')) {
            return;
        }

        try {
            $objc = self::libobjc();
            if (is_null($objc)) {
                return;
            }

            $sel = $objc->sel_registerName('setContentsScale:');
            $self = $objc->cast('id', $pointer);
            $objc->objc_msgSend($self, $sel, $scale);
        } catch (\Throwable) {
            return;
        }
    }

    private static function libobjc(): ?\FFI
    {
        static $ffi = null;
        if (! is_null($ffi)) {
            return $ffi;
        }

        try {
            $ffi = \FFI::cdef(
                'typedef void *id; typedef void *SEL;'
                .'SEL sel_registerName(const char *name);'
                .'void objc_msgSend(id self, SEL op, double scale);',
                '/usr/lib/libobjc.A.dylib',
            );
        } catch (\Throwable) {
            return null;
        }

        return $ffi;
    }
}
