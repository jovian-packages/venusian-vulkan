<?php

declare(strict_types=1);

use Jovian\Bindings\Metal\Runtime\Lifetime;
use Jovian\Bindings\Metal\Runtime\Registry;
use Jovian\Bindings\Vulkan\Ext\KHRSurface;
use Jovian\Venusian\Vulkan\Contracts\SurfaceHost;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Hosts\MetalLayerHost;
use Jovian\Venusian\Vulkan\VulkanContext;
use Jovian\Venusian\Vulkan\VulkanEngine;
use Jovian\Venusian\Vulkan\VulkanExecutor;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\VulkanSurfaceLender;

beforeEach(function () {
    if (! vulkanExtensionLoaded()) {
        test()->markTestSkipped('ext-vulkan is not loaded');
    }

    if (metalExtensionLoaded()) {
        Lifetime::reset();
        Registry::reset();
    }
});

afterEach(function () {
    if (metalExtensionLoaded()) {
        Registry::reset();
        Lifetime::reset();
    }
});

it('boots on the lender WSI and asks the lender for its surface', function () {
    $wsi = listedWsi(VulkanContext::listedInstanceExtensions());
    if (is_null($wsi)) {
        test()->markTestSkipped('the loader lists no window-system surface extension');
    }
    $lent = lender(['VK_KHR_surface', $wsi], surface: 0);   // the lender refuses: proves the route without a window

    expect(fn () => (new VulkanEngine)->attach(new GPUHost(0, 64, 64, 1.0, vk: $lent)))
        ->toThrow(VulkanDrawingException::class)
        ->and($lent->instances)->toHaveCount(1);
});

it('adopts a lent CAMetalLayer and hands no layer back (macOS)', function () {
    if (PHP_OS_FAMILY !== 'Darwin' || ! metalExtensionLoaded()) {
        test()->markTestSkipped('macOS + ext-metal only');
    }

    $layer = \Jovian\Bindings\Metal\QuartzCore\CAMetalLayer::init();
    $bits = \Jovian\Bindings\Metal\Runtime\Bridge::pointerOf($layer->handle);

    $attachment = (new VulkanEngine)->attach(new GPUHost(0, 64, 64, 1.0, layer: $bits));

    expect($attachment->layer_pointer)->toBe(0)
        ->and($attachment->executor->drawableSize())->toBe([64, 64]);

    $attachment->executor->release();
});

it('gives back only the retain it took on a foreign lent layer (macOS)', function () {
    if (PHP_OS_FAMILY !== 'Darwin' || ! metalExtensionLoaded() || ! extension_loaded('ffi')) {
        test()->markTestSkipped('macOS + ext-metal + FFI only');
    }

    $warm = \Jovian\Bindings\Metal\QuartzCore\CAMetalLayer::init();   // QuartzCore is loaded
    $objc = \FFI::cdef(
        'typedef void *id; typedef void *SEL;'
        .'id objc_getClass(const char *name); SEL sel_registerName(const char *name);'
        .'id objc_msgSend(id self, SEL op);',
        '/usr/lib/libobjc.A.dylib',
    );
    $count = \FFI::cdef(
        'typedef void *id; typedef void *SEL;'
        .'SEL sel_registerName(const char *name); unsigned long objc_msgSend(id self, SEL op);',
        '/usr/lib/libobjc.A.dylib',
    );

    // A layer ext-metal has never seen — what SDL_Metal_GetLayer hands a stage host.
    $layer = $objc->objc_msgSend($objc->objc_getClass('CAMetalLayer'), $objc->sel_registerName('new'));
    $bits = $objc->new('uintptr_t');
    \FFI::memcpy(\FFI::addr($bits), \FFI::addr($layer), 8);
    $pointer = (int) $bits->cdata;
    $retains = static fn (): int => $count->objc_msgSend($count->cast('id', $pointer), $count->sel_registerName('retainCount'));
    $before = $retains();

    $attachment = (new VulkanEngine)->attach(new GPUHost(0, 64, 64, 2.0, layer: $pointer));
    expect($attachment->layer_pointer)->toBe(0)
        ->and($attachment->executor->drawableSize())->toBe([128, 128])
        ->and($retains())->toBeGreaterThan($before);

    $attachment->executor->release();
    unset($attachment);
    gc_collect_cycles();

    expect($retains())->toBe($before);

    $objc->objc_msgSend($layer, $objc->sel_registerName('release'));
    unset($warm);
});

