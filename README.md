# jovian/venusian-vulkan

The `vulkan` rendering engine for Venusian Surface. Draws Surface's draw lists on any Vulkan 1.1+ GPU: V3DV on the Raspberry Pi 5, MoltenVK on the Mac. Presents into a `VkSurfaceKHR` a canvas lends.

## Where it sits

```
DrawingManager → GpuRenderingEngine (Surface) → VulkanDevice (this package) → ext-vulkan → Vulkan loader → V3DV | MoltenVK
```

The engine's framebuffer is a `VulkanFramebuffer`, a Surface `GLFramebuffer`.

## Requirements

- A Vulkan 1.1+ GPU (the CPU device is skipped) with a graphics queue and `VK_KHR_swapchain`. Anti-aliased edges need 4 samples for colour and stencil; a device with fewer refuses them by name.
- macOS: Homebrew `vulkan-loader`, `vulkan-headers`, `molten-vk`. Presenting into a canvas's `CAMetalLayer` also needs ext-metal.
- Raspberry Pi: Mesa V3DV. Run inside the user's session (`WAYLAND_DISPLAY`, `XDG_RUNTIME_DIR`) so the loader finds the display.
- PHP 8.4, ext-vulkan ^0.10 (with `VK_API_VERSION_1_1`, the properties2 / Wayland / xcb / Xlib surface names, `VkMemoryBarrier`, `VkMemoryDedicatedAllocateInfo` and `vk_close_fd`), `venusian-surface/drawing` ^0.10.
- No window code: the engine opens no window. A window's surface arrives from a toolkit canvas as a `LentSurface`.

## Install

```
composer require jovian/venusian-vulkan
```

Provider discovered through `extra.venusian.providers`. It registers `vulkan` on `app('drawing')`.

## Usage

Offscreen. Run as a script; printed values are its output (Apple M1 Pro; the Pi prints `V3D 7.1.7.0` and the same pixels).

```php
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\NutsAndBolts\Color;

$engine = app('drawing')->renderer('vulkan', ['width' => 320, 'height' => 240]);
$engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(16, 24, 32))->fillEllipse(160, 120, 60, 40, Color::rgb(255, 128, 0)));
$rgba = $engine->framebuffer()->flush(FormatSpec::rgba8());

vkGetPhysicalDeviceProperties($engine->device()->physical(), $properties);
echo $properties->deviceName, "\n";                            // Apple M1 Pro
echo bin2hex(substr($rgba, (120 * 320 + 160) * 4, 4)), "\n";   // ff8000ff  ellipse centre
echo bin2hex(substr($rgba, 0, 4)), "\n";                       // 101820ff  corner
```

On a display. The display reads back what changed and sends it in its own format. ST7796 480 × 320 on the Pi 5 at 10 MHz: 57.4 fps of partial frames (see Measured).

```php
$display = app('displays')->panel('st7796', direct: true);
$engine = app('drawing')->renderer('vulkan', ['output' => $display]);
```

On a canvas. AppKit lends its `CAMetalLayer` and Qt a `VkSurfaceKHR` made against the engine's instance: the device adopts it and `present()` copies each frame into the next swapchain image. GTK on Linux takes the frame the other way: the canvas lends a `dmabuf` surface, the device blits each frame into a linear image it exports as a dmabuf and waits, and the canvas imports it as a texture (`DmabufExportable::dmabuf()` on the engine's framebuffer).

```php
$engine = app('drawing')->renderer('vulkan', ['output' => $canvas]);
```

Before a canvas lends one of `vulkan-surface`, `metal-layer` or `dmabuf`, the refusal reads `This window cannot host 'vulkan': it lends … It can host: …`.

## Presenting and HDR

Present = show pass (no blit): target → `presentRect()` at swapchain size, window's filter, black bars, encoded for swapchain. Damage → `VkPresentRegionsKHR`. Vsync = surface present modes (FIFO on, FIFO_RELAXED adaptive, MAILBOX, IMMEDIATE off); FIFO paced by present wait: `present()` answers false while `frames_in_flight` presents unshown, never blocks. `frames_in_flight` 1-3: frame + present rings. `presents()` = frame numbers via `VK_KHR_present_wait` (no times).

HDR (needs `VK_EXT_swapchain_colorspace` + a surface offering the pair): rgba16float extended-linear sRGB / P3 (EXTENDED_SRGB_LINEAR, × window SDR white), rgb10a2 HDR10 (HDR10_ST2084 + `vkSetHdrMetadataEXT`), rgba8 Display P3. Target = linear half floats, SDR white 1.0.

    $engine = $drawing->engine('vulkan', ['output' => $window, 'format' => TargetFormat::Rgb10A2, 'colorspace' => ColorSpace::Hdr10Pq]);

