# Log

## 2026-10-08

* Staged windows phase 6: show-pass presents, vsync, frames in flight, HDR, hidden windows, see [architecture](architecture/engine.md).

## 2026-10-06

* The final review of 11b: three export images, held for the importer until `releaseDmabuf()`; dedicated allocation; a device let go hands its surface back itself; xcb and Xlib surface extensions on the instance; one surface per device. [engine](architecture/engine.md)
* A dmabuf export for GTK: `DMABUF` surfaces adopted, a linear exported image blitted per present, `VulkanFramebuffer implements DmabufExportable`; `surfaces()` lists `DMABUF` only with the export extensions. [engine](architecture/engine.md), [testing](runbooks/testing.md)
* venusian-vulkan 0.10.0: [engine](architecture/engine.md), [testing](runbooks/testing.md).