it('enables every instance extension the lender names', function () {
    $listed = VulkanContext::listedInstanceExtensions();
    $wsi = listedWsi($listed);
    $extra = array_values(array_filter(
        ['VK_KHR_portability_enumeration', 'VK_KHR_get_surface_capabilities2', 'VK_EXT_swapchain_colorspace'],
        static fn (string $extension): bool => in_array($extension, $listed, true),
    ));
    if (is_null($wsi) || $extra === []) {
        test()->markTestSkipped('the loader lists no WSI, or no extra instance extension to lend');
    }
    $names = ['VK_KHR_surface', $extra[0], $wsi];
    $engine = new VulkanEngine;

    expect(fn () => $engine->attach(new GPUHost(0, 64, 64, 1.0, vk: lender($names, surface: 0))))
        ->toThrow(VulkanDrawingException::class);

    $context = $engine->context(array_reverse($names));   // cached by the set, not the order
    expect($context->enabledInstanceExtensions)->toContain(...$names);
});

it('hands a lent surface back to the lender exactly once on release (macOS)', function () {
    if (PHP_OS_FAMILY !== 'Darwin' || ! metalExtensionLoaded()) {
        test()->markTestSkipped('macOS + ext-metal only — a real surface needs a window elsewhere');
    }

    // A lender over a real MoltenVK surface; its destroy is the only destroy.
    $lender = new class implements VulkanSurfaceLender
    {
        /** @var list<array{int, int}> */
        public array $created = [];

        /** @var list<array{int, int}> */
        public array $destroyed = [];

        private ?MetalLayerHost $layer = null;

        public function instanceExtensions(): array
        {
            return ['VK_KHR_surface', 'VK_EXT_metal_surface'];
        }

        public function createSurface(int $instance): int
        {
            $this->layer = MetalLayerHost::mint(64, 64);
            $surface = $this->layer->createSurface($instance);
            $this->created[] = [$instance, $surface];

            return $surface;
        }

        public function destroySurface(int $instance, int $surface): void
        {
            $this->destroyed[] = [$instance, $surface];
            KHRSurface::vkDestroySurfaceKHR($instance, $surface, 0);
            $this->layer?->release();
            $this->layer = null;
        }

        public function drawableSize(): array
        {
            return [64, 64];
        }
    };

    $executor = (new VulkanEngine)->attach(new GPUHost(0, 64, 64, 1.0, vk: $lender))->executor;
    $instance = $executor->instance();
    expect($lender->destroyed)->toBe([]);

    $executor->release();
    $executor->release();

    expect($lender->destroyed)->toHaveCount(1)
        ->and($lender->destroyed)->toBe($lender->created)
        ->and($lender->destroyed[0][0])->toBe($instance);
});

it('gives the surface back through the host when the executor fails after creating it', function () {
    $host = new class implements SurfaceHost
    {
        /** @var list<array{int, int}> */
        public array $destroyed = [];

        public int $released = 0;

        public function wsiExtension(): string
        {
            return 'VK_KHR_xlib_surface';
        }

        public function instanceExtensions(): array
        {
            return [];
        }

        public function layerPointer(): int
        {
            return 0;
        }

        public function layerClass(): string
        {
            return '';
        }

        public function setDrawableSize(int $width, int $height): void
        {
            throw new VulkanDrawingException('host went away mid-attach');
        }

        public function setContentsScale(float $scale): void {}

        public function createSurface(int $instance): int
        {
            return 77;   // never presented: the throwing step runs before the swapchain
        }

        public function destroySurface(int $instance, int $surface): void
        {
            $this->destroyed[] = [$instance, $surface];
        }

        public function release(): void
        {
            $this->released++;
        }
    };
    $context = vulkanContext();

    expect(fn () => new VulkanExecutor($context, $host, 64, 64))
        ->toThrow(VulkanDrawingException::class, 'host went away mid-attach')
        ->and($host->destroyed)->toBe([[$context->instance, 77]])
        ->and($host->released)->toBe(1);
});
