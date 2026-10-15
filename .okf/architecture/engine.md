---
type: Module
title: Engine
description: VulkanDevice - first non-CPU GPU, RGBA8 target kept in GENERAL, 4× colour and stencil behind a resolving render pass, five pipelines over one SPIR-V pair with push constants, one fenced command buffer a frame, staging readback and upload, swapchain present that never blocks.
resource: src/
tags: [vulkan, moltenvk, v3dv, gpu, drawing]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-06T12:00:00Z }
sources:
  - id: device
    resource: src/VulkanDevice.php
    title: VulkanDevice
  - id: framebuffer
    resource: src/VulkanFramebuffer.php
    title: VulkanFramebuffer
  - id: frag
    resource: resources/shaders/venusian.frag
    title: venusian.frag
---

# Overview

`VulkanDevice implements Surface\Drawing\Gpu\GpuDevice` over ext-vulkan. `GpuRenderingEngine` lowers drawing calls to a `DrawList`; `draw()` records it into one command buffer. `VulkanFramebuffer extends GLFramebuffer` over the resolved image.[^device][^framebuffer]

# Instance and device

* Instance extensions, each enabled only when the loader lists it: `VK_KHR_surface`, `VK_EXT_metal_surface` (macOS) or `VK_KHR_wayland_surface`, `VK_KHR_xcb_surface` and `VK_KHR_xlib_surface` (elsewhere: Qt makes the surface for the session it runs in), `VK_KHR_get_physical_device_properties2`, `VK_KHR_portability_enumeration` with its create flag. API 1.1.
* Physical device: the first whose type is not `CPU`. Limits kept: `maxImageDimension2D`, colour and stencil sample counts, `nonCoherentAtomSize`, `maxPushConstantsSize` (≥ 112 or the constructor throws).
* Queue: the first family with `GRAPHICS`. Device extensions: `VK_KHR_swapchain` (required), portability subset, external memory fd, dmabuf, DRM format modifier, each where listed.
* Stencil format: `D24_UNORM_S8_UINT` (V3DV) or `D32_SFLOAT_S8_UINT` (MoltenVK), the first with optimal depth-stencil attachment support.
* One command pool (reset per buffer) with three primary buffers: frame, one-time, present. One frame fence.

# Target

