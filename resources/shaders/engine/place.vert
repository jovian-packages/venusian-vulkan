#version 450

// Every vertex is a point in surface pixels, origin top-left. An image that holds only part of the surface (a
// layer's) says where it starts: frame.origin. Vulkan's clip space has y down, as the surface does.
layout(location = 0) in vec2 point;
layout(push_constant) uniform Frame { vec2 size; vec2 origin; } frame;
layout(location = 0) out vec2 at;

void main()
{
    vec2 q = point - frame.origin;
    gl_Position = vec4(q / frame.size * 2.0 - 1.0, 0.0, 1.0);
    at = point;
}
