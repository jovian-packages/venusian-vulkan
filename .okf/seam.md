---
type: Decision
title: The Vulkan layer seam
description: >-
  venusian-vulkan mints a CAMetalLayer through jovian/metal and answers
  raw pointer bits; venusian-appkit adopts them. Neither side imports the
  other. MoltenVK currentExtent is bounds × contentsScale, not drawableSize.
resource: src/VulkanEngine.php
tags: [vulkan, appkit, pointer-seam, layer, moltenvk, retina]
status: draft
generated:
  by: cursor-grok-4.6/cursor
  at: "2026-09-14T04:45:00Z"
sources:
  - id: spec
    resource: ../../venusian/surface/docs/superpowers/specs/2026-09-13-gpu-drawing-slice3-vulkan-design.md
    title: GPU drawing slice 3 design
  - id: metal-runtime
    resource: ../metal/.okf/runtime.md
    title: jovian/metal runtime — pointerOf / adopt
---

# Overview

Raw pointer bits are the only currency between Vulkan and AppKit. MoltenVK
presents into a `CAMetalLayer` through `VK_EXT_metal_surface`. Registry
handles from one extension are meaningless in the other.[^metal-runtime]

# Who does what

| Side | Mints | Hands over | Releases |
|---|---|---|---|
| `jovian/venusian-vulkan` | `CAMetalLayer::init()`, `drawableSize = host × scale`. MoltenVK owns device, pixelFormat, framebufferOnly | `Bridge::pointerOf($layer->handle)` and `'CAMetalLayer'` on `GPUAttachment` | The layer box on `VulkanExecutor::release()`, after every Vulkan object that referenced it |
| `jovian/venusian-appkit` | The host `NSView` | Nothing Vulkan-ward except the `GPUHost` size/scale | The adopted box on `destroyNative()` |

This package never imports `Jovian\Bindings\AppKit\*` or
`Jovian\Venusian\AppKit\*`. `jovian/metal` is imported by **one file**
(`Hosts/MetalLayerHost`). AppKit adopts; Vulkan does not call `adopt`.[^spec]

# Hold the box

```php
$layer = CAMetalLayer::init();
$layer->setDrawableSize(new CGSize((float) $w, (float) $h));
$pointer = MetalBridge::pointerOf($layer->handle);   // layer still live
```

`CAMetalLayer::init()->handle` is already released by the time
`pointerOf` would run. The host holds the layer for the executor's life
so the bits stay valid while AppKit's twin holds the adopted retain.

# What attach answers

`GPUAttachment(executor, layer_pointer > 0, layer_class === 'CAMetalLayer')`.
`drawableSize()` is pixels: host points × backing scale. A 320×240 host at
scale 2 is `[640, 480]`.

`VulkanEngine::surfaceKind()` answers `SurfaceKind::LAYER`. GTK still
refuses that kind with `GPUViewException::unsupported()`. A Wayland host
is a later slice; until then there is no Linux Vulkan path.

# Portability by enumeration

No `PHP_OS_FAMILY` in `src/`. The instance enables
`VK_KHR_portability_enumeration` and `ENUMERATE_PORTABILITY_BIT_KHR`
when the loader lists the extension; the device enables
`VK_KHR_portability_subset` the same way. A headless boot (`wsi === ''`)
skips `VK_KHR_surface` / WSI / swapchain so Feature context tests run
without a window.

# MoltenVK currentExtent

A bare layer that is not yet on screen reports `currentExtent` 0×0, not
the spec's `0xFFFFFFFF`. `SwapchainChoice` treats a zero extent as
undefined and clamps the host pixel size.

Once the layer is on an `NSView`, MoltenVK fills `currentExtent` from
`naturalDrawableSizeMVK`: **bounds × contentsScale**, not the
`drawableSize` we set. A layer we mint starts at `contentsScale` 1.0, so
a Retina view reports the **point** size. Painter still emits
`points × GPUView.scale` vertices. The scene looks 2× zoomed — Engine
Trio's Vulkan column, 2026-09-14.

`MetalLayerHost` sets `contentsScale` to the host backing scale (libobjc;
ext-metal does not bind the CALayer property) on every
`setDrawableSize`. `SwapchainChoice` also prefers host pixels when
`currentExtent` is a 2× or 3× divisor of the host — the same Retina
mismatch if the scale write does not stick. `SUBOPTIMAL` after that is
chronic, not a resize; the executor presents and does not recreate.

[^spec]: GPU drawing slice 3 design
[^metal-runtime]: jovian/metal runtime — pointerOf / adopt