| Image | Format | Samples | Usage | Layout |
|---|---|---|---|---|
| resolved (the `VulkanFramebuffer`'s) | R8G8B8A8_UNORM | 1 | colour attachment, transfer src/dst, sampled | GENERAL for life |
| 4× colour | R8G8B8A8_UNORM | 4 | colour attachment | COLOR_ATTACHMENT_OPTIMAL between passes |
| stencil | the stencil format | 1 or 4 | depth-stencil attachment | cleared every pass |

The resolved image goes to GENERAL once, when made. Everything after (render, resolve, copy, upload, sample, blit) uses GENERAL, so the engine issues no layout barrier on it again.

# Render passes

One pass per colour load op, cached: CLEAR (target creation), LOAD (every frame), DONT_CARE (restore). Same formats and sample counts; the restore pass at 4× has no resolve attachment (it samples the target), and a single-subpass pass's resolve attachment does not count for compatibility, so one pipeline per kind serves all three.

* 4 samples: [0] 4× colour (load op as asked; initial COLOR_ATTACHMENT_OPTIMAL when loading, else UNDEFINED; stored), [1] stencil (cleared, not stored), [2] resolved as the resolve attachment (GENERAL in and out), except on the restore pass. The 4× colour loads every frame: a partial frame draws over the kept samples.
* 1 sample: [0] resolved as the colour attachment (GENERAL), [1] stencil.
* Dependencies: subpass → external (colour output and fragment tests → transfer, fragment shader, colour output) and external → subpass (transfer, fragment shader, colour output → colour output and fragment tests). Readback, blit and the next frame are ordered by these.

# Pipelines

One shader pair, vertex binding stride 8 (`R32G32_SFLOAT`), triangle lists, dynamic viewport and scissor, no culling, the target's sample count. Built against the LOAD pass.

| Kind | Colour | Blend | Stencil |
|---|---|---|---|
| solid | written | off | off |
| fill | written | source-over: colour `SRC_ALPHA, ONE_MINUS_SRC_ALPHA`, alpha `ONE, ONE_MINUS_SRC_ALPHA` | off |
| winding | mask 0 | off | always; front increment-wrap, back decrement-wrap |
| invert | mask 0 | off | always; invert both faces |
| cover | written | source-over | not-equal 0, pass zeroes |

Reference 0 is baked into each pipeline: MoltenVK's portability subset has no separate front/back reference, and none is needed. Non-zero filling is the same whichever face counts up, so the face assignment is not observable in output.

# Shaders and push constants

GLSL 450 compiled with `glslangValidator -V -g` (the source is embedded; `ShadersTest` reads it). The fragment body is venusian-sdl3's. Push constants: vertex `vec4 size` at [0, 16); fragment `Paint` at [16, 112): `uvec4 mode_rgba[2]`, `vec4 shape`, `band`, `abcd`, `efwh`, the same 96 bytes sdl3's `paintBytes()` packs. Set 0 binding 0 is the combined image sampler (nearest, clamp; the shader uses `texelFetch`). The vertex stage maps pixel y = 0 to clip −1: image row 0 is the top, readback is top-down with no flip.[^frag]

# A frame

1. `finish()`: wait the last frame's fence, free its transients.
2. Vertex buffer (host-visible, flushed): the list's vertices plus one whole-target quad.
3. Sources: a `VulkanFramebuffer` of this device is sampled through its own view, except the current target, which is this frame's attachment: it is blitted into a transient image before the passes and the copy is sampled. Anything else gets a sampled image, a staging buffer, and a copy recorded before the passes.
4. One descriptor pool for the frame: the blank 1 × 1 image (bound for every draw that samples nothing), one set per source, one for the restore.
5. Restore pass (4× only, after an upload marked the samples stale): the DONT_CARE pass, which has no resolve attachment, draws the resolved image back into the 4× colour in COPY mode. The samples count as restored only once the frame is submitted.
6. The frame's LOAD pass: viewport, scissor, vertex buffer, vertex push, blank set; per operation bind pipeline, push `Paint`, draw.
7. Submit with the fence. Nothing waits. The frame's objects are retired to the next `finish()`; a frame that throws before submit frees them at once.

# Readback and upload

Through the one-time command buffer, submitted and `vkQueueWaitIdle`'d. Staging memory is host-visible, coherent preferred. Upload: map, write, `vkFlushMappedMemoryRanges`, copy buffer → image. Readback: copy image → buffer, a `VkMemoryBarrier` transfer-write → host-read, map, `vkInvalidateMappedMemoryRanges`, read. Ranges are rounded up to `nonCoherentAtomSize` and capped at the allocation: right on non-coherent memory, free on coherent.

# Presenting

* `adopt()`: `VULKAN_SURFACE` takes `handle('surface')` as a `VkSurfaceKHR` (the canvas made it against `handles()['instance']` and owns it). `METAL_LAYER` sizes the layer's drawable to the surface and makes a `VkSurfaceKHR` with `vkCreateMetalSurfaceEXT` (the device owns it). `DMABUF` (Linux, with the external memory fd and dmabuf extensions; else refused naming what is missing) sets the device exporting: every target gets an export image beside it. The graphics family must support the surface. The swapchain is built at once; `onRelease()` destroys it when the canvas releases the surface.
* Swapchain: `B8G8R8A8_UNORM`, else `R8G8B8A8_UNORM`, else the first format that is not sRGB (the blit copies bytes; sRGB would encode them again); `MAILBOX` if offered else `FIFO` (MoltenVK offers FIFO and IMMEDIATE); min images + 1 within bounds; usage transfer-dst; extent = the current extent, or the surface's size when the surface leaves it to the swapchain (`0xFFFFFFFF`) or reports 0, clamped to the bounds.
* `present()`: false for a released surface; false while the last present's fence is unsignalled; false while the surface has no area (0 wide or high: the swapchain is not rebuilt, and is once it has); acquire with timeout 0 — `NOT_READY` / `TIMEOUT` false, `OUT_OF_DATE` rebuild and false, `SUBOPTIMAL` presents and rebuilds before the next. Then barrier the image to TRANSFER_DST, blit the resolved image (nearest, scaled to the extent), barrier to PRESENT_SRC, submit waiting on the acquire semaphore at the transfer stage, signalling the image's own render semaphore, with the present fence; `vkQueuePresentKHR` waits that semaphore. One render semaphore per swapchain image: it is reused only when its image is acquired again. A failed submit leaves the acquire semaphore signalled: it is made anew and the swapchain rebuilt on the next present. A failed swapchain create keeps the old one. `forget()` waits the present fence before it destroys a framebuffer's image, since the blit reads it.

# Dmabuf export

For GTK, which shows a texture it imports. Every target gets three export images: `R8G8B8A8_UNORM`, `LINEAR` tiling, transfer-dst, created with `VkExternalMemoryImageCreateInfo { DMA_BUF }`, allocated with `VkExportMemoryAllocateInfo { DMA_BUF }` chained to `VkMemoryDedicatedAllocateInfo` (allowed for any image in Vulkan 1.1, required for exported ones by some drivers; device-local and host-visible preferred), GENERAL once. `vkGetMemoryFdKHR` gives each fd; `vkGetImageSubresourceLayout` (colour aspect) gives stride and offset; fourcc `DRM_FORMAT_ABGR8888`, modifier `DRM_FORMAT_MOD_LINEAR`.

`present()` on the `DMABUF` surface takes an export the importer does not hold, blits the target into it at equal size, a memory barrier transfer-write → host and memory read, submits with the present fence and waits; the export is then held, and `dmabuf()` answers it. The importer hands it back with `releaseDmabuf($fd)`; GTK's canvas does from the texture's destroy notify, once GSK is done with it. So no export GTK may still sample is written, and with all three held `present()` copies nothing and answers false. A new target size or the surface's release destroys the images and their memory (the dmabuf's buffer lives on behind any open fd); a held fd stays open until it is handed back, after the device's `release()` too, and the rest close. `vk_close_fd()` errors in teardown are ignored: Linux closes the descriptor even when `close()` reports `EINTR`. V3DV: stride width × 4, offset 0.

