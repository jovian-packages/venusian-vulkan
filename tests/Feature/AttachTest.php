<?php

declare(strict_types=1);

use Jovian\Bindings\Metal\Runtime\Lifetime;
use Jovian\Bindings\Metal\Runtime\Registry;
use Jovian\Venusian\Vulkan\Contracts\VulkanDrawing;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Hosts\MetalLayerHost;
use Jovian\Venusian\Vulkan\VulkanEngine;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\NativeWindows\Views\Color;

beforeEach(function () {
    if (PHP_OS_FAMILY !== 'Darwin' || ! vulkanExtensionLoaded() || ! metalExtensionLoaded()) {
        test()->markTestSkipped('AttachTest needs Darwin + ext-vulkan + ext-metal');
    }

    Lifetime::reset();
    Registry::reset();
});

afterEach(function () {
    if (! metalExtensionLoaded()) {
        return;
    }

    Registry::reset();
    Lifetime::reset();
});

it('attaches a CAMetalLayer and draws the proof triangle', function () {
    $engine = new VulkanEngine;
    $attachment = $engine->attach(new GPUHost(0, 64, 64, 1.0));

    expect($attachment->layer_class)->toBe('CAMetalLayer')
        ->and($attachment->layer_pointer)->toBeGreaterThan(0)
        ->and($attachment->executor)->toBeInstanceOf(VulkanDrawing::class);

    $executor = $attachment->executor;
    $executor->resize(64, 64);
    expect($executor->drawableSize())->toBe([64, 64]);

    expect($executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0)))->toBeTrue();

    $size = 64;
    $half = $size / 2.0;
    $vertices = vulkanVertex($half, $half - 0.8 * $half, 1.0, 0.5, 0.25)
        .vulkanVertex($half - 0.8 * $half, $half + 0.8 * $half, 1.0, 0.5, 0.25)
        .vulkanVertex($half + 0.8 * $half, $half + 0.8 * $half, 1.0, 0.5, 0.25);
    $executor->draw(Topology::TRIANGLES, $vertices, 3, Transform::orthographic($size, $size));

    $pixels = $executor->readPixels();
    expect(strlen($pixels))->toBe($size * $size * 4);
    $centre = array_values(unpack('C4', substr($pixels, (intdiv($size, 2) * $size + intdiv($size, 2)) * 4, 4)));
    $corner = array_values(unpack('C4', substr($pixels, 0, 4)));

    expect($centre[0])->toBeGreaterThanOrEqual(240)
        ->and($centre[1])->toBeBetween(100, 155)
        ->and($centre[2])->toBeBetween(48, 80)
        ->and($centre[3])->toBe(255)
        ->and($corner)->toBe([0, 0, 0, 255]);

    $executor->endFrame();
    $executor->release();
    expect(fn () => $executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0)))
        ->toThrow(VulkanDrawingException::class);
});

it('a scissor in top-left pixels clips the bottom half, not the top', function () {
    $executor = (new VulkanEngine)->attach(new GPUHost(0, 16, 16, 1.0))->executor;
    $size = 16;
    expect($executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0)))->toBeTrue();

    $executor->scissor(0, 8, 16, 8);
    $quad = vulkanVertex(0, 0, 1, 0, 0)
        .vulkanVertex(16, 0, 1, 0, 0)
        .vulkanVertex(0, 16, 1, 0, 0)
        .vulkanVertex(16, 16, 1, 0, 0);
    $executor->drawIndexed(Topology::TRIANGLES, $quad, 4, pack('v*', 0, 1, 2, 2, 1, 3), 6, Transform::orthographic($size, $size));

    $pixels = $executor->readPixels();
    $top = array_values(unpack('C4', substr($pixels, (2 * $size + 8) * 4, 4)));
    $bottom = array_values(unpack('C4', substr($pixels, (13 * $size + 8) * 4, 4)));
    expect($top)->toBe([0, 0, 0, 255])->and($bottom[0])->toBe(255);

    $executor->endFrame();
    $executor->release();
});

it('uploads an RGBA8 texture and samples it', function () {
    $executor = (new VulkanEngine)->attach(new GPUHost(0, 8, 8, 1.0))->executor;
    $size = 8;
    expect($executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0)))->toBeTrue();

    $texture = $executor->texture(str_repeat(pack('C4', 0, 255, 0, 255), 4), 2, 2);
    $v = fn (float $x, float $y, float $u, float $t) => vulkanVertex($x, $y, 1.0, 1.0, 1.0, 1.0, $u, $t);
    $quad = $v(0, 0, 0, 0).$v(8, 0, 1, 0).$v(0, 8, 0, 1).$v(8, 8, 1, 1);
    $executor->drawIndexed(Topology::TRIANGLES, $quad, 4, pack('v*', 0, 1, 2, 2, 1, 3), 6, Transform::orthographic($size, $size), $texture);

    $centre = array_values(unpack('C4', substr($executor->readPixels(), (4 * $size + 4) * 4, 4)));
    expect($centre)->toBe([0, 255, 0, 255]);

    $executor->releaseTexture($texture);
    $executor->endFrame();
    $executor->release();
});

it('writes contentsScale from the host backing scale so MoltenVK currentExtent is pixels', function () {
    if (! extension_loaded('ffi')) {
        test()->markTestSkipped('FFI is required to read CALayer.contentsScale');
    }

    $host = MetalLayerHost::mint(800, 600, 2.0);
    $objc = \FFI::cdef(
        'typedef void *id; typedef void *SEL;'
        .'SEL sel_registerName(const char *name);'
        .'double objc_msgSend(id self, SEL op);',
        '/usr/lib/libobjc.A.dylib',
    );
    $scale = $objc->objc_msgSend(
        $objc->cast('id', $host->layerPointer()),
        $objc->sel_registerName('contentsScale'),
    );
    $host->release();

    expect($scale)->toBe(2.0);
});

it('reuses the engine context on a second attach', function () {
    $engine = new VulkanEngine;
    $first = $engine->attach(new GPUHost(0, 32, 32, 1.0));
    $instance = $first->executor->instance();
    $device = $first->executor->device();
    $first->executor->release();

    $second = $engine->attach(new GPUHost(0, 32, 32, 1.0));
    expect($second->executor->instance())->toBe($instance)
        ->and($second->executor->device())->toBe($device)
        ->and($second->layer_class)->toBe('CAMetalLayer');
    $second->executor->release();
});
