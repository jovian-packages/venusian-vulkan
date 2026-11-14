#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

layout(set = 0, binding = 0) uniform sampler2D a;
layout(set = 1, binding = 0) uniform sampler2D b;
layout(push_constant) uniform Step { layout(offset = 16) uvec4 op; vec4 k; } c;

// operator 0 over, 1 in, 2 out, 3 atop, 4 xor, 5 arithmetic (k1..k4)
void main()
{
    vec4 x = vec4(byte4(texelFetch(a, pixel(), 0))) / 255.0;
    vec4 y = vec4(byte4(texelFetch(b, pixel(), 0))) / 255.0;
    float aa = x.a;
    float ab = y.a;
    vec4 v;
    switch (c.op.x) {
        case 0u: v = x + y * (1.0 - aa); break;
        case 1u: v = x * ab; break;
        case 2u: v = x * (1.0 - ab); break;
        case 3u: v = x * ab + y * (1.0 - aa); break;
        case 4u: v = x * (1.0 - ab) + y * (1.0 - aa); break;
        default: v = c.k.x * x * y + c.k.y * x + c.k.z * y + c.k.w; break;
    }
    color = stored(v);
}
