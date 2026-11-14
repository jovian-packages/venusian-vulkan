<?php

namespace Jovian\Engines\Vulkan;

use Surface\Contracts\Graphics\Effects\Blend;
use Surface\Contracts\Graphics\Effects\BlendMode;
use Surface\Contracts\Graphics\Effects\Blur;
use Surface\Contracts\Graphics\Effects\BlurSampling;
use Surface\Contracts\Graphics\Effects\Channel;
use Surface\Contracts\Graphics\Effects\ColorMatrix;
use Surface\Contracts\Graphics\Effects\Composite;
use Surface\Contracts\Graphics\Effects\CompositeOperator;
use Surface\Contracts\Graphics\Effects\Displace;
use Surface\Contracts\Graphics\Effects\EdgeMode;
use Surface\Contracts\Graphics\Effects\Field;
use Surface\Contracts\Graphics\Effects\FieldKind;
use Surface\Contracts\Graphics\Effects\FilterChain;
use Surface\Contracts\Graphics\Effects\FilterStep;
use Surface\Contracts\Graphics\Effects\Flood;
use Surface\Contracts\Graphics\Effects\Noise;
use Surface\Contracts\Graphics\Effects\NoiseType;
use Surface\Contracts\Graphics\Effects\Table;
use Surface\Contracts\Rendering\Framebuffers\Framebuffer;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandFilters;
use VkClearColorValue;
use VkClearValue;
use VkCommandBuffer;
use VkDescriptorSet;
use VkFramebufferCreateInfo;
use VkRect2D;
use VkRenderPassBeginInfo;
use VkViewport;

/**
 * Filter chains, glass captures and the glass pyramid on the GPU. Every image is an RGBA8 image; each step is one
 * render pass that draws a triangle over the image and works out each pixel from its inputs (shaders/engine: one
 * fragment a step). What a step reads past its push constants (blur weights, tables, spiral points, band weights,
 * a noise lattice) is a data image: 32-bit words, 1024 a row, written by the command buffer itself (VKLogicalDevice::data()).
 *
 * Images live for one frame: they come from a pool by size, rounded up to 64 pixels, and go back to it when the
 * frame ends (release()). One no frame used is let go then, once the frame is finished. Data images go back to
 * their pool once the GPU has finished the frame that wrote them; a noise lattice is written once a seed and kept.
 *
 * Inputs and results are passed as the descriptor set that reads the image: set 0 and set 1 of a pass.
 */
class VulkanFilters
{
    /** An image's sides are multiples of this many pixels in the pool. */
    protected const int STEP = 64;

    /** Words a row of a data image. */
    protected const int WORDS = 1024;

    /** @var array<string, list<array{\VkImage, \VkDeviceMemory, \VkImageView, \VkDescriptorPool, VkDescriptorSet, \VkFramebuffer}>> images by rounded size */
    protected array $pool = [];

    /** @var array<string, int> how many of each size this frame has taken */
    protected array $taken = [];

    /** @var array<int, list<array{\VkImage, \VkDeviceMemory, \VkImageView, \VkDescriptorPool, VkDescriptorSet}>> data images free to take, by rows */
    protected array $data = [];

    /** @var list<array{int, array{\VkImage, \VkDeviceMemory, \VkImageView, \VkDescriptorPool, VkDescriptorSet}}> data images this frame took, by rows */
    protected array $lent = [];

    /** @var array<int, array{\VkImage, \VkDeviceMemory, \VkImageView, \VkDescriptorPool, VkDescriptorSet}> each seed's noise lattice */
    protected array $lattices = [];

    public function __construct(protected readonly VKLogicalDevice $device) {}

