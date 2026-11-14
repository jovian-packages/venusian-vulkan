#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

layout(set = 0, binding = 0) uniform sampler2D a;
layout(set = 1, binding = 0) uniform sampler2D b;
layout(push_constant) uniform Step { layout(offset = 16) uint mode; } m;

// mode 0 normal, 1 multiply, 2 screen, 3 darken, 4 lighten; a over b
void main()
{
    vec4 x = vec4(byte4(texelFetch(a, pixel(), 0))) / 255.0;
    vec4 y = vec4(byte4(texelFetch(b, pixel(), 0))) / 255.0;
    float qa = x.a;
    float qb = y.a;
    vec3 ca = x.rgb;
    vec3 cb = y.rgb;
    vec3 v;
    switch (m.mode) {
        case 0u: v = (1.0 - qa) * cb + ca; break;
        case 1u: v = (1.0 - qa) * cb + (1.0 - qb) * ca + ca * cb; break;
        case 2u: v = cb + ca - ca * cb; break;
        case 3u: v = min((1.0 - qa) * cb + ca, (1.0 - qb) * ca + cb); break;
        default: v = max((1.0 - qa) * cb + ca, (1.0 - qb) * ca + cb); break;
    }
    color = stored(vec4(v, 1.0 - (1.0 - qa) * (1.0 - qb)));
}
