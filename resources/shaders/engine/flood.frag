#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

layout(push_constant) uniform Step { layout(offset = 16) uvec4 rgba; } f;

void main()
{
    color = unbyte(f.rgba);
}
