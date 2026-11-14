<?php

namespace Jovian\Engines\Vulkan;

use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Raycasting\Camera;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\RaycastException;
use Surface\Contracts\Rendering\Raycasting\Scenery;
use Surface\Rendering\Raycasting\Raycaster;
use VkBuffer;
use VkClearColorValue;
use VkClearValue;
use VkCommandBuffer;
use VkDescriptorPool;
use VkDescriptorSet;
use VkDeviceMemory;
use VkFramebuffer;
use VkFramebufferCreateInfo;
use VkImage;
use VkImageView;
use VkMemoryBarrier;
use VkRect2D;
use VkRenderPassBeginInfo;
use VkViewport;
use WeakMap;

/**
 * The vulkan engine's raycaster. Rendering checks every call and turns angles into vectors, in
 * double, as it does for every driver. Then:
 *
 *  - Rays are walked by a compute dispatch, one invocation a ray (raycast_hits.comp), into a hits
 *    image: no render pass, which is what a submit-and-wait costs most on MoltenVK.
 *  - A view painted on a framebuffer stays on the GPU: the view is drawn from the hits
 *    (raycast_view.frag), and nothing comes back, so the CPU does not wait.
 *  - What comes back to the CPU (cast, casts, fan; a view as bytes) is one dispatch and one read of
 *    its hits, so many rays go in one call (casts, fan), never one call a ray.
 *
 * The grid's cells, the wall table and the wall textures are storage buffers, read through a
 * descriptor set: the textures need no atlas, and the grid is limited only by how many bytes a
 * storage buffer binding may span. A ray walks from its cell and where in that cell it is, so
 * float32 holds small numbers only; the cell, wall and face of a hit are the walk's own integers.
 */
class VulkanCaster extends Raycaster
{
    protected const int HITS_FORMAT = VK_FORMAT_R32G32B32A32_SFLOAT;

    /** Rays a compute workgroup walks: raycast_hits.comp's local_size_x. */
    protected const int GROUP = 64;

    /** The grid's cells, a byte each. */
    protected readonly VulkanKept $cells;

    /** @var array<int, true> the wall ids the grid holds */
    protected readonly array $ids;

    /** @var array{VkImage, VkDeviceMemory, VkImageView, VkDescriptorPool, VkDescriptorSet, null, int, int, VkDescriptorPool, VkDescriptorSet}|null a ray list's hits (what hitsImage() made), kept while ray lists are the same size */
    protected ?array $hits = null;

    /** @var array{VkImage, VkDeviceMemory, VkImageView, VkDescriptorPool, VkDescriptorSet, VkFramebuffer}|null a view read out as bytes, kept while views are the same size */
    protected ?array $out = null;

    /** The set the hits pass reads the cells through, and its pool. */
    protected readonly VkDescriptorSet $cellSet;

    protected readonly VulkanKept $cellPool;

    /** @var array{VkImage, VkDeviceMemory, VkImageView, VkDescriptorPool, VkDescriptorSet, int, int}|null rays' own directions (RG32F, written by the host), kept while ray lists are the same size */
    protected ?array $directions = null;

    /** @var array{VkImage, VkDeviceMemory, VkImageView, VkDescriptorPool, VkDescriptorSet, int, int} the 1x1 that stands in for no directions: the pass binds an image either way */
    protected array $noDirections;

    /** @var WeakMap<Scenery, array{VulkanKept, VulkanKept, VulkanKept, VkDescriptorSet}> each scenery's wall table, textures, and the set a view reads them through (with its pool) */
    protected WeakMap $sceneries;

    /**
     * @throws VulkanException When the grid's cells are more than a storage buffer binding may span.
     */
    public function __construct(
        protected readonly VKLogicalDevice $device,
        Grid $grid,
    ) {
        parent::__construct($grid);

        if (strlen($grid->cells) > $device->maxStorage()) {
            throw VulkanException::because("a grid {$grid->width}x{$grid->height} is ".strlen($grid->cells).' bytes: past the '.$device->maxStorage().' a storage buffer binding may span.');
        }

        $commands = $device->commands();
        $this->cells = new VulkanKept($device, $device->kept($commands, $grid->cells));
        $device->submit($commands);
        [$pool, $this->cellSet] = $device->storageSet([$this->cells->buffer, $this->cells->buffer, $this->cells->buffer]);
        $this->cellPool = new VulkanKept($device, null, $pool);
        $this->noDirections = $this->directionImage(1, 1);

        $ids = [];
        foreach (str_split(count_chars($grid->cells, 3)) as $byte) {
            if ($byte !== "\0" && $byte !== '') {
                $ids[ord($byte)] = true;
            }
        }
        $this->ids = $ids;
        $this->sceneries = new WeakMap;
    }

