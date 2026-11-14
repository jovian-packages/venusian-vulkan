#version 450
#extension GL_GOOGLE_include_directive : require
#define DATA
#include "filter.glsl"

layout(set = 0, binding = 0) uniform sampler2D src;
layout(set = 1, binding = 0) uniform sampler2D map;
layout(push_constant) uniform Step { layout(offset = 16) ivec2 size; ivec2 mapSize; float scale; float spread; uint xChannel; uint yChannel; uint bands; uint mapPremultiplied; } d;

// The map is straight (a framebuffer's) or premultiplied (a step's), read where the pixel is; past its edge, no
// shift. The data: a band's weights (r, g, b, 0) a band.
void main()
{
    ivec2 p = pixel();
    float dx = 0.0;
    float dy = 0.0;
    if (p.x < d.mapSize.x && p.y < d.mapSize.y) {
        uvec4 m = byte4(texelFetch(map, p, 0));
        if (d.mapPremultiplied != 0u) {
            m = uvec4(straightOf(m.r, m.a), straightOf(m.g, m.a), straightOf(m.b, m.a), m.a);
        }
        dx = d.scale * (float(m[d.xChannel]) / 255.0 - 0.5);
        dy = d.scale * (float(m[d.yChannel]) / 255.0 - 0.5);
    }
    float u = float(p.x) + 0.5;
    float v = float(p.y) + 0.5;
    uvec4 middle = bilinear(src, d.size, u + dx, v + dy, 0u);
    if (d.bands == 1u) {
        color = unbyte(middle);
        return;
    }
    vec3 rgb = vec3(0.0);
    for (uint i = 0u; i < d.bands; i++) {
        float k = float(i) / float(d.bands - 1u) * 2.0 - 1.0;
        float f = 1.0 + d.spread * k;
        uvec4 t = bilinear(src, d.size, u + dx * f, v + dy * f, 0u);
        int w = int(4u * i);
        rgb += vec3(t.rgb) * vec3(floatAt(w), floatAt(w + 1), floatAt(w + 2));
    }
    uvec3 c = min(uvec3(middle.a), uvec3(floor(rgb + 0.5)));
    color = unbyte(uvec4(c, middle.a));
}