Measured 2026-10-08: MoltenVK (Mac) and V3DV (Pi 5) render all four pairs and take HDR metadata; MoltenVK's CAMetalLayer offers extended-linear and HDR10 swapchains.

## What it draws

| Operation | Draws |
|---|---|
| CLEAR | the colour over the whole target, no blend |
| SCISSOR | clip rectangle for what follows (`vkCmdSetScissor`, top-left origin) |
| SOLID | flat-colour triangles, no blend |
| STENCIL_FILL + COVER | a path: triangle lists into the stencil (winding: front faces increment-wrap, back faces decrement-wrap; even-odd: invert), then a cover quad blended where the stencil is non-zero, zeroing it |
| ELLIPSE, RING | coverage of the outer (and inner) ellipse in the fragment shader, folded into alpha as Velvet folds it |
| UPLOAD + IMAGE | a framebuffer as an image, fetched texel by texel at the inverse placement of the pixel centre, nearest or Velvet's 256ths bilinear |
| RECTS | rectangles blended at the colour's alpha |

Edges: four samples resolved inside the render pass (anti-aliased) or one (hard).

## Blending

Fixed-function source-over: colour `SRC_ALPHA, ONE_MINUS_SRC_ALPHA`, alpha `ONE, ONE_MINUS_SRC_ALPHA`. The shader hands the blender Velvet's alpha: `(α·coverage + 127) / 255` for shapes, `(sₐ·opacity + 127) / 255` for images. `GpuParity` 21/21 on Apple M1 Pro (MoltenVK) and on the Pi 5 (V3DV), no tolerance; translucent fills at α 1, 127, 128, 254, 255 match byte for byte with one sample and four.

## Shaders

`resources/shaders/venusian.vert` and `venusian.frag`, GLSL 450, compiled with `glslangValidator -V -g` and committed as `.spv`. The fragment body is venusian-sdl3's, unchanged; only the declarations differ: the sampler is set 0, binding 0, and the paint block is a push-constant block. Push constants: `vec4 size` for the vertex stage at offset 0, `Paint` (96 bytes) for the fragment stage at offset 16. The vertex shader maps pixel y = 0 to clip −1: Vulkan's clip y runs down and image row 0 is the top, so nothing is flipped anywhere.

## Reference

### `VulkanDevice`

| Member | Does |
|---|---|
| `new VulkanDevice` | instance (surface, Metal on macOS or Wayland, xcb and Xlib surfaces elsewhere, properties2, portability enumeration: each where listed), first non-CPU GPU, device with one graphics queue and `VK_KHR_swapchain` (portability subset, external memory fd, dmabuf, DRM format modifier: each where listed), command pool, shaders, layouts, sampler. Throws `DrawingException` when no GPU or no swapchain |
| `name()` | `'vulkan'` |
| `surfaces()` | `[VULKAN_SURFACE, METAL_LAYER, DMABUF]`; `DMABUF` only with `VK_KHR_external_memory_fd` and `VK_EXT_external_memory_dma_buf` enabled (Linux) |
| `handles()` | `['instance' => <VkInstance address>]`: a canvas makes its `VkSurfaceKHR` against it |
| `instance()`, `physical()`, `device()`, `queue()`, `family()` | the Vulkan objects and the graphics queue family |
| `stencilFormat()` | `D24_UNORM_S8_UINT` or `D32_SFLOAT_S8_UINT`, the first the device renders |
| `coherent()` | whether the last staging buffer's memory type is `HOST_COHERENT` |
| `extensions()` | the device extensions enabled |
| `maxSamples()` | 4 when colour and stencil both take 4 samples, else 1 |
| `maxImageDimension2D()` | the largest image side |
| `target($w, $h, $samples)` | the persistent RGBA8 target, transparent black; 1 or 4 samples |
| `draw($list)` | records one frame into one command buffer and submits it; does not wait |
| `adopt($surface)` | `VULKAN_SURFACE` (`handle('surface')`) or `METAL_LAYER` (`handle('layer')`, made into a surface with `vkCreateMetalSurfaceEXT`); builds the swapchain. `DMABUF`: every target from then on gets a linear export image; throws naming the export extensions the device lacks |
| `present($surface)` | blits the target into the next swapchain image and presents; false when nothing was copied. On a `DMABUF` surface: blits into an export the importer does not hold and waits for it, then holds it for the importer; false while it holds all three |
| `dmabufOf($framebuffer)`, `releaseDmabuf($fd)` | what `VulkanFramebuffer::dmabuf()` and `releaseDmabuf()` call |
| `exportBytes()` | for tests: the export image's pixels through a mapping of its memory |
| `swapchainExtent()` | `[width, height]` of the swapchain |
| `release()` | waits the device idle and destroys everything |
| `read($image, $region)`, `write($image, $rgba8, $region)` | readback and upload through staging buffers (`VulkanFramebuffer` calls them) |
| `forget($framebuffer)` | destroys a framebuffer's image when the PHP object goes |
| `finish()` | waits the frame in flight and frees what it used |

