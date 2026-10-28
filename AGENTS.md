# AGENTS.md

1. Bindings are ext-vulkan's, 1:1. This package holds the engine, never Vulkan calls ext-vulkan does not bind.
2. The shader body is venusian-sdl3's maths: change both or neither. Only the declarations (set 0 sampler, push-constant blocks) and the vertex y mapping differ. Recompile with `glslangValidator -V -g` and commit the `.spv`. See [.okf/architecture/engine.md](.okf/architecture/engine.md).
3. The parity suite is the gate, on the Mac (MoltenVK) and the Pi (V3DV): `tests/ParityTest.php` through Surface's `GpuParity`.
4. No window code here. Surfaces come from the toolkit packages' canvases as `LentSurface`s.
5. Tests run on the GPU, never skipped, except the presenting tests off macOS (Linux surfaces come from a toolkit).
6. Publish prep is part of done: README examples run, `.okf` validated.
