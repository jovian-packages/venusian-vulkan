#version 450

// The image as it is: every sample of the four-sample twin from the single-sample image; a frame into a swapchain image.
layout(set = 0, binding = 0) uniform sampler2D frame;
layout(location = 0) out vec4 color;

void main()
{
    color = texelFetch(frame, ivec2(gl_FragCoord.xy), 0);
}
