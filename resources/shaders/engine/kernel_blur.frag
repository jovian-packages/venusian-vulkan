#version 450
#extension GL_GOOGLE_include_directive : require
#define DATA
#include "filter.glsl"

layout(set = 0, binding = 0) uniform sampler2D src;
layout(push_constant) uniform Step { layout(offset = 16) ivec2 size; uint horizontal; uint radius; uint edge; } k;

// A small Gaussian along an axis: integer weights for -R..R (the data) that sum to 256, (sum + 128) >> 8.
void main()
{
    ivec2 p = pixel();
    ivec2 step = k.horizontal != 0u ? ivec2(1, 0) : ivec2(0, 1);
    int length = k.horizontal != 0u ? k.size.x : k.size.y;
    uvec4 sum = uvec4(0u);
    for (int i = 0; i <= int(2u * k.radius); i++) {
        ivec2 q = p + step * (i - int(k.radius));
        int at = k.horizontal != 0u ? q.x : q.y;
        if ((at < 0 || at >= length) && k.edge == 0u) {
            continue;
        }
        sum += word(i) * fetchOf(src, q, k.size, 1u);
    }
    color = unbyte((sum + 128u) >> 8);
}
