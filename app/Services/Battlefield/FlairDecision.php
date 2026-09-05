<?php

namespace App\Services\Battlefield;

/**
 * A model's resolved battlefield flair: which badge to show, and for how long.
 */
final readonly class FlairDecision
{
    /**
     * @param  string  $flair  the flair key broadcast to the client
     * @param  int  $durationMs  how long the badge stays up
     */
    public function __construct(
        public string $flair,
        public int $durationMs,
    ) {}
}
