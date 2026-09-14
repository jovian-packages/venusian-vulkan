#version 450

layout(location = 0) in vec3 aPos;
layout(location = 1) in vec4 aColor;
layout(location = 2) in vec2 aUV;

layout(push_constant) uniform Push {
    mat4 projection;
} push;

layout(location = 0) out vec4 v_color;
layout(location = 1) out vec2 v_uv;

void main() {
    vec4 clip = push.projection * vec4(aPos.xy, 0.0, 1.0);
    clip.y = -clip.y;
    gl_Position = clip;
    gl_PointSize = 1.0;
    v_color = aColor;
    v_uv = aUV;
}
