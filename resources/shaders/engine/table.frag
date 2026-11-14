#version 450
#extension GL_GOOGLE_include_directive : require
#define DATA
#include "filter.glsl"

layout(set = 0, binding = 0) uniform sampler2D src;

// Four lookup tables of 256 bytes (the data), red, green, blue, alpha, on the straight colour; the alpha's first,
// the colours premultiplied by the new alpha.
uint entry(uint i)
{
    return (word(int(i >> 2)) >> ((i & 3u) * 8u)) & 0xFFu;
}

void main()
{
    uvec4 c = byte4(texelFetch(src, pixel(), 0));
    uint na = entry(768u + c.a);
    color = unbyte(uvec4(premultiplyOf(entry(straightOf(c.r, c.a)), na), premultiplyOf(entry(256u + straightOf(c.g, c.a)), na), premultiplyOf(entry(512u + straightOf(c.b, c.a)), na), na));
}
