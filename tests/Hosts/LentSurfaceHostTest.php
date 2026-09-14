<?php

declare(strict_types=1);

use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Hosts\LentSurfaceHost;

it('names the WSI extension the host needs, not VK_KHR_surface', function () {
    expect((new LentSurfaceHost(lender()))->wsiExtension())->toBe('VK_KHR_wayland_surface');
});

it('names the xlib WSI a Pi SDL lends under XWayland', function () {
    expect((new LentSurfaceHost(lender(['VK_KHR_surface', 'VK_KHR_xlib_surface'])))->wsiExtension())
        ->toBe('VK_KHR_xlib_surface');
});

it('names the WSI past a non-WSI extension the host also needs', function () {
    expect((new LentSurfaceHost(lender(['VK_KHR_surface', 'VK_KHR_portability_enumeration', 'VK_EXT_metal_surface'])))->wsiExtension())
        ->toBe('VK_EXT_metal_surface')
        ->and((new LentSurfaceHost(lender(['VK_KHR_surface', 'VK_KHR_display'])))->wsiExtension())
        ->toBe('VK_KHR_display');
});

it('refuses a host that names no WSI extension', function () {
    expect(fn () => (new LentSurfaceHost(lender(['VK_KHR_surface'])))->wsiExtension())
        ->toThrow(VulkanDrawingException::class)
        ->and(fn () => (new LentSurfaceHost(lender(['VK_KHR_surface', 'VK_KHR_portability_enumeration'])))->instanceExtensions())
        ->toThrow(VulkanDrawingException::class);
});

it('hands the engine every instance extension the host names', function () {
    expect((new LentSurfaceHost(lender(['VK_KHR_surface', 'VK_KHR_portability_enumeration', 'VK_EXT_metal_surface'])))->instanceExtensions())
        ->toBe(['VK_KHR_surface', 'VK_KHR_portability_enumeration', 'VK_EXT_metal_surface'])
        ->and((new LentSurfaceHost(lender(['VK_KHR_xlib_surface'])))->instanceExtensions())
        ->toBe(['VK_KHR_surface', 'VK_KHR_xlib_surface']);
});

it('gives the surface back to the host that lent it', function () {
    $lent = lender();
    (new LentSurfaceHost($lent))->destroySurface(4242, 77);

    expect($lent->destroyed)->toBe([[4242, 77]]);
});

it('asks the host for the surface on the engine instance', function () {
    $lent = lender();
    $host = new LentSurfaceHost($lent);

    expect($host->createSurface(4242))->toBe(77)
        ->and($lent->instances)->toBe([4242])
        ->and($host->layerPointer())->toBe(0)
        ->and($host->layerClass())->toBe('');
});

it('treats a zero surface as the host refusing', function () {
    expect(fn () => (new LentSurfaceHost(lender(surface: 0)))->createSurface(1))
        ->toThrow(VulkanDrawingException::class);
});