    /** Everything it holds, once nothing the GPU was handed still reads it. */
    public function __destruct()
    {
        if (! $this->device->isOpen()) {
            return;
        }
        $this->device->sync();
        foreach ($this->pool as $images) {
            foreach ($images as $image) {
                $this->destroy($image);
            }
        }
        foreach ([...array_merge(...array_values($this->data) ?: [[]]), ...array_column($this->lent, 1), ...array_values($this->lattices)] as $image) {
            $this->destroy($image);
        }
        [$this->pool, $this->data, $this->lent, $this->lattices] = [[], [], [], []];
    }

    /**
     * Runs $chain over a $width x $height image, the chain's source. Every step works on premultiplied colour, as
     * PHPLandFilters::run() does; the result is premultiplied too.
     *
     * @param VkDescriptorSet $source reads premultiplied colour, the image at its top left
     * @param int $originX where the image's top left lies on the surface: noise and fields are functions of position
     *
     * @throws VulkanException
     */
    public function run(FilterChain $chain, VkCommandBuffer $commands, VkDescriptorSet $source, int $width, int $height, int $originX, int $originY): VkDescriptorSet
    {
        $results = [FilterStep::SOURCE => $source];
        $previous = $source;
        foreach ($chain->steps as $step) {
            $input = fn (?string $name): VkDescriptorSet => $name === null || $name === '' ? $previous : $results[$name];
            $made = match (true) {
                $step instanceof Blur => $this->blur($commands, $input($step->in), $width, $height, $step),
                $step instanceof ColorMatrix => $this->pass($commands, 'color_matrix', $width, $height, [$input($step->in)], pack('g20', ...$step->values)),
                $step instanceof Flood => $this->pass($commands, 'flood', $width, $height, [], pack('V4', ...self::floodPixel($step))),
                $step instanceof Composite => $this->pass($commands, 'composite', $width, $height, [$input($step->in), $input($step->in2)], pack('V4g4', self::operator($step->operator), 0, 0, 0, $step->k1, $step->k2, $step->k3, $step->k4)),
                $step instanceof Blend => $this->pass($commands, 'blend', $width, $height, [$input($step->in), $input($step->in2)], pack('V', self::mode($step->mode))),
                $step instanceof Noise => $this->noise($commands, $step, $width, $height, $originX, $originY),
                $step instanceof Displace => $this->displace($commands, $input($step->in), $width, $height, $step, is_string($step->map) ? $results[$step->map] : null),
                $step instanceof Field => $this->field($commands, $step, $width, $height, $originX, $originY),
                $step instanceof Table => $this->pass($commands, 'table', $width, $height, [$input($step->in)], '', self::tables($step)),
            };
            $previous = $made;
            if (($name = $step->named()) !== null) {
                $results[$name] = $made;
            }
        }

        return $previous;
    }

    /**
     * A $width x $height region of what $source reads at ($x, $y) as an image of its own; past the source's own
     * $sourceWidth x $sourceHeight, transparent. With $straight, unpremultiplied, as Velvet's glass reads its backdrop.
     *
     * @throws VulkanException
     */
    public function grab(VkCommandBuffer $commands, VkDescriptorSet $source, int $sourceWidth, int $sourceHeight, int $x, int $y, int $width, int $height, bool $straight): VkDescriptorSet
    {
        return $this->pass($commands, 'grab', $width, $height, [$source], pack('l4V', $x, $y, $sourceWidth, $sourceHeight, $straight ? 1 : 0));
    }

    /**
     * One level down of a $width x $height region at the top left of $source: PHPLandGlass::down().
     *
     * @throws VulkanException
     */
    public function kawaseDown(VkCommandBuffer $commands, VkDescriptorSet $source, int $width, int $height): VkDescriptorSet
    {
        return $this->pass($commands, 'kawase_down', intdiv($width + 1, 2), intdiv($height + 1, 2), [$source], pack('l4', 0, 0, $width, $height));
    }

    /**
     * One level up of a $sourceWidth x $sourceHeight level, $width x $height: PHPLandGlass::up().
     *
     * @throws VulkanException
     */
    public function kawaseUp(VkCommandBuffer $commands, VkDescriptorSet $source, int $sourceWidth, int $sourceHeight, int $width, int $height): VkDescriptorSet
    {
        return $this->pass($commands, 'kawase_up', $width, $height, [$source], pack('l2', $sourceWidth, $sourceHeight));
    }

