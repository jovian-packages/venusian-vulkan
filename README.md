# jovian/venusian-vulkan

Vulkan composition for Venusian Surface GPU drawing.

`jovian/vulkan` projects `ext-vulkan` one call at a time and adds no behaviour.
This package is where a frame, a swapchain, and the MoltenVK layer seam live.
Surface talks to it only through `gpu.vulkan`.

```
ext-vulkan  →  jovian/vulkan  →  venusian-vulkan  →  Surface
  1:1            typed             composition        cross-platform
```

It never imports AppKit. The only crossing is raw pointer bits: `attach()`
answers `Bridge::pointerOf($layer->handle)` and `'CAMetalLayer'`; the AppKit
window twin adopts that pointer on its side. `jovian/metal` is imported by
one file — `Hosts/MetalLayerHost`.

## Install

```bash
composer require jovian/venusian-vulkan
```

Requires PHP `^8.4|^8.5|^8.6`, `jovian/vulkan`, `jovian/metal`, and the
Surface drawing contracts. Installing the package registers
`VenusianVulkanServiceProvider`, which binds `VulkanEngine` as the
`gpu.vulkan` singleton.

## The shape of it

```php
$window->gpu('scene', 'vulkan', 20, 20, 640, 560)
    ->onDraw(fn (Drawing2D $g, Frame $f) => …);

$executor = $gpu->executor();
if ($executor instanceof VulkanDrawing) {
    $executor->device();
}
```

A frame is `vkAcquireNextImageKHR` → clear pass held open → draws staged
through a persistently mapped host-visible buffer → `vkQueueSubmit` /
`vkQueuePresentKHR`. One frame is in flight. Staging growth and texture
release retire handles into a ledger; `reap()` runs after the frame fence.

The transform is a 64-byte vertex push constant (`Transform::toPacked()`).
Untextured draws bind a 1×1 white placeholder. The vertex shader flips
`clip.y` because Vulkan NDC is y-down and Surface's orthographic is y-up.
Viewport and scissor stay in Surface's top-left pixel space.

`capabilities()` answers `blending=true`, `depth=false`, `instancing=true`,
`readback=true`. `readPixels()` is mid-frame: end the pass, copy the
swapchain image into a host-visible buffer, wait the fence, swizzle
BGRA→RGBA when needed, then reopen with the load pass.

MoltenVK reports `currentExtent` as 0×0 on a layer that is not yet on
screen; the picker treats that as undefined and uses the host pixel size.
Once the layer is on a view, `currentExtent` is `bounds × contentsScale`.
A mint layer starts at scale 1.0, so Retina would report points and the
scene would look 2×. `MetalLayerHost` writes `contentsScale` to the
backing scale; the picker also prefers host pixels when the reported
extent is a 2× or 3× point-size of the host.

## Ownership

The `CAMetalLayer` box lives for the executor's life (the box-drop rule).
Per-frame state lives on `VulkanFrame` and is dropped at `endFrame()`.
The process-wide `VulkanContext` (instance, device, modules, layouts,
sampler, placeholder) is cached on `VulkanEngine` and is not released by
an executor.
