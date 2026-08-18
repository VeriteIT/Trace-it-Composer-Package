<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/** The resolved geometry of one badge. Output of Layout::plan(). */
final class BadgePlan
{
    public function __construct(
        public readonly bool $fits,
        public readonly int $qrWidth = 0,
        public readonly int $qrHeight = 0,
        public readonly int $plateWidth = 0,
        public readonly int $plateHeight = 0,
        public readonly int $platePadding = 0,
        public readonly int $x = 0,
        public readonly int $y = 0,
        public readonly int $radius = 0,
        public readonly bool $plate = false,
        public readonly ?string $reason = null,
    ) {
    }

    public static function doesNotFit(string $reason): self
    {
        return new self(fits: false, reason: $reason);
    }

    /**
     * True when the badge box lies entirely within the image.
     *
     * Exposed so callers can assert it in their own tests. The bug this guards
     * against produced negative coordinates, which GD draws without complaint.
     */
    public function isInside(int $imageWidth, int $imageHeight): bool
    {
        return $this->fits
            && $this->x >= 0
            && $this->y >= 0
            && $this->x + $this->plateWidth <= $imageWidth
            && $this->y + $this->plateHeight <= $imageHeight;
    }
}
