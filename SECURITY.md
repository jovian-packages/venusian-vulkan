# Security Policy

## Supported versions

Venusian is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on this repository's
**Security** tab. If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, and steps to reproduce. Reports are read and
weighed for the release line in development; before 1.0 there is no response-time commitment.

## Security model

- The shaders are the package's own committed SPIR-V files, never user input.
- A `LentSurface` handle is an address trusted to be a `VkSurfaceKHR` made against this device's instance, or a `CAMetalLayer`, ext-vulkan's `fromPointer` rule. It comes only from a toolkit package's canvas.
- Mapped-memory reads and writes are sized by the region and the framebuffer: `VulkanFramebuffer` refuses a region outside the target and an upload whose bytes do not fill its region exactly, and each staging buffer is made for exactly those bytes.
- `vk_read_mapped` and `vk_write_mapped` run only on addresses this device mapped from its own staging memory, inside the mapping's size.
- Image sources are read through `toRgba8()` and uploaded at the source framebuffer's own size; a source or target past `maxImageDimension2D` is refused before Vulkan sees it.
- Once the canvas releases its surface the device destroys the swapchain and presents nothing more.
