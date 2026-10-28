# Log

## 2026-10-28

* **Version**: 0.11.0 with the Venusian 0.11 ecosystem: `dev-main` → `0.11.x-dev`, its extensions, `venusian-surface/*`, `venusian-voyager/*` and the jovian siblings at `^0.11.0`, PHP `~8.4.27||^8.5.12`, homepage `0.11.x`. No API change. 104 passed, 8 skipped on Homebrew PHP 8.4 NTS and ZTS; 95 passed, 17 skipped on the Pi (8.4 ZTS).

## 2026-10-08

* Staged windows phase 6: show-pass presents, vsync, frames in flight, HDR, hidden windows, see [architecture](architecture/engine.md).

## 2026-10-06

* Three export images, held for the importer until `releaseDmabuf()`; dedicated allocation; a device let go hands its surface back itself; xcb and Xlib surface extensions on the instance; one surface per device. [engine](architecture/engine.md)
* A dmabuf export for GTK: `DMABUF` surfaces adopted, a linear exported image blitted per present, `VulkanFramebuffer implements DmabufExportable`; `surfaces()` lists `DMABUF` only with the export extensions. [engine](architecture/engine.md), [testing](runbooks/testing.md)
* venusian-vulkan 0.10.0: [engine](architecture/engine.md), [testing](runbooks/testing.md).
