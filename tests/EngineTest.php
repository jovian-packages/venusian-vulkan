<?php

declare(strict_types=1);

use Jovian\Venusian\Vulkan\Contracts\SurfaceHost;
use Jovian\Venusian\Vulkan\Contracts\VulkanDrawing;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Hosts\MetalLayerHost;
use Jovian\Venusian\Vulkan\Providers\VenusianVulkanServiceProvider;
use Jovian\Venusian\Vulkan\VulkanEngine;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Drawing\SurfaceKind;

it('declares VULKAN and LAYER', function () {
    $engine = new VulkanEngine;

    expect($engine)->toBeInstanceOf(GPUEngineDriver::class)
        ->and($engine->engine())->toBe(GPUEngine::VULKAN)
        ->and($engine->surfaceKind())->toBe(SurfaceKind::LAYER);
});

it('is published behind the gpu.vulkan alias', function () {
    $app = new class
    {
        /** @var list<string> */
        public array $singletons = [];

        /** @var array<string, string> */
        public array $aliases = [];

        public function singleton(string $abstract): void
        {
            $this->singletons[] = $abstract;
        }

        public function alias(string $abstract, string $alias): void
        {
            $this->aliases[$alias] = $abstract;
        }
    };

    $provider = new VenusianVulkanServiceProvider($app);
    $provider->register();

    expect($app->singletons)->toContain(VulkanEngine::class)
        ->and($app->aliases['gpu.vulkan'] ?? null)->toBe(VulkanEngine::class);
});

it('exposes the SurfaceHost and VulkanDrawing contracts the plan named', function () {
    $host = array_map(
        static fn (\ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(SurfaceHost::class))->getMethods(),
    );
    $drawing = array_map(
        static fn (\ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(VulkanDrawing::class))->getMethods(),
    );

    expect($host)->toEqualCanonicalizing([
        'wsiExtension',
        'layerPointer',
        'layerClass',
        'setDrawableSize',
        'setContentsScale',
        'createSurface',
        'release',
    ])->and($drawing)->toEqualCanonicalizing([
        'instance',
        'physicalDevice',
        'device',
        'queue',
        'queueFamily',
        'surface',
        'swapchain',
        'swapchainFormat',
        'commandBuffer',
        'renderPass',
        'pipelineLayout',
        'loaderVersion',
        'retirementPending',
    ]);
});

it('MetalLayerHost implements SurfaceHost and refuses a mint without ext-metal', function () {
    expect(is_a(MetalLayerHost::class, SurfaceHost::class, true))->toBeTrue();

    if (metalExtensionLoaded()) {
        test()->markTestSkipped('ext-metal is loaded — mint is proven in Feature/AttachTest');
    }

    expect(fn () => MetalLayerHost::mint(64, 64))
        ->toThrow(VulkanDrawingException::class);
});