    /**
     * The frame in $commands is over: every image goes back to the pool; one this frame did not use, and the data
     * images it wrote, go once the GPU has finished it.
     */
    public function release(VkCommandBuffer $commands): void
    {
        $gone = [];
        foreach ($this->pool as $size => $images) {
            array_push($gone, ...array_slice($images, $this->taken[$size] ?? 0));
            $this->pool[$size] = array_slice($images, 0, $this->taken[$size] ?? 0);
        }
        $this->pool = array_filter($this->pool);
        [$lent, $this->lent, $this->taken] = [$this->lent, [], []];
        if ($gone === [] && $lent === []) {
            return;
        }
        $this->device->after($commands, function () use ($gone, $lent): void {
            foreach ($gone as $image) {
                $this->destroy($image);
            }
            foreach ($lent as [$rows, $image]) {
                $this->data[$rows][] = $image;
            }
        });
    }

    /** @throws VulkanException */
    protected function blur(VkCommandBuffer $commands, VkDescriptorSet $image, int $width, int $height, Blur $blur): VkDescriptorSet
    {
        $edge = $blur->edge === EdgeMode::DUPLICATE ? 1 : 0;
        if ($blur->sampling === BlurSampling::SPIRAL) {
            if ($blur->x <= 0.0) {
                return $image;
            }
            $offsets = '';
            foreach (PHPLandFilters::spiralOffsets($blur->x, $blur->samples) as [$dx, $dy]) {
                $offsets .= pack('g2', $dx, $dy);
            }

            return $this->pass($commands, 'spiral_blur', $width, $height, [$image], pack('l2V2', $width, $height, $blur->samples, $edge), $offsets);
        }
        foreach ([[true, $blur->x], [false, $blur->y]] as [$horizontal, $sigma]) {
            if ($sigma <= 0.0) {
                continue;
            }
            if ($sigma < 2.0) {
                $weights = PHPLandFilters::kernelWeights($sigma);
                $radius = intdiv(count($weights) - 1, 2);
                $image = $this->pass($commands, 'kernel_blur', $width, $height, [$image], pack('l2V3', $width, $height, $horizontal ? 1 : 0, $radius, $edge), pack('l*', ...$weights));

                continue;
            }
            foreach (PHPLandFilters::boxes($sigma) as [$size, $left]) {
                $image = $this->pass($commands, 'box_blur', $width, $height, [$image], pack('l2V2lV', $width, $height, $horizontal ? 1 : 0, $size, $left, $edge));
            }
        }

        return $image;
    }

    /** @throws VulkanException */
    protected function noise(VkCommandBuffer $commands, Noise $noise, int $width, int $height, int $originX, int $originY): VkDescriptorSet
    {
        $type = match ($noise->type) {
            NoiseType::TURBULENCE => 0,
            NoiseType::FRACTAL => 1,
            NoiseType::HASH => 2,
        };
        [$frequencyX, $frequencyY] = PHPLandFilters::stitched($noise, $width, $height);
        $stitch = [0, 0, 0, 0];
        if ($noise->stitch) {
            $w = (int) ($width * $frequencyX + 0.5);
            $h = (int) ($height * $frequencyY + 0.5);
            $stitch = [$w, $h, (int) ($originX * $frequencyX + 4096 + $w), (int) ($originY * $frequencyY + 4096 + $h)];
        }
        $bytes = pack('l2lV', $originX, $originY, $noise->seed, $type).pack('g2', $frequencyX, $frequencyY).pack('V2', $noise->octaves, $noise->stitch ? 1 : 0).pack('l4', ...$stitch);

        // the lattice: 514 selector entries (uint16, 1028 bytes), then 4 x 514 gradients (float2). A seed's lattice
        // never changes: written once, by the frame that first needs it, and kept
        if (! isset($this->lattices[$noise->seed])) {
            $tables = PHPLandFilters::noiseTables($noise->seed);
            $rows = self::rows(strlen($tables));
            $this->lattices[$noise->seed] = $this->dataImage($rows);
            $this->device->data($commands, $tables, $this->lattices[$noise->seed][0], $rows);
        }

        return $this->pass($commands, 'noise', $width, $height, [], $bytes, null, $this->lattices[$noise->seed][4]);
    }

