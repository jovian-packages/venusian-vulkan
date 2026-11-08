# Log

## 2026-11-08

* Host data moves through `vkCmdUpdateBuffer` (the frame's vertices, in chunks of 65,536 bytes into a pooled device-local buffer) and the Vulkan 1.4 host image copies (`vkCopyMemoryToImage` for sources, the atlas, filter data and the blank image; `vkCopyImageToMemory` for readbacks), with `vkTransitionImageLayout` for the first layout. The instance asks for 1.4; the device needs `hostImageCopy` and is refused by name without it. `hostBuffer()`, `stage()` and the host buffer pool are gone; the package needs only ext-vulkan and ext-posi. Parity 63/63 and the ring test pass on MoltenVK with a scratch build of ext-vulkan; lists and data tabs at scale 2 cost 21 and 39 ms CPU a frame, as before. Not run on Linux or the Pi.

## 2026-11-08

* `posix_close` (ext-posi) closes dmabuf descriptors; `ext-posi` is required. Pooled device-local vertex buffers and pooled source images are reused across frames.

## 2026-11-08

* G3 reuse (Surface drawing-path spec): the frame's vertex and staging buffers come from a pool of buffers that stay mapped, and image sources from a pool of images, each given back when the frame's fence has signalled, instead of a vkCreateBuffer, vkAllocateMemory and map for every buffer of every frame. Parity: all 63 Surface scenes pass (MoltenVK on the Mac); `VulkanDeviceTest` draws six frames through the pools with a growth between. Data and Lists tabs at scale 2: 44 and 20 ms CPU a frame before and after. Not run on Linux.

## 2026-11-07

* Glass (Surface drawing-path spec, G4): `VulkanDevice` is `DrawsGlass`. `GLASS_BACKDROP` ends the pass and runs the glass passes (`glass.frag`: capture, down, up; integer texel reads, ext-fb's levels byte for byte) into pooled level images (each with its view and a framebuffer of the filter's render pass), through a descriptor pool of the pyramid's own; the pyramid is kept across frames while Lowering says nothing under it changed, and a level nobody keeps goes back to the pool (freed only once no frame in flight reads it). `GLASS` is mode 9 of `venusian.frag` (Velvet's per-pixel steps at the pixel centre, edge by the ROUND distance ramp); the pipeline layout has a second set, which holds the pane's sharp capture (the blank image for every other draw). Against Velvet+ext on a glass scene (MoltenVK): max channel difference 11, 0.15 % of channels over 2; the kept pyramid draws the same. Glass tab, CPU time per frame: 5.4 ms at scale 1, 6.8 ms at scale 2.
* Filter passes FIELD (an image from the pixel position: a rounded box's windowed distance and direction, its inside mask, a ramp) and TABLE (a 256-entry lookup per straight channel), and a displacement whose map is an earlier pass's result (`params[5]`). Parity: the new scenes `layer fields`, `layer tables` and `layer displacement by a made map` pass on this engine (`venusian/surface/scripts/gpu-parity.php`).

* Layers: the TARGET, BACKDROP, FILTER and LAYER_SOURCE operations. A layer target is an image like the frame's own (colour at the frame's samples, a stencil, a resolved RGBA8 image in the general layout), made on first use and kept until the target changes; a layer's drawing is a pass of its own on its framebuffer, cleared on entry, and an enclosing target's pass is begun again with its colour loaded. BACKDROP and FILTER write a target's resolved image with a blit, after the pass that drew it has ended and resolved. A filter chain is Surface's `FilterPlan`: one pass of `resources/shaders/filter.frag` per plan pass (compiled with `glslangValidator -V -g`, like the others), over the region, into an image of its own, through a set of four samplers (the pass's inputs, its numbers as an RGBA8 image holding float32 bytes, a displacement map) and 64 bytes of push constants. All 57 parity tests pass on Homebrew PHP 8.4 (Mac, MoltenVK): the layer, filter, backdrop, noise and displacement scenes are byte for byte Velvet's; the rounded-layer scene differs only at the rounded edge. Layers on an HDR target throw.

## 2026-10-28

* **Version**: 0.11.0 with the Venusian 0.11 ecosystem: `dev-main` → `0.11.x-dev`, its extensions, `venusian-surface/*`, `venusian-voyager/*` and the jovian siblings at `^0.11.0`, PHP `~8.4.27||^8.5.12`, homepage `0.11.x`. No API change. 104 passed, 8 skipped on Homebrew PHP 8.4 NTS and ZTS; 95 passed, 17 skipped on the Pi (8.4 ZTS).

## 2026-10-08

* Staged windows phase 6: show-pass presents, vsync, frames in flight, HDR, hidden windows, see [architecture](architecture/engine.md).

## 2026-10-06

* Three export images, held for the importer until `releaseDmabuf()`; dedicated allocation; a device let go hands its surface back itself; xcb and Xlib surface extensions on the instance; one surface per device. [engine](architecture/engine.md)
* A dmabuf export for GTK: `DMABUF` surfaces adopted, a linear exported image blitted per present, `VulkanFramebuffer implements DmabufExportable`; `surfaces()` lists `DMABUF` only with the export extensions. [engine](architecture/engine.md), [testing](runbooks/testing.md)
* venusian-vulkan 0.10.0: [engine](architecture/engine.md), [testing](runbooks/testing.md).
