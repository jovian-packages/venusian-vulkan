<?php

declare(strict_types=1);

use Surface\Drawing\Gpu\GpuRenderingEngine;
use Venusian\Surface\Tests\Support\GpuParity\GpuParity;

it('boots with Surface\'s GPU engine and its parity suite in reach', function (): void {
    expect(class_exists(GpuRenderingEngine::class))->toBeTrue()
        ->and(class_exists(GpuParity::class))->toBeTrue()
        ->and(extension_loaded('vulkan'))->toBeTrue();
});

it('has ext-metal on macOS, where the Metal layer is the surface', function (): void {
    expect(PHP_OS_FAMILY !== 'Darwin' || class_exists(CAMetalLayer::class))->toBeTrue('macOS presents into a CAMetalLayer: ext-metal must be loaded.');
});
