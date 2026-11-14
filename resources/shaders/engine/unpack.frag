#version 450
#extension GL_GOOGLE_include_directive : require
#define DATA
#include "filter.glsl"

// An image the CPU gave as bytes (RGBA8, rows from the top, width x height): the data's words, a pixel a word, as
// they are. A displacement map from a framebuffer: straight colour, read as such.
layout(push_constant) uniform Size { layout(offset = 16) ivec2 size; } s;

void main()
{
    ivec2 p = pixel();
    color = unpackUnorm4x8(word(p.y * s.size.x + p.x));
}