    public function __destruct()
    {
        if (! $this->device->isOpen()) {
            return;
        }
        $this->device->sync();
        foreach (array_filter([$this->hits, $this->out, $this->directions, $this->noDirections]) as $target) {
            $this->destroy($target);
        }
    }

    public function driver(): string
    {
        return 'vulkan';
    }

    /** A view as RGBA8. The scenery's buffers are kept for the next call with it. */
    public function view(Camera $camera, int $width, int $height, Scenery $scenery, float $reach = INF): string
    {
        return $this->viewBytes(self::pictureOf($camera, $width, $height, $scenery, $reach), $this->scenery($scenery));
    }

    /**
     * A view on a vulkan framebuffer at $where, drawn on the GPU from the GPU's hits.
     *
     * @internal
     *
     * @throws RaycastException When a ray hits a wall the scenery has no entry for: nothing is drawn.
     * @throws VulkanException
     */
    public function paintOn(Camera $camera, Scenery $scenery, VulkanFramebuffer $target, Region $where, float $reach): void
    {
        [$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $table, $ceiling, $floor, $shade, $reach] = self::pictureOf($camera, $where->width, $where->height, $scenery, $reach);
        $set = $this->scenery($scenery)[3];

        // rays and the view in one submission, and nothing comes back: the CPU does not wait for it
        $commands = $this->device->commands();
        $perRow = $this->hitsPass($commands, $x, $y, $dirX, $dirY, $planeX, $planeY, $width, $reach);
        $commands = $this->checked($commands, $perRow, $width, $table);

        $target->cover($commands, $where, 'raycast_view', $set, $this->hits[4], self::viewBytesOf($where->x, $where->y, $width, $height, $ceiling, $floor, $shade, $perRow, false));
        $this->device->submit($commands);
    }

    /**
     * Rays from ($x, $y), one an angle: their Hits records, in order. Checked as cast() checks one ray, and each
     * angle becomes its vector in double, as cast() makes it. Walked in one GPU pass.
     *
     * @param list<float> $angles
     *
     * @throws RaycastException
     * @throws VulkanException
     */
    public function casts(float $x, float $y, array $angles, float $reach = INF): string
    {
        foreach (['x' => $x, 'y' => $y] as $what => $value) {
            if (! is_finite($value)) {
                throw RaycastException::notFinite("A ray's {$what}");
            }
            if (abs($value) > self::LIMIT) {
                throw RaycastException::pastLimit("A ray's {$what}", $value);
            }
        }
        if (! ($reach > 0.0)) {
            throw RaycastException::reach($reach);
        }
        if ($angles === []) {
            return '';
        }
        foreach ($angles as $angle) {
            if (! is_finite($angle)) {
                throw RaycastException::notFinite("A ray's angle");
            }
        }

        $directions = '';
        foreach ($angles as $angle) {
            $directions .= pack('g2', cos($angle), sin($angle));
        }

        $commands = $this->device->commands();
        $perRow = $this->hitsPass($commands, $x, $y, 0.0, 0.0, 0.0, 0.0, count($angles), $reach, $directions);
        $this->device->submit($commands);
        $this->device->sync();

        return $this->records($perRow, count($angles));
    }

    protected function ray(float $x, float $y, float $dx, float $dy, float $reach): string
    {
        return $this->hitRecords($x, $y, $dx, $dy, 0.0, 0.0, 1, $reach);
    }

    protected function rays(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach): string
    {
        return $this->hitRecords($x, $y, $dirX, $dirY, $planeX, $planeY, $columns, $reach);
    }

    protected function picture(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $width, int $height, array $walls, int $ceiling, int $floor, int $shade, float $reach): string
    {
        // a table with no scenery to keep its buffers by: made for this call, gone with it
        return $this->viewBytes([$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $walls, $ceiling, $floor, $shade, $reach], $this->pack($walls));
    }

