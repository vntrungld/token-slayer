<?php

namespace App\Services\Battlefield;

/**
 * A model's resolved battlefield flair: which badge to show, for how long,
 * and in what color.
 */
final readonly class FlairDecision
{
    /**
     * @param  string  $flair  the flair key broadcast to the client
     * @param  int  $durationMs  how long the badge stays up
     * @param  string  $color  hex color for the flair effect, admin-configured
     *                         or {@see ModelFlairResolver::DEFAULT_COLOR}
     */
    public function __construct(
        public string $flair,
        public int $durationMs,
        public string $color,
    ) {}
}