# Release

A device let go while it still holds its surface (`release()`, or its destructor when its engine was never released) first drops the swapchain and the exports, then calls the surface's `release()`: the canvas's `onRelease()` takes it back (Qt destroys its window's `VkSurfaceKHR`) while this device's instance still exists. `adopt()` refuses a second surface of any kind.


`release()` (and the destructor): device wait idle, frame transients, swapchain and its semaphores and fence, the owned surface, target attachments, passes, framebuffers, pipelines, every live framebuffer's image, blank image, sampler, layouts, modules, fence, command pool, device, instance. Later calls throw `vulkan: this device was released.`; a `VulkanFramebuffer` destroyed after does nothing.

[^device]: src/VulkanDevice.php
[^framebuffer]: src/VulkanFramebuffer.php
[^frag]: resources/shaders/venusian.frag

# HDR (measured 2026-10-08)

`targetFormats()` on MoltenVK (M-series Mac) and V3DV (Pi 5): extended-linear sRGB / P3, HDR10, Display P3 (all four pairs). `hdrMetadata()` true on both. MoltenVK CAMetalLayer surface offers R16G16B16A16_SFLOAT + EXTENDED_SRGB_LINEAR, A2B10G10R10 / A2R10G10B10 + HDR10_ST2084, B8G8R8A8 + DISPLAY_P3_NONLINEAR, and more.

# Presenting and HDR

Present = show pass (no blit): target → `presentRect()` at swapchain size, window's filter, black bars, encoded for swapchain. Damage → `VkPresentRegionsKHR`. Vsync = surface present modes (FIFO on, FIFO_RELAXED adaptive, MAILBOX, IMMEDIATE off); FIFO paced by present wait: `present()` answers false while `frames_in_flight` presents unshown, never blocks. `frames_in_flight` 1-3: frame + present rings. `presents()` = frame numbers via `VK_KHR_present_wait` (no times).

HDR (needs `VK_EXT_swapchain_colorspace` + a surface offering the pair): rgba16float extended-linear sRGB / P3 (EXTENDED_SRGB_LINEAR, × window SDR white), rgb10a2 HDR10 (HDR10_ST2084 + `vkSetHdrMetadataEXT`), rgba8 Display P3. Target = linear half floats, SDR white 1.0.

    $engine = $drawing->engine('vulkan', ['output' => $window, 'format' => TargetFormat::Rgb10A2, 'colorspace' => ColorSpace::Hdr10Pq]);

Measured 2026-10-08: MoltenVK (Mac) and V3DV (Pi 5) render all four pairs and take HDR metadata; MoltenVK's CAMetalLayer offers extended-linear and HDR10 swapchains.