    /** @throws VulkanException */
    protected function field(VkCommandBuffer $commands, Field $field, int $width, int $height, int $originX, int $originY): VkDescriptorSet
    {
        $kind = match ($field->kind) {
            FieldKind::ROUNDED_BOX => 0,
            FieldKind::INSIDE => 1,
            FieldKind::RAMP => 2,
        };
        $bytes = pack('l2V2', $originX, $originY, $kind, 0).pack('g4', ...($field->box ?? [0.0, 0.0, 0.0, 0.0])).pack('g4', ...$field->radii).pack('g2', $field->from, $field->to);

        return $this->pass($commands, 'field', $width, $height, [], $bytes);
    }

    /**
     * @param VkDescriptorSet|null $named the map an earlier step made, premultiplied; null: the step's own framebuffer
     *
     * @throws VulkanException
     */
    protected function displace(VkCommandBuffer $commands, VkDescriptorSet $image, int $width, int $height, Displace $step, ?VkDescriptorSet $named): VkDescriptorSet
    {
        if (is_null($named)) {
            /** @var Framebuffer $framebuffer */
            $framebuffer = $step->map;
            [$mapWidth, $mapHeight] = [$framebuffer->viewportWidth(), $framebuffer->viewportHeight()];
            $map = $this->upload($commands, $framebuffer->toRgba8(), $mapWidth, $mapHeight);
        } else {
            [$map, $mapWidth, $mapHeight] = [$named, $width, $height];
        }
        $channel = fn (Channel $c): int => match ($c) {
            Channel::R => 0,
            Channel::G => 1,
            Channel::B => 2,
            Channel::A => 3,
        };
        $weights = '';
        foreach ($step->weights ?? PHPLandFilters::bandWeights($step->bands) as [$r, $g, $b]) {
            $weights .= pack('g4', $r, $g, $b, 0.0);
        }
        $bytes = pack('l4', $width, $height, $mapWidth, $mapHeight).pack('g2', $step->scale, $step->spread).pack('V4', $channel($step->xChannel), $channel($step->yChannel), $step->bands, is_null($named) ? 0 : 1);

        return $this->pass($commands, 'displace', $width, $height, [$image, $map], $bytes, $weights);
    }

    /**
     * $rgba8 ($width x $height, rows from the top, straight) as an image for this frame: a data image of its words,
     * drawn into an RGBA8 image by a pass that unpacks them. The CPU never writes an image the GPU may be reading.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function upload(VkCommandBuffer $commands, string $rgba8, int $width, int $height): VkDescriptorSet
    {
        return $this->pass($commands, 'unpack', $width, $height, [], pack('V2', $width, $height), $rgba8);
    }

    /**
     * The image a set this frame's pool made reads, for reading it back.
     *
     * @internal
     */
    public function image(VkDescriptorSet $set): ?\VkImage
    {
        foreach ($this->pool as $images) {
            foreach ($images as $image) {
                if ($image[4] === $set) {
                    return $image[0];
                }
            }
        }

        return null;
    }

