#version 450
#extension GL_GOOGLE_include_directive : require
#define DATA
#include "filter.glsl"

layout(set = 0, binding = 0) uniform sampler2D src;
layout(push_constant) uniform Step { layout(offset = 16) ivec2 size; uint samples; uint edge; } s;

// Points on a golden-angle spiral (the data: dx, dy a point), each read bilinearly, averaged.
void main()
{
    ivec2 p = pixel();
    uvec4 sum = uvec4(0u);
    for (uint i = 0u; i < s.samples; i++) {
        sum += bilinear(src, s.size, float(p.x) + 0.5 + floatAt(int(2u * i)), float(p.y) + 0.5 + floatAt(int(2u * i + 1u)), s.edge);
    }
    color = unbyte((sum + s.samples / 2u) / s.samples);
}
