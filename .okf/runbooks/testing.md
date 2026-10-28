---
type: Runbook
title: Testing
description: Real-GPU Pest suite on the Mac (MoltenVK) and the Pi (V3DV) with Surface's GpuParity; temporary path repository; php84, zhp, Pi php in the Wayland session.
resource: tests/
tags: [vulkan, moltenvk, v3dv, pest]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-06T12:00:00Z }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
---

# Steps

* The suite runs the real GPU. `tests/Pest.php` fails with a message without ext-vulkan.
* `tests/Pest.php` requires Surface's `GpuParity` from the sibling checkout (`dev/venusian/surface/tests`); `SURFACE_TESTS` overrides the directory.
* Surface's 0.11 splits are not on Packagist: add a path repository to `<surface>/src/Surface/*` for the run, `composer update`. Keep the committed `composer.json` aside and put it back before every commit.
* Mac: `php84 -d memory_limit=128M vendor/bin/pest`, then `zhp` (ZTS). MoltenVK through Homebrew's loader; portability enumeration and the portability subset are enabled where listed.
* `tests/PresentTest.php` presents into a `CAMetalLayer` with no view (ext-metal): Mac only. Off macOS those tests skip.
* `tests/DmabufTest.php`: the export on Linux with the two extensions (V3DV); its first test runs everywhere (no export before adopting; without the extensions, the refusal naming them).
* Pi: copy the package and Surface (`src`, `tests/Support`) without `vendor`, path repository `../surface/src/Surface/*`, then `WAYLAND_DISPLAY=wayland-0 XDG_RUNTIME_DIR=/run/user/1000 SURFACE_TESTS=<copy>/tests php -d memory_limit=128M vendor/bin/pest`. V3DV's loader finds the display through the session.
* Cleanup: `rm -rf vendor composer.lock composer.json.committed .phpunit.cache .phpunit.result.cache`; remove the Pi copy.

# Expected

* Mac php84 and zhp: every test passes. Pi: every test passes but the Mac-only presenting tests. `GpuParity` 21/21 on both.
