#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

layout(set = 0, binding = 0) uniform sampler2D src;
layout(push_constant) uniform Step { layout(offset = 16) float m[20]; } c;

// The 4 x 5 matrix row by row, on the straight colour; premultiplied again by the new alpha.
void main()
{
    uvec4 v = byte4(texelFetch(src, pixel(), 0));
    float r = float(straightOf(v.r, v.a)) / 255.0;
    float g = float(straightOf(v.g, v.a)) / 255.0;
    float b = float(straightOf(v.b, v.a)) / 255.0;
    float a = float(v.a) / 255.0;
    float nr = clamp(c.m[0] * r + c.m[1] * g + c.m[2] * b + c.m[3] * a + c.m[4], 0.0, 1.0);
    float ng = clamp(c.m[5] * r + c.m[6] * g + c.m[7] * b + c.m[8] * a + c.m[9], 0.0, 1.0);
    float nb = clamp(c.m[10] * r + c.m[11] * g + c.m[12] * b + c.m[13] * a + c.m[14], 0.0, 1.0);
    float na = clamp(c.m[15] * r + c.m[16] * g + c.m[17] * b + c.m[18] * a + c.m[19], 0.0, 1.0);
    color = unbyte(uvec4(floor(nr * na * 255.0 + 0.5), floor(ng * na * 255.0 + 0.5), floor(nb * na * 255.0 + 0.5), floor(na * 255.0 + 0.5)));
}
