#version 450
#extension GL_GOOGLE_include_directive : require
#define DATA
#include "filter.glsl"

layout(push_constant) uniform Step { layout(offset = 16) ivec2 origin; int seed; uint type; vec2 frequency; uint octaves; uint stitched; ivec4 stitch; } n;

// type 0 turbulence, 1 fractal, 2 hash. stitch: width, height, wrapX, wrapY of the first octave.
// The data is the seed's lattice: 514 selector entries (16 bits, two a word), then 4 x 514 gradients (x, y), a row
// of 514 a channel, from word 257.
uint hashOf(int x, int y, int seed)
{
    uint h = uint(x) * 374761393u + uint(y) * 668265263u + uint(seed) * 2246822519u;
    h = (h ^ (h >> 13)) * 1274126177u;
    return h ^ (h >> 16);
}

int selector(int i)
{
    return int((word(i >> 1) >> ((i & 1) * 16)) & 0xFFFFu);
}

vec2 gradient(int b, uint channel)
{
    int at = 257 + 2 * (int(channel) * 514 + b);
    return vec2(floatAt(at), floatAt(at + 1));
}

float noise2(uint channel, float x, float y, bool stitched, ivec4 stitch)
{
    float t = x + 4096.0;
    int bx0 = int(t) & 0xFF;
    int bx1 = (bx0 + 1) & 0xFF;
    float rx0 = t - float(int(t));
    float rx1 = rx0 - 1.0;
    t = y + 4096.0;
    int by0 = int(t) & 0xFF;
    int by1 = (by0 + 1) & 0xFF;
    float ry0 = t - float(int(t));
    float ry1 = ry0 - 1.0;
    if (stitched) {
        if (bx0 >= stitch.z) { bx0 -= stitch.x; }
        if (bx1 >= stitch.z) { bx1 -= stitch.x; }
        if (by0 >= stitch.w) { by0 -= stitch.y; }
        if (by1 >= stitch.w) { by1 -= stitch.y; }
    }
    bx0 &= 0xFF;
    bx1 &= 0xFF;
    by0 &= 0xFF;
    by1 &= 0xFF;
    int i = selector(bx0);
    int j = selector(bx1);
    int b00 = selector(i + by0);
    int b10 = selector(j + by0);
    int b01 = selector(i + by1);
    int b11 = selector(j + by1);
    float sx = rx0 * rx0 * (3.0 - 2.0 * rx0);
    float sy = ry0 * ry0 * (3.0 - 2.0 * ry0);
    vec2 g00 = gradient(b00, channel);
    vec2 g10 = gradient(b10, channel);
    vec2 g01 = gradient(b01, channel);
    vec2 g11 = gradient(b11, channel);
    float u = rx0 * g00.x + ry0 * g00.y;
    float v = rx1 * g10.x + ry0 * g10.y;
    float a = u + sx * (v - u);
    u = rx0 * g01.x + ry1 * g01.y;
    v = rx1 * g11.x + ry1 * g11.y;
    float b = u + sx * (v - u);
    return a + sy * (b - a);
}

void main()
{
    ivec2 p = pixel() + n.origin;
    if (n.type == 2u) {
        uint h = hashOf(p.x, p.y, n.seed) & 0xFFu;
        color = unbyte(uvec4(h, h, h, 255u));
        return;
    }
    uvec4 s;
    for (uint channel = 0u; channel < 4u; channel++) {
        float sum = 0.0;
        float vx = float(p.x) * n.frequency.x;
        float vy = float(p.y) * n.frequency.y;
        float ratio = 1.0;
        ivec4 stitch = n.stitch;
        for (uint octave = 0u; octave < n.octaves; octave++) {
            float v = noise2(channel, vx, vy, n.stitched != 0u, stitch);
            sum += (n.type == 1u ? v : abs(v)) / ratio;
            vx *= 2.0;
            vy *= 2.0;
            ratio *= 2.0;
            stitch = ivec4(stitch.x * 2, stitch.y * 2, 2 * stitch.z - 4096, 2 * stitch.w - 4096);
        }
        float value = n.type == 1u ? (sum * 255.0 + 255.0) / 2.0 : sum * 255.0;
        s[channel] = uint(clamp(floor(value + 0.5), 0.0, 255.0));
    }
    color = unbyte(uvec4(premultiplyOf(s.r, s.a), premultiplyOf(s.g, s.a), premultiplyOf(s.b, s.a), s.a));
}
