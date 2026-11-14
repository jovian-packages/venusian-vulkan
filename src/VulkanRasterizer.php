<?php

namespace Jovian\Engines\Vulkan;

use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GERasterizer;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;
use Surface\Rendering\Rasterization\Stroker;

/**
 * The vulkan engine's rasterizer: a path is filled on the GPU in two steps. Its contours are
 * drawn as triangle fans that only mark the stencil (counting up and down for non-zero, flipping
 * for even-odd), then one rectangle over the path's box is coloured where the stencil is marked
 * and blended over what is there. Overlaps and holes come out of the stencil, so the path needs
 * no tessellation.
 *
 * Antialiased edges come from four samples a pixel; hard edges from the pixel's centre.
 */
class VulkanRasterizer extends GERasterizer
{
    public function __construct(
        protected readonly VKLogicalDevice $device,
        protected readonly Edges $edges,
    ) {
        parent::__construct($device);
    }

    public function edges(): Edges
    {
        return $this->edges;
    }

    protected function fillPath(GEFramebuffer $onto, Path $path, Color $color, FillRule $rule, Region $clip): void
    {
        $this->paint($onto, array_column($path->flatten(), 'points'), $color, $rule, $clip);
    }

    /** A stroke is filled as its outline, wound non-zero: the outline comes from Rendering's Stroker. */
    protected function strokePath(GEFramebuffer $onto, Path $path, Color $color, StrokeStyle $style, Region $clip): void
    {
        $contours = [];
        foreach ($path->flatten() as ['points' => $points, 'closed' => $closed]) {
            $pieces = $style->dash === []
                ? [['points' => $points, 'closed' => $closed, 'direction' => null]]
                : Stroker::dashed($points, $closed, $style->dash, $style->dashOffset);
            foreach ($pieces as $piece) {
                foreach (Stroker::outline($piece['points'], $style->width, $piece['closed'], $style->cap, $style->join, $style->miterLimit, $piece['direction']) as $flat) {
                    $contours[] = array_chunk($flat, 2);
                }
            }
        }
        $this->paint($onto, $contours, $color, FillRule::NON_ZERO, $clip);
    }

    /**
     * @param list<list<array{float, float}>> $contours
     *
     * @throws VulkanException
     */
    protected function paint(GEFramebuffer $onto, array $contours, Color $color, FillRule $rule, Region $clip): void
    {
        // the fans, then the covering rectangle, as one list of points
        $points = [];
        [$left, $top, $right, $bottom] = [INF, INF, -INF, -INF];
        foreach ($contours as $contour) {
            $count = count($contour);
            if ($count < 3) {
                continue;
            }
            for ($i = 1; $i < $count - 1; $i++) {
                array_push($points, ...$contour[0], ...$contour[$i], ...$contour[$i + 1]);
            }
            foreach ($contour as [$x, $y]) {
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
            }
        }
        if ($points === []) {
            return;
        }
        $fan = intdiv(count($points), 2);
        array_push($points, $left, $top, $right, $top, $right, $bottom, $left, $top, $right, $bottom, $left, $bottom);

        // the pass covers the shape's box inside the clip and no more: a small shape costs what it covers
        $area = $clip->intersect(new Region((int) floor($left) - 1, (int) floor($top) - 1, (int) ceil($right) - (int) floor($left) + 2, (int) ceil($bottom) - (int) floor($top) + 2));
        if (is_null($area) || $area->isEmpty()) {
            return;
        }

        /** @var VulkanFramebuffer $onto the base checked it is this engine's */
        $antialiased = $this->edges === Edges::ANTIALIASED;
        $rgba = pack('V4', (int) round($color->red * 255), (int) round($color->green * 255), (int) round($color->blue * 255), (int) round($color->alpha * 255));

        $commands = $this->device->commands();
        $onto->begin($commands, $antialiased, pack('g*', ...$points), area: $area);

        vkCmdBindPipeline($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $onto->pipeline('solid', $antialiased, stencil: $rule === FillRule::EVEN_ODD ? 'invert' : 'winding'));
        $onto->paint($commands, $rgba);
        vkCmdDraw($commands, $fan, 1, 0, 0);

        vkCmdBindPipeline($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $onto->pipeline('solid', $antialiased, stencil: 'cover'));
        vkCmdDraw($commands, 6, 1, $fan, 0);

        $onto->end($commands, $antialiased);
        $this->device->submit($commands);
    }
}