    /**
     * $columns rays as Hits records.
     *
     * @throws VulkanException
     */
    protected function hitRecords(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach): string
    {
        $commands = $this->device->commands();
        $perRow = $this->hitsPass($commands, $x, $y, $dirX, $dirY, $planeX, $planeY, $columns, $reach);
        $this->device->submit($commands);
        $this->device->sync();

        return $this->records($perRow, $columns);
    }

    /**
     * A view's straight RGBA8 bytes, from an offscreen image.
     *
     * @param array{float, float, float, float, float, float, int, int, array, int, int, int, float} $arguments pictureOf()'s
     * @param array{VulkanKept, VulkanKept, VulkanKept, VkDescriptorSet} $scenery what pack() made
     *
     * @throws RaycastException When a ray hits a wall with no entry.
     * @throws VulkanException
     */
    protected function viewBytes(array $arguments, array $scenery): string
    {
        [$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $table, $ceiling, $floor, $shade, $reach] = $arguments;
        $set = $scenery[3];

        $commands = $this->device->commands();
        $perRow = $this->hitsPass($commands, $x, $y, $dirX, $dirY, $planeX, $planeY, $width, $reach);
        $commands = $this->checked($commands, $perRow, $width, $table);

        $this->out = $this->target($this->out, $width, $height, VKLogicalDevice::COLOR);
        $this->pass($commands, $this->out, $width, $height, 'raycast_view', VKLogicalDevice::COLOR, [$set, $this->hits[4]], self::viewBytesOf(0, 0, $width, $height, $ceiling, $floor, $shade, $perRow, true));
        $this->device->submit($commands);
        $this->device->sync();

        return $this->device->hostRead($this->out[0], new Region(0, 0, $width, $height));
    }

    /**
     * Records the hits pass: $columns rays, one a pixel. Call it before a render pass begins.
     *
     * @return int how many rays a pair of the hits image's rows holds
     *
     * @throws VulkanException When the rays need a hits image taller than the GPU holds.
     */
    protected function hitsPass(VkCommandBuffer $commands, float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach, ?string $directions = null): int
    {
        $side = $this->device->maxSide();
        $perRow = min($columns, $side);
        $rows = 2 * intdiv($columns + $perRow - 1, $perRow);
        if ($rows > $side) {
            throw VulkanException::because("{$columns} rays need a hits image {$perRow}x{$rows}: past the GPU's images, {$side} a side.");
        }
        $this->hits = $this->hitsImage($this->hits, $perRow, $rows);

        // Velvet walks nothing from outside the grid: every ray misses
        $inside = $x >= 0.0 && $y >= 0.0 && $x < $this->grid->width && $y < $this->grid->height;
        $cellX = $inside ? (int) floor($x) : 0;
        $cellY = $inside ? (int) floor($y) : 0;
        // past what float32 holds, a reach stops nothing a grid this size can show
        $reaches = is_finite($reach) && $reach < 3.0e38;
        $flags = ($reaches ? 1 : 0) | ($inside ? 2 : 0) | (is_null($directions) ? 0 : 4);

        $cast = pack('l2g6V3gl2', $cellX, $cellY, $inside ? $x - $cellX : 0.0, $inside ? $y - $cellY : 0.0, $dirX, $dirY, $planeX, $planeY, $columns, $perRow, $flags, $reaches ? $reach : 0.0, $this->grid->width, $this->grid->height);
        $sets = [$this->cellSet, $this->directionsOf($directions, $perRow, intdiv($rows, 2)), $this->hits[9]];
        $layout = $this->device->computeLayout();

        // an earlier submission's reads and writes of the hits are done before these writes
        self::barrier($commands, VK_PIPELINE_STAGE_FRAGMENT_SHADER_BIT | VK_PIPELINE_STAGE_COMPUTE_SHADER_BIT, VK_ACCESS_SHADER_READ_BIT | VK_ACCESS_SHADER_WRITE_BIT, VK_PIPELINE_STAGE_COMPUTE_SHADER_BIT, VK_ACCESS_SHADER_WRITE_BIT);
        vkCmdBindPipeline($commands, VK_PIPELINE_BIND_POINT_COMPUTE, $this->device->computePipeline('raycast_hits'));
        vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_COMPUTE, $layout, 0, 3, $sets, 0, []);
        vkCmdPushConstants($commands, $layout, VK_SHADER_STAGE_COMPUTE_BIT, 0, strlen($cast), $cast);
        vkCmdDispatch($commands, intdiv($columns + self::GROUP - 1, self::GROUP), 1, 1);
        // the hits reach a view's fragments and the host
        self::barrier($commands, VK_PIPELINE_STAGE_COMPUTE_SHADER_BIT, VK_ACCESS_SHADER_WRITE_BIT, VK_PIPELINE_STAGE_FRAGMENT_SHADER_BIT | VK_PIPELINE_STAGE_HOST_BIT, VK_ACCESS_SHADER_READ_BIT | VK_ACCESS_HOST_READ_BIT);