    /**
     * One pass: $fragment over a $width x $height image, into an image of the pool.
     *
     * @param list<VkDescriptorSet> $inputs sets 0 and 1
     * @param string $push the fragment's push constants
     * @param string|null $data its data image's words, set 2
     * @param VkDescriptorSet|null $kept a data image already written (a lattice), set 2
     *
     * @throws VulkanException
     */
    protected function pass(VkCommandBuffer $commands, string $fragment, int $width, int $height, array $inputs, string $push = '', ?string $data = null, ?VkDescriptorSet $kept = null): VkDescriptorSet
    {
        if (strlen($push) > VKLogicalDevice::PUSH_PAINT) {
            throw VulkanException::because("the '{$fragment}' pass was given ".strlen($push).' bytes of push constants: the fragment stage has '.VKLogicalDevice::PUSH_PAINT.'.');
        }
        $out = $this->take($width, $height);
        $sets = $inputs;
        if (! is_null($data)) {
            // the data first: a copy is not allowed inside a render pass
            $rows = self::rows(strlen($data));
            $image = empty($this->data[$rows]) ? $this->dataImage($rows) : array_pop($this->data[$rows]);
            $this->lent[] = [$rows, $image];
            $this->device->data($commands, $data, $image[0], $rows);
            $kept = $image[4];
        }
        if (! is_null($kept)) {
            $sets[2] = $kept;
        }
        $pipeline = $this->device->pipeline('cover', $fragment, 'plain', blend: false);

        $clear = new VkClearValue;
        $clear->color = new VkClearColorValue;
        $clear->color->float32 = [0.0, 0.0, 0.0, 0.0];
        $begin = new VkRenderPassBeginInfo;
        $begin->renderPass = $this->device->pass('plain', VK_ATTACHMENT_LOAD_OP_DONT_CARE);
        $begin->framebuffer = $out[5];
        [$begin->renderArea->extent->width, $begin->renderArea->extent->height] = [$width, $height];
        $begin->clearValueCount = 1;
        $begin->pClearValues = [$clear];
        vkCmdBeginRenderPass($commands, $begin, VK_SUBPASS_CONTENTS_INLINE);
        $viewport = new VkViewport;
        [$viewport->x, $viewport->y, $viewport->width, $viewport->height, $viewport->minDepth, $viewport->maxDepth] = [0.0, 0.0, (float) $width, (float) $height, 0.0, 1.0];
        vkCmdSetViewport($commands, 0, 1, [$viewport]);
        $rect = new VkRect2D;
        [$rect->extent->width, $rect->extent->height] = [$width, $height];
        vkCmdSetScissor($commands, 0, 1, [$rect]);

        vkCmdBindPipeline($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $pipeline);
        if ($sets !== []) {
            // sets bound from 0 up: a slot the fragment does not read takes any set
            $last = max(array_keys($sets));
            $any = reset($sets);
            $bound = [];
            for ($i = 0; $i <= $last; $i++) {
                $bound[] = $sets[$i] ?? $any;
            }
            vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->device->layout(), 0, count($bound), $bound, 0, []);
        }
        if ($push !== '') {
            vkCmdPushConstants($commands, $this->device->layout(), VK_SHADER_STAGE_FRAGMENT_BIT, VKLogicalDevice::PUSH_FRAME, strlen($push), $push);
        }
        vkCmdDraw($commands, 3, 1, 0, 0);
        vkCmdEndRenderPass($commands);

