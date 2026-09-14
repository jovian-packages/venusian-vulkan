# src/Shaders — the vendored SPIR-V for the painter

Two GLSL 450 sources and their compiled SPIR-V. The `.spv` files are
**committed**, so neither box needs a GLSL compiler to draw: Vulkan
consumes SPIR-V, and SPIR-V is portable across drivers and architectures.
Only this directory's `.spv` files are regenerated, and only by hand, on
the Mac.

## The sources

`painter.vert` takes the Surface vertex at three locations (`vec3` position,
`vec4` colour, `vec2` UV), multiplies by a `push_constant mat4 projection`,
negates `clip.y` (Vulkan NDC is y-down; Surface's orthographic is y-up),
and writes `gl_PointSize = 1.0`. `painter.frag` samples `u_texture` at
set 0 binding 0 and writes `v_color * texture(u_texture, v_uv)`.

There is no geometry shader, no triangle fan and no wide line anywhere here,
because MoltenVK implements the Vulkan portability subset and has none of
the three.

## The command, run once on the Mac

```bash
glslangValidator -V src/Shaders/painter.vert -o src/Shaders/painter.vert.spv
glslangValidator -V src/Shaders/painter.frag -o src/Shaders/painter.frag.spv
```

`glslangValidator` came from Homebrew `glslang` 16.5.0 at
`/opt/homebrew/bin/glslangValidator`. `glslangValidator --version` printed:

```
Glslang Version: 11:16.5.0
ESSL Version: OpenGL ES GLSL 3.20 glslang Khronos. 16.5.0
GLSL Version: 4.60 glslang Khronos. 16.5.0
SPIR-V Version 0x00010600, Revision 1
GLSL.std.450 Version 100, Revision 1
Khronos Tool ID 8
SPIR-V Generator Version 11
GL_KHR_vulkan_glsl version 100
ARB_GL_gl_spirv version 100
```

## What was produced

| file | bytes | SPIR-V version word | sha256 |
|---|---:|---|---|
| `painter.vert.spv` | 1668 | `1.0` | `23f36d076e3f9ba97a00b19d7e759574bb27273c29447985eb52bed753419ce3` |
| `painter.frag.spv` | 664 | `1.0` | `84b1f6401da61ad72bf523bf901a910daef360bfbdbc61936adf2b87a61f3388` |

`-V` alone targets Vulkan 1.0 semantics, so the module header says SPIR-V
**1.0** — the version every Vulkan implementation from 1.0 onward must accept.
That is deliberate: the Pi's V3D device reports Vulkan 1.3 and MoltenVK reports
1.4, and neither has to care. (`--target-env vulkan1.3` would emit SPIR-V 1.6
and would not load on a 1.0 or 1.1 device.)

Both files begin with the SPIR-V magic word `0x07230203` little-endian, which
is what `vkCreateShaderModule` looks for; `Spirv::load()` checks the magic and
that the length is a multiple of 4 before the bytes go through
`Bridge::alloc` + `Bridge::write` untouched.

## Regenerating

Only on the Mac, only with the two commands above, and only alongside an
update to the table here. `glslang` is **not** a build dependency of this
package and is not required on the Pi.
