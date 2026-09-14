# Update Log

## 2026-09-14

* **Retina extent**: MoltenVK `currentExtent` is `bounds × contentsScale`
  (`naturalDrawableSizeMVK`), not `drawableSize`. Engine Trio showed the
  Vulkan column at 2× because a mint layer stays at scale 1.0.
  `MetalLayerHost` writes `contentsScale` through libobjc on every
  drawable resize; `SwapchainChoice` prefers host pixels when the
  reported extent is a 2×/3× point-size of the host; `SUBOPTIMAL` no
  longer forces a swapchain recreate.

## 2026-09-14

Built as slice 3 of the Surface GPU drawing program. `jovian/vulkan` is the
projection; this package is the composition.

* **Scaffold**: `jovian/venusian-vulkan`, namespace `Jovian\Venusian\Vulkan\`,
  PHP `^8.4|^8.5|^8.6`. Path repos for `jovian/vulkan`, `jovian/metal`, and
  `venusian/surface`. `VenusianVulkanServiceProvider` binds `VulkanEngine`
  as `gpu.vulkan`.
* **Context**: one instance / device / queue / SPIR-V painter modules /
  descriptor layout / pipeline layout / LINEAR CLAMP_TO_EDGE sampler /
  256-set pool / 1×1 white placeholder, cached on `VulkanEngine`.
  Portability extensions enabled when the loader lists them.
* **Executor**: one frame in flight, staging cursor with growth through
  `Retirement`, indexed uint16, RGBA8 textures, mid-frame `readPixels`
  with BGRA→RGBA swizzle and load-pass reopen. Capabilities: blending
  true, depth false, instancing true, readback true.
* **Seam**: `attach()` answers `Bridge::pointerOf($layer->handle)` and
  `'CAMetalLayer'`. No AppKit imports. `surfaceKind() === LAYER`.
  `SwapchainChoice` treats MoltenVK's 0×0 `currentExtent` as undefined.
* **Tests**: Pest suite, 59 passed / 1 skipped (mint-without-metal) on a
  Mac with `ext-vulkan` + `ext-metal`. Pure suite (enums, shaders,
  capabilities, values, engine name) runs without the extension.
