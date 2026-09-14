---
type: Component
title: VulkanExecutor — frame, staging, readback
description: >-
  How a Vulkan frame is opened, how vertices ride a persistently mapped
  staging buffer, and how mid-frame readPixels copies the swapchain then
  reopens the load pass.
resource: src/VulkanExecutor.php
tags: [vulkan, executor, frame, readback, staging, moltenvk]
status: draft
generated:
  by: cursor-grok-4.6/cursor
  at: "2026-09-14T04:45:00Z"
sources:
  - id: spec
    resource: ../../venusian/surface/docs/superpowers/specs/2026-09-13-gpu-drawing-slice3-vulkan-design.md
    title: GPU drawing slice 3 design
  - id: contracts
    resource: ../../venusian/surface/src/Surface/Contracts/Drawing
    title: Phase A drawing contracts
  - id: plan
    resource: src/VulkanExecutor.php
    title: VulkanExecutor
---

# Overview

`VulkanExecutor` implements `Surface\Contracts\Drawing\Executor` and
`Contracts\VulkanDrawing`. One instance owns one `VkSurfaceKHR` and one
swapchain for its life. Per-frame state lives on a `VulkanFrame` dropped
at `endFrame()`.[^spec]

# Frame lifecycle

1. `beginFrame($clear)` — wait and reset the frame fence if pending,
   `reap()` retired handles, recreate the swapchain if flagged (the only
   `vkDeviceWaitIdle` between frames). `vkAcquireNextImageKHR` of
   `OUT_OF_DATE` rebuilds once and retries; a second miss answers false.
   `SUBOPTIMAL` presents without a recreate — MoltenVK says that when
   `drawableSize` is @2x and `contentsScale` is still 1. Then reset/begin the
   command buffer, open the clear pass, full viewport, full scissor.
2. `draw` / `drawIndexed` / `scissor` / `viewport` / `readPixels` — legal
   only while that pass is open. Outside a frame they throw
   `VulkanDrawingException::outOfFrame()`.
3. `endFrame()` — end the pass, submit signalling `renderFinished`
   (waiting the acquire semaphore only if `readPixels` has not already),
   present. `OUT_OF_DATE` flags recreate; `SUBOPTIMAL` does not.

`release()` is terminal and idempotent: abandon recording, device idle,
reap, destroy textures / staging / readback / swapchain / pipelines /
passes / sync / pool / surface, then `host->release()` so the layer
outlives every Vulkan object that referenced it.

# Staging

One host-visible, host-coherent buffer (initial 256 KiB), kept mapped.
`StagingPlan::place($cursor, $bytes)` 4-aligns the cursor. Overflow
`retire()`s the old buffer, allocates `max(2 × old, needed)`, and resets
the cursor to 0. Draws already recorded keep binding the old buffer until
the next fence wait reaps it.

- Vertex bytes → `vkCmdBindVertexBuffers` at the staged offset
- Index bytes → `vkCmdBindIndexBuffer` `UINT16`
- `Transform::toPacked()` → 64-byte `VERTEX` push constants
- Texture descriptor set, or the context's 1×1 white placeholder → set 0

Vertex layout is the Phase A contract: `x y z r g b a u v`, nine floats,
`pack('g9')`, stride 36. The shader reads `vec4(aPos.xy, 0, 1)` and
negates `clip.y`.[^contracts]

# Readback

`readPixels()` is mid-frame. Path:

1. `vkCmdEndRenderPass` (final layout is already `PRESENT_SRC_KHR`)
2. Barrier `PRESENT_SRC → TRANSFER_SRC`
3. `vkCmdCopyImageToBuffer` into a lazily sized host-visible buffer
4. Submit (wait acquire only if not yet waited), fence wait
5. Map/read `w×h×4`; swizzle BGRA→RGBA when the swapchain is BGRA
6. Reset/begin the command buffer, open the load pass
   (`initialLayout TRANSFER_SRC_OPTIMAL`), restore viewport and scissor

Outside a frame → `VulkanDrawingException`. `readback` capability is true.

# Capabilities

`blending=true`, `depth=false`, `instancing=true`, `readback=true`,
`max_texture_size = limits.maxImageDimension2D`. Pipelines blend
`SRC_ALPHA / ONE_MINUS_SRC_ALPHA`. No depth attachment.

# Ownership

Vulkan handles are PHP `int`s. Long-lived `Bridge::alloc` packs live on
the executor's `Blocks` scratch and are `packInto`'d every call. The
process-wide `VulkanContext` is not released here.

[^spec]: GPU drawing slice 3 design
[^contracts]: Phase A drawing contracts
[^plan]: VulkanExecutor
