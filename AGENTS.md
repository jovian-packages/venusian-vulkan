# Agent guidelines — jovian/venusian-vulkan

## Knowledge Bundle (OKF)

This package ships an Open Knowledge Format bundle at [`.okf/`](.okf/)
(excluded from the Composer dist via `.gitattributes` `export-ignore`).
Before changing code or advising on this package: read
[`.okf/index.md`](.okf/index.md) first, open only the concepts the task
needs, prefer `status: stable` over `draft`. When you learn something
durable, update the affected concept(s) and append [`.okf/log.md`](.okf/log.md);
new or changed concepts stay `status: draft` until a human verifies them.

Do **not** create `.okf` folders under `src/` — knowledge for this package
lives at the package root only.

## Where this package sits

`ext-vulkan` (1:1 binding, zero opinion) → `jovian/vulkan` (enums + typed
projection) → **`jovian/venusian-vulkan`** (composition) → `venusian/surface`
(cross-platform abstraction).

**This is the layer where opinion is allowed.** `jovian/vulkan` may only
project one extension call per method, and Surface may not know Vulkan
exists, so everything that bundles Vulkan calls into a frame, a swapchain,
or a pointer seam belongs here.

Never import `Jovian\Bindings\AppKit\*` or `Jovian\Venusian\AppKit\*`.
Never depend on `jovian/appkit` or `jovian/venusian-appkit`. The only
crossing is raw pointer bits: `MetalLayerHost` answers
`jovian/metal`'s `Bridge::pointerOf($layer->handle)`; the AppKit side
adopts. `jovian/metal` is imported by **one file** (`Hosts/MetalLayerHost`).

Never import a `Jovian\` engine symbol from Surface — Surface resolves
`gpu.vulkan` and knows nothing else. Never build a cross-platform
abstraction here — that is Surface's job.

## Package rules (quick) — 0.8.x

- Composer: `jovian/venusian-vulkan` **0.8.0**. PHP `^8.4|^8.5|^8.6`.
  Requires `jovian/vulkan`, `jovian/metal`, `surface/contracts`,
  `surface/drawing`, `venusian-voyager/contracts`,
  `venusian-voyager/nuts-and-bolts`.
- Namespace root is `Jovian\Venusian\Vulkan\` at `src/`. Tests live under
  `Venusian\Tests\`.
- **The provider binds `gpu.vulkan`.** That container alias is the entire
  seam to Surface; installing this package is the whole of what makes the
  Vulkan engine available. Do not rename it.
- **`surfaceKind()` is `LAYER`.** MoltenVK presents into a `CAMetalLayer`
  through `VK_EXT_metal_surface`. A Linux Wayland host is a later slice.
- **Implement Surface's drawing contracts, do not re-declare policy.**
  `Executor` and `GPUEngineDriver` own the intersection. `Contracts\VulkanDrawing`
  is the bespoke half a sketch reaches beside the Painter, through
  `$gpu->executor()` and `instanceof VulkanDrawing`. No `GPUView::vulkan()`.
- **Exceptions subclass `Surface\Contracts\Drawing\DrawingException`** so a
  sketch catches one type without naming Vulkan.
- **Handles are PHP `int`s.** Every struct is `(new VkFoo(...))->pack()` /
  `packInto()`. Out-handles are 8-byte blocks read with `unpack('P')`;
  counts are `unpack('V')`. Compare `VkResult` by `->value`.
- **Portability by enumeration, not OS.** Enable
  `VK_KHR_portability_enumeration` + `ENUMERATE_PORTABILITY_BIT_KHR` and
  `VK_KHR_portability_subset` when the loader lists them. No
  `PHP_OS_FAMILY` in `src/`.
- **One frame in flight.** Staging growth and texture release `retire()`
  handles; `reap()` runs after the frame fence. `vkDeviceWaitIdle` only
  at swapchain recreate and `release()`.
- **Vulkan clip y is down.** The vertex shader flips `clip.y`. Surface's
  `Transform::orthographic` is y-up; viewport and scissor pass through in
  top-left pixel space.
- Enums are int- or string-backed with FULLY UPPERCASE cases. **No class
  constants anywhere.** Prefer `is_null($var)` over `$var === null`.

## Verification

Pure-logic code is covered by Pest with no extension present. Feature
tests need `ext-vulkan`; AttachTest also needs Darwin + `ext-metal`.

```bash
vendor/bin/pest
php -l src/VulkanEngine.php
composer validate
```

If PHP cannot see the extensions, export the Herd scan dir first:

```bash
export HERD_PHP_84_INI_SCAN_DIR=$(zsh -ic 'echo $HERD_PHP_84_INI_SCAN_DIR')
```
