# Update Log

## 2026-09-14
* **Packaging**: `jovian/metal` moved from `require` to `suggest` (macOS
  LAYER path); path repository kept. Linux installs without `ext-metal`.
  `MetalLayerHost::mint()` / `::lent()` throw `noHostForPlatform` unless
  `ext-metal` is loaded and `jovian/metal` is installed. Test helper
  `metalExtensionLoaded()` checks both. Concept `seam.md`.

## 2026-09-14

* **Surface ownership**: `SurfaceHost::destroySurface()` is the only
  destroyer — `MetalLayerHost` calls `vkDestroySurfaceKHR`,
  `LentSurfaceHost` calls the lender's. Executor calls it in `release()`
  and when its constructor throws after `createSurface`.
* **Extension set**: `SurfaceHost::instanceExtensions()`. The context
  enables every extension a lender names and is cached by the order-free
  set (`VulkanContext::extensionSet`); `wsi` string gone. The mint set is
  unchanged. `VulkanContext::listedInstanceExtensions()` added.

## 2026-09-14

* **Lent attach**: `surfaceKind()` is `LAYER` on Darwin, `VULKAN_SURFACE`
  elsewhere. `attach()` routes `GPUHost->vk` → `LentSurfaceHost`,
  `GPUHost->layer > 0` → `MetalLayerHost::lent()`, else mint. Lent paths
  answer `layer_pointer` 0. Lent-layer retain balance measured (adopt +1,
  box +0, release −1). seam.md gains the platform table and lent hosts.

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