### `VulkanFramebuffer`

`image()`, `view()`, `memory()`, `device()`, plus `GLFramebuffer`'s pixel calls. Implements `DmabufExportable`: `dmabuf()` is `['fd', 'width', 'height', 'stride', 'offset', 'fourcc' => DRM_FORMAT_ABGR8888 (0x34324241), 'modifier' => DRM_FORMAT_MOD_LINEAR (0)]`, the export the last present wrote, while it is the target of a device presenting into a `DMABUF` surface, else null. `releaseDmabuf($fd)`: the importer is done with that export; until then the device neither writes it again nor closes it, even past a new target size or its own release.

### `VenusianVulkanServiceProvider`

`extend(DrawingManager $drawing)` registers `vulkan`: each `renderer()` call builds `GpuRenderingEngine::from(new VulkanDevice, $args, $drawing)`.

## Behaviour

- The target image lives in `VK_IMAGE_LAYOUT_GENERAL` its whole life: drawn or resolved into, copied out of, uploaded into, sampled, blitted from, with no layout change after the first.
- A frame is submitted with a fence and not waited. The next frame, readback, upload or release waits it, then frees that frame's vertex buffer, source images and descriptor pool.
- A readback is a copy into a host-visible buffer, invalidated, read. An upload is a flushed staging buffer copied in. Flush and invalidate always run, at `nonCoherentAtomSize`, coherent memory or not.
- With anti-aliased edges, an upload leaves the 4× samples behind; the next frame first draws the target back into them (one restore pass, which does not resolve).
- The 4× colour image keeps its contents between frames: a partial frame draws over the last one.
- The engine's own framebuffer used as an image source shows the frame before the current one: it is copied before the frame starts, and the copy is sampled.
- Image sources of another device, or CPU framebuffers, are uploaded every frame they are drawn.
- `present()` never blocks on the display: an acquire with timeout 0, false when the last present's copy has not finished, when no image is free, or while the surface has no area (a minimised window). A rebuild waits the device idle. An out-of-date swapchain is rebuilt at the surface's size and that call returns false; a suboptimal one presents and is rebuilt before the next. `MAILBOX` where offered, else `FIFO`.
- A framebuffer let go right after a present waits for that present's copy before its image goes.
- A dmabuf export is a pool of three linear images per target size, each memory allocated dedicated to its image. A present writes one the importer does not hold and holds it for the importer; the importer hands it back with `releaseDmabuf()` (the GTK canvas does when its texture is finalized), so GTK never samples an image being written. A new size or the surface's release destroys the images; a descriptor the importer still holds stays open until it is handed back. V3DV gives stride = width × 4, offset 0.
- A device let go while it still holds a surface (released, or freed before its engine) drops its swapchain and exports, then releases the surface itself, so the canvas takes it back while the device's instance still exists.
- When the canvas releases its surface the device destroys the swapchain (and the surface, when it made it from a layer) before the canvas takes the native surface away.

## Measured

| | Mac (Apple M1 Pro, MoltenVK) php84 | Mac zhp | Pi 5 (V3D 7.1.7.0) |
|---|---|---|---|
| 320 × 240, 100 ellipses + text, whole | 1.44 ms | 0.68 ms | 1.22 ms |
| same, partial | 4.79 ms | 1.51 ms | 2.31 ms |
| peak memory | 4.0 MB | 4.0 MB | 4.0 MB |

ST7796 480 × 320 at 10 MHz through `DirectEDisplay` on the Pi 5: whole frame draw 1.7 ms + present 249.2 ms; partial frames draw 0.7 ms + present 16.8 ms, 57.4 fps (Velvet: 0.7 + 15.9 ms, 60.2 fps); peak 16.0 MB.

Devices: MoltenVK `D32_SFLOAT_S8_UINT`, coherent host memory, `nonCoherentAtomSize` 16, present modes FIFO and IMMEDIATE. V3DV `D24_UNORM_S8_UINT`, coherent host memory, `nonCoherentAtomSize` 256, largest image 7680.

## Testing

See [.okf/runbooks/testing.md](.okf/runbooks/testing.md). The suite runs on the GPU; only the presenting tests skip off macOS.

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.
