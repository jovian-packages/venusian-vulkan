# AGENTS.md

1. Bindings are ext-vulkan's, 1:1. This package holds the engine, never Vulkan calls ext-vulkan does not bind.
2. Vulkan draws its own way: hardware blending, premultiplied storage. Velvet's picture is a loose check (edges and one step of colour may differ), never a byte target. Shaders are GLSL in `resources/shaders/engine`: recompile with `glslangValidator -V` and commit the `.spv`.
3. The gate is `tests/smoke.php`, on the GPU with no window, and cost is part of it: time at the caller's real usage against Velvet and Metal.
4. No window or toolkit code here, and no Velvet extension (ext-fb, ext-rasterize, ext-paint, ext-raycast). A stage lends its platform handle through `nativeSurface()`; the engine builds its own surface on it. On macOS that surface is a swapchain over a `CAMetalLayer` on the lent `NSView` (`VulkanSurface`): the view is ext-appkit's and the layer ext-metal's, used only when both are loaded and never declared in `composer.json`, so the package installs on Linux unchanged. From ext-metal, `CAMetalLayer` alone, to make and scale the layer: MoltenVK sets its device and size. Without them a stage is shown on as pixels.
5. Tests run on the GPU, never skipped, except the presenting tests off macOS (Linux surfaces come from a toolkit).
6. Publish prep is part of done: README examples run, `.okf` validated.
