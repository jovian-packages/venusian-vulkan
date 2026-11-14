#version 450
#extension GL_GOOGLE_include_directive : require
#include "filter.glsl"

layout(push_constant) uniform Step { layout(offset = 16) ivec2 origin; uint kind; uint pad; vec4 box; vec4 radii; vec2 window; } f;

// kind 0 rounded box (distance, normal), 1 inside, 2 ramp
void main()
{
    vec2 p = vec2(pixel() + f.origin) + 0.5;
    float span = f.window.y - f.window.x;
    if (f.kind == 2u) {
        uint v = uint(floor(clamp((p.y - f.window.x) / span, 0.0, 1.0) * 255.0 + 0.5));
        color = unbyte(uvec4(v, v, v, 255u));
        return;
    }
    float hx = (f.box.z - f.box.x) / 2.0;
    float hy = (f.box.w - f.box.y) / 2.0;
    vec2 q0 = p - vec2((f.box.x + f.box.z) / 2.0, (f.box.y + f.box.w) / 2.0);
    float radius = q0.x < 0.0 ? (q0.y < 0.0 ? f.radii.x : f.radii.w) : (q0.y < 0.0 ? f.radii.y : f.radii.z);
    radius = min(radius, min(hx, hy));
    float sideX = q0.x < 0.0 ? -1.0 : 1.0;
    float sideY = q0.y < 0.0 ? -1.0 : 1.0;
    float qx = abs(q0.x) - hx + radius;
    float qy = abs(q0.y) - hy + radius;
    float corner = length(vec2(max(qx, 0.0), max(qy, 0.0)));
    float nx;
    float ny;
    if (qx > 0.0 && qy > 0.0) {
        nx = sideX * (qx / max(corner, 1e-4));
        ny = sideY * (qy / max(corner, 1e-4));
    } else if (qx > qy) {
        nx = sideX;
        ny = 0.0;
    } else {
        nx = 0.0;
        ny = sideY;
    }
    float d = min(max(qx, qy), 0.0) + corner - radius;
    if (f.kind == 1u) {
        uint v = d < 0.0 ? 255u : 0u;
        color = unbyte(uvec4(v, v, v, 255u));
        return;
    }
    color = unbyte(uvec4(uint(floor(clamp((d - f.window.x) / span, 0.0, 1.0) * 255.0 + 0.5)), uint(floor(clamp(0.5 + 0.5 * nx, 0.0, 1.0) * 255.0 + 0.5)), uint(floor(clamp(0.5 + 0.5 * ny, 0.0, 1.0) * 255.0 + 0.5)), 255u));
}