        return $out[4];
    }

    /**
     * An image of the pool, for this frame only.
     *
     * @return array{\VkImage, \VkDeviceMemory, \VkImageView, \VkDescriptorPool, VkDescriptorSet, \VkFramebuffer}
     *
     * @throws VulkanException
     */
    protected function take(int $width, int $height): array
    {
        [$w, $h] = [self::round($width), self::round($height)];
        $size = "{$w}x{$h}";
        $index = $this->taken[$size] ?? 0;
        $this->taken[$size] = $index + 1;
        if (isset($this->pool[$size][$index])) {
            return $this->pool[$size][$index];
        }

        $device = $this->device;
        [$image, $memory] = $device->image(VKLogicalDevice::COLOR, $w, $h, 1, VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT | VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_HOST_TRANSFER_BIT);
        $device->hostGeneral($image);
        $view = $device->view($image, VKLogicalDevice::COLOR, VK_IMAGE_ASPECT_COLOR_BIT);
        [$pool, [$set]] = $device->samplerSets(1);
        $device->point($set, $view);
        $info = new VkFramebufferCreateInfo;
        $info->renderPass = $device->pass('plain', VK_ATTACHMENT_LOAD_OP_DONT_CARE);
        $info->attachmentCount = 1;
        $info->pAttachments = [$view];
        [$info->width, $info->height, $info->layers] = [$w, $h, 1];
        VKLogicalDevice::ok(vkCreateFramebuffer($device->device(), $info, 0, $framebuffer), 'vkCreateFramebuffer');

        return $this->pool[$size][$index] = [$image, $memory, $view, $pool, $set, $framebuffer];
    }

    /**
     * A data image of $rows rows of 1024 words.
     *
     * @return array{\VkImage, \VkDeviceMemory, \VkImageView, \VkDescriptorPool, VkDescriptorSet}
     *
     * @throws VulkanException
     */
    protected function dataImage(int $rows): array
    {
        [$image, $memory] = $this->device->image(VK_FORMAT_R32_UINT, self::WORDS, $rows, 1, VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_TRANSFER_DST_BIT);
        $view = $this->device->view($image, VK_FORMAT_R32_UINT, VK_IMAGE_ASPECT_COLOR_BIT);
        [$pool, [$set]] = $this->device->samplerSets(1);
        $this->device->point($set, $view);

        return [$image, $memory, $view, $pool, $set];
    }

    /** @param array<int, mixed> $image what take() or dataImage() made */
    protected function destroy(array $image): void
    {
        $vk = $this->device->device();
        if (isset($image[5])) {
            vkDestroyFramebuffer($vk, $image[5], 0);
        }
        vkDestroyDescriptorPool($vk, $image[3], 0);
        vkDestroyImageView($vk, $image[2], 0);
        vkDestroyImage($vk, $image[0], 0);
        vkFreeMemory($vk, $image[1], 0);
    }

    /** Rows of 1024 words that hold $bytes: a power of two, so few sizes are pooled. */
    protected static function rows(int $bytes): int
    {
        $rows = max(1, intdiv($bytes + self::WORDS * 4 - 1, self::WORDS * 4));

        return 1 << (int) ceil(log($rows, 2));
    }

    /** @return array{int, int, int, int} a flood's colour, premultiplied as PHPLandFilters::flood() does */
    protected static function floodPixel(Flood $flood): array
    {
        $a = (int) round($flood->color->alpha * 255);
        $p = fn (float $c): int => intdiv((int) round($c * 255) * $a + 127, 255);

        return [$p($flood->color->red), $p($flood->color->green), $p($flood->color->blue), $a];
    }

    /** Four tables of 256 bytes, red, green, blue, alpha; an identity for one the step left out. */
    protected static function tables(Table $table): string
    {
        $bytes = '';
        foreach ([$table->red, $table->green, $table->blue, $table->alpha] as $entries) {
            $bytes .= pack('C256', ...($entries ?? range(0, 255)));
        }

        return $bytes;
    }

    protected static function operator(CompositeOperator $operator): int
    {
        return match ($operator) {
            CompositeOperator::OVER => 0,
            CompositeOperator::IN => 1,
            CompositeOperator::OUT => 2,
            CompositeOperator::ATOP => 3,
            CompositeOperator::XOR => 4,
            CompositeOperator::ARITHMETIC => 5,
        };
    }

    protected static function mode(BlendMode $mode): int
    {
        return match ($mode) {
            BlendMode::NORMAL => 0,
            BlendMode::MULTIPLY => 1,
            BlendMode::SCREEN => 2,
            BlendMode::DARKEN => 3,
            BlendMode::LIGHTEN => 4,
        };
    }

    protected static function round(int $side): int
    {
        return max(self::STEP, intdiv($side + self::STEP - 1, self::STEP) * self::STEP);
    }
}
