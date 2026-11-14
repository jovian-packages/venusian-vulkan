#version 450

// One colour, given as its bytes, straight: written premultiplied for the pipeline to blend
// (source + destination x (1 - source alpha)). The stencil pass uses it with colour writes off.
layout(push_constant) uniform Paint { layout(offset = 16) uvec4 rgba; } paint;
layout(location = 0) out vec4 color;

void main()
{
    float a = float(paint.rgba.a) / 255.0;
    color = vec4(vec3(paint.rgba.rgb) / 255.0 * a, a);
}
