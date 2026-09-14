---
okf_version: "0.2"
---

# jovian/venusian-vulkan — knowledge bundle

Vulkan composition for Venusian Surface GPU drawing. `jovian/vulkan` projects
`ext-vulkan` one call at a time; this package owns the frame, the swapchain,
and the MoltenVK layer seam. Surface talks to it only through `gpu.vulkan`.

Read this index first, then open only the concepts the task needs. Every
concept here is `status: draft` until a human verifies it.

# Concepts

* [executor.md](/executor.md) - frame lifecycle, staging cursor, deferred
  destruction, mid-frame readback reopen, and present
* [seam.md](/seam.md) - LAYER on Darwin, VULKAN_SURFACE elsewhere; who
  mints or lends the `CAMetalLayer` / `VkSurfaceKHR`, who releases which retain

# Related bundles

* [jovian/vulkan](../../vulkan/.okf/index.md) - the typed projection this
  package composes
* [jovian/metal](../../metal/.okf/index.md) - `pointerOf` and `CAMetalLayer`
  used by the one-file host
* [venusian/surface](../../../venusian/surface/.okf/index.md) - the
  engine-free contracts and Painter this package implements
