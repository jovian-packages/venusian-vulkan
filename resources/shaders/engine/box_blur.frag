#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

layout(set = 0, binding = 0) uniform sampler2D src;
layout(push_constant) uniform Step { layout(offset = 16) ivec2 size; uint horizontal; uint width; int left; uint edge; } b;

// One box blur along an axis: output i is the mean of i - left .. i - left + width - 1, rounded to nearest.
void main()
{
    ivec2 p = pixel();
    ivec2 step = b.horizontal != 0u ? ivec2(1, 0) : ivec2(0, 1);
    uvec4 sum = uvec4(0u);
    for (int j = 0; j < int(b.width); j++) {
        sum += fetchOf(src, p + step * (j - b.left), b.size, b.edge);
    }
    color = unbyte((sum + b.width / 2u) / b.width);
}