        return $perRow;
    }

    protected static function barrier(VkCommandBuffer $commands, int $srcStage, int $srcAccess, int $dstStage, int $dstAccess): void
    {
        $barrier = new VkMemoryBarrier;
        $barrier->srcAccessMask = $srcAccess;
        $barrier->dstAccessMask = $dstAccess;
        vkCmdPipelineBarrier($commands, $srcStage, $dstStage, 0, 1, [$barrier], 0, [], 0, []);
    }

    /**
     * $hits if it is already $width x $height, else a new RGBA32F image that size: written by the hits pass as a
     * storage image, sampled by a view, read by the host. The old one is destroyed once nothing submitted uses it.
     *
     * @param array<int, mixed>|null $hits
     * @return array{VkImage, VkDeviceMemory, VkImageView, VkDescriptorPool, VkDescriptorSet, null, int, int, VkDescriptorPool, VkDescriptorSet}
     *
     * @throws VulkanException
     */
    protected function hitsImage(?array $hits, int $width, int $height): array
    {
        if (! is_null($hits) && $hits[6] === $width && $hits[7] === $height) {
            return $hits;
        }
        if (! is_null($hits)) {
            $this->device->sync();
            $this->destroy($hits);
        }

        $device = $this->device;
        [$image, $memory] = $device->image(self::HITS_FORMAT, $width, $height, 1, VK_IMAGE_USAGE_STORAGE_BIT | VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_HOST_TRANSFER_BIT);
        $device->hostGeneral($image);
        $view = $device->view($image, self::HITS_FORMAT, VK_IMAGE_ASPECT_COLOR_BIT);
        [$pool, [$set]] = $device->samplerSets(1);
        $device->point($set, $view);
        [$imagePool, $imageSet] = $device->imageSet($view);

        return [$image, $memory, $view, $pool, $set, null, $width, $height, $imagePool, $imageSet];
    }

    /**
     * The set the hits pass reads rays' own directions through: the directions written by the host into an RG32F image
     * (ray i at i % $perRow, i / $perRow), kept while it is the same size; with none, the 1x1 that stands in. The host
     * writes the image only once nothing submitted reads it.
     *
     * @throws VulkanException
     */
    protected function directionsOf(?string $directions, int $perRow, int $rows): VkDescriptorSet
    {
        if (is_null($directions)) {
            return $this->noDirections[4];
        }
        if (is_null($this->directions) || $this->directions[5] !== $perRow || $this->directions[6] !== $rows) {
            if (! is_null($this->directions)) {
                $this->device->sync();
                $this->destroy($this->directions);
            }
            $this->directions = $this->directionImage($perRow, $rows);
        }
        $this->device->sync();
        $this->device->hostWrite($this->directions[0], str_pad($directions, $perRow * $rows * 8, "\0"), new Region(0, 0, $perRow, $rows));

        return $this->directions[4];
    }

    /**
     * An RG32F image the host writes and the hits pass reads.
     *
     * @return array{VkImage, VkDeviceMemory, VkImageView, VkDescriptorPool, VkDescriptorSet, int, int}
     *
     * @throws VulkanException
     */
    protected function directionImage(int $width, int $height): array
    {
        $device = $this->device;
        [$image, $memory] = $device->image(VK_FORMAT_R32G32_SFLOAT, $width, $height, 1, VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_HOST_TRANSFER_BIT);
        $device->hostGeneral($image);
        $view = $device->view($image, VK_FORMAT_R32G32_SFLOAT, VK_IMAGE_ASPECT_COLOR_BIT);
        [$pool, [$set]] = $device->samplerSets(1);
        $device->point($set, $view);

        return [$image, $memory, $view, $pool, $set, $width, $height];
    }

    /**
     * When the grid holds a wall the table has no entry for, the hits are read before a view is drawn: a ray on such
     * a wall is refused, as Velvet refuses it, and nothing is drawn.
     *
     * @return VkCommandBuffer what to go on recording into
     *
     * @throws RaycastException
     */
    protected function checked(VkCommandBuffer $commands, int $perRow, int $columns, array $table): VkCommandBuffer
    {
        if (array_diff_key($this->ids, $table) === []) {
            return $commands;
        }

        $this->device->submit($commands);
        $this->device->sync();
        $records = $this->records($perRow, $columns);
        for ($i = 0; $i < $columns; $i++) {
            $wall = ord($records[$i * 22 + 20]);
            if ($wall !== 0 && ! isset($table[$wall])) {
                throw RaycastException::noWall($wall);
            }
        }

        return $this->device->commands();
    }

    /** The hits image's rays as Hits records: 22 bytes each, all zero for a miss. Only once the GPU has drawn them. */
    protected function records(int $perRow, int $columns): string
    {
        $rows = 2 * intdiv($columns + $perRow - 1, $perRow);
        $floats = unpack('g*', $this->device->hostRead($this->hits[0], new Region(0, 0, $perRow, $rows), 16));

        $out = '';
        for ($i = 0; $i < $columns; $i++) {
            // unpack counts from 1
            $first = ((2 * intdiv($i, $perRow)) * $perRow + $i % $perRow) * 4 + 1;
            $second = $first + $perRow * 4;
            $wall = (int) $floats[$second];
            $out .= $wall === 0
                ? str_repeat("\0", 22)
                : pack('eevvCC', $floats[$first], $floats[$first + 1], (int) $floats[$first + 2], (int) $floats[$first + 3], $wall, (int) $floats[$second + 1]);
        }

        return $out;
    }

    /**
     * The scenery on the GPU, made the first time it is drawn and never written again, so a frame the GPU is still
     * drawing can go on reading it.
     *
     * @return array{VulkanKept, VulkanKept, VulkanKept, VkDescriptorSet} what pack() made
     */
    protected function scenery(Scenery $scenery): array
    {
        return $this->sceneries[$scenery] ??= $this->pack($scenery->table());
    }

    /**
     * The wall table (per wall id: kind, colour, first texel word, width | height << 16) and every wall texture's
     * straight RGBA8 bytes, one after another, as storage buffers, and the set a view reads them through.
     *
     * @param array<int, int|array{string, int, int}> $table
     * @return array{VulkanKept, VulkanKept, VulkanKept, VkDescriptorSet} the wall table, the textures, the set's pool, the set
     *
     * @throws VulkanException When a texture is past 65535 a side, or the textures past a storage buffer binding.
     */
    protected function pack(array $table): array
    {
        $walls = str_repeat("\0", 256 * 16);
        $texels = '';
        foreach ($table as $id => $wall) {
            if (is_int($wall)) {
                $entry = pack('V4', 1, $wall, 0, 0);
            } else {
                [$bytes, $width, $height] = $wall;
                if ($width > 0xFFFF || $height > 0xFFFF) {
                    throw VulkanException::because("wall {$id}'s texture {$width}x{$height} is past 65535 a side.");
                }
                $entry = pack('V4', 2, 0, intdiv(strlen($texels), 4), $width | ($height << 16));
                $texels .= $bytes;
            }
            $walls = substr_replace($walls, $entry, $id * 16, 16);
        }
        if (strlen($texels) > $this->device->maxStorage()) {
            throw VulkanException::because("the scenery's wall textures are ".strlen($texels).' bytes: past the '.$this->device->maxStorage().' a storage buffer binding may span.');
        }

        $commands = $this->device->commands();
        $wallBuffer = new VulkanKept($this->device, $this->device->kept($commands, $walls));
        $texelBuffer = new VulkanKept($this->device, $this->device->kept($commands, $texels));
        $this->device->submit($commands);
        [$pool, $set] = $this->device->storageSet([$this->cells->buffer, $wallBuffer->buffer, $texelBuffer->buffer]);

        return [$wallBuffer, $texelBuffer, new VulkanKept($this->device, null, $pool), $set];
    }

    /**
     * $target if it is already $width x $height, else a new image of $format that size: colour attachment, sampled,
     * read by the host. The old one is destroyed once nothing submitted uses it.
     *
     * @param array{VkImage, VkDeviceMemory, VkImageView, VkDescriptorPool, VkDescriptorSet, VkFramebuffer}|null $target
     * @return array{VkImage, VkDeviceMemory, VkImageView, VkDescriptorPool, VkDescriptorSet, VkFramebuffer}
     *
     * @throws VulkanException
     */
    protected function target(?array $target, int $width, int $height, int $format): array
    {
        if (! is_null($target) && $target[6] === $width && $target[7] === $height) {
            return $target;
        }
        if (! is_null($target)) {
            $this->device->sync();
            $this->destroy($target);
        }

        $device = $this->device;
        [$image, $memory] = $device->image($format, $width, $height, 1, VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT | VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_HOST_TRANSFER_BIT);
        $device->hostGeneral($image);
        $view = $device->view($image, $format, VK_IMAGE_ASPECT_COLOR_BIT);
        [$pool, [$set]] = $device->samplerSets(1);
        $device->point($set, $view);
        $info = new VkFramebufferCreateInfo;
        $info->renderPass = $device->pass('plain', VK_ATTACHMENT_LOAD_OP_DONT_CARE, $format);
        $info->attachmentCount = 1;
        $info->pAttachments = [$view];
        [$info->width, $info->height, $info->layers] = [$width, $height, 1];
        VKLogicalDevice::ok(vkCreateFramebuffer($device->device(), $info, 0, $framebuffer), 'vkCreateFramebuffer');

        return [$image, $memory, $view, $pool, $set, $framebuffer, $width, $height];
    }

    /** @param array<int, mixed> $target what target() or directionImage() made */
    protected function destroy(array $target): void
    {
        $vk = $this->device->device();
        if ($target[5] instanceof VkFramebuffer) {
            vkDestroyFramebuffer($vk, $target[5], 0);
        }
        if (($target[8] ?? null) instanceof VkDescriptorPool) {
            vkDestroyDescriptorPool($vk, $target[8], 0);
        }
        vkDestroyDescriptorPool($vk, $target[3], 0);
        vkDestroyImageView($vk, $target[2], 0);
        vkDestroyImage($vk, $target[0], 0);
        vkFreeMemory($vk, $target[1], 0);
    }

    /**
     * One pass of $fragment over all of $target, replacing it.
     *
     * @param array<int, mixed> $target what target() made
     * @param list<VkDescriptorSet> $sets set 0 (storage) and, for a view, set 1 (the hits)
     *
     * @throws VulkanException
     */
    protected function pass(VkCommandBuffer $commands, array $target, int $width, int $height, string $fragment, int $format, array $sets, string $push): void
    {
        $pipeline = $this->device->pipeline('cover', $fragment, 'plain', blend: false, format: $format, raycast: true);
        $layout = $this->device->raycastLayout();

        $clear = new VkClearValue;
        $clear->color = new VkClearColorValue;
        $clear->color->float32 = [0.0, 0.0, 0.0, 0.0];
        $begin = new VkRenderPassBeginInfo;
        $begin->renderPass = $this->device->pass('plain', VK_ATTACHMENT_LOAD_OP_DONT_CARE, $format);
        $begin->framebuffer = $target[5];
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
        vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $layout, 0, count($sets), $sets, 0, []);
        vkCmdPushConstants($commands, $layout, VK_SHADER_STAGE_FRAGMENT_BIT, VKLogicalDevice::PUSH_FRAME, strlen($push), $push);
        vkCmdDraw($commands, 3, 1, 0, 0);
        vkCmdEndRenderPass($commands);
    }

    /** The View push constants of raycast_view.frag. */
    protected static function viewBytesOf(int $x, int $y, int $width, int $height, int $ceiling, int $floor, int $shade, int $perRow, bool $straight): string
    {
        return pack('l4V5', $x, $y, $width, $height, $ceiling, $floor, $shade, $perRow, $straight ? 1 : 0);
    }
}
