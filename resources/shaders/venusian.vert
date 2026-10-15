#version 450

// A point in target pixels, origin top-left, to clip space. Vulkan's clip y
// runs down and its image row 0 is the top, so pixel y = 0 is clip −1 and the
// target reads back top-down with no flip. `at` is the point, interpolated.
layout(location = 0) in vec2 point;
layout(location = 0) out vec2 at;
layout(push_constant) uniform Push { vec4 size; } push;

void main()
{
    gl_Position = vec4(point.x / push.size.x * 2.0 - 1.0, point.y / push.size.y * 2.0 - 1.0, 0.0, 1.0);
    at = point;
}
