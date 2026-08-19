<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/**
 * Where the badge goes and how big it is.
 *
 * Sizes are fractions, not pixels, because the composite happens at the photo's
 * native resolution — which varies per image — and a badge specified in pixels
 * would be huge on a thumbnail and invisible on a full-size photo.
 */
final class Layout
{
    /**
     * @param float  $scale        QR width as a fraction of the image's SHORT side.
     * @param int    $minPx        Below this a printed code stops being reliably
     *                             scannable, so it is better to skip the badge.
     * @param int    $maxPx        Upper bound, to stop the badge dominating a large photo.
     * @param float  $padding      Inset from the image edge, fraction of the short side.
     * @param string $corner       bottom-right | bottom-left | top-right | top-left
     * @param bool   $plate        White card behind the code. OFF by default: a
     *                             branded Trace-It PNG already is a white rounded
     *                             card, so a plate adds a visible second border.
     *                             Turn it on for a bare transparent QR.
     * @param float  $platePadding Plate padding as a fraction of QR width.
     * @param float  $radius       Plate corner radius as a fraction of QR width.
     */
    public function __construct(
        public readonly float $scale = 0.28,
        public readonly int $minPx = 96,
        public readonly int $maxPx = 420,
        public readonly float $padding = 0.035,
        public readonly string $corner = 'bottom-right',
        public readonly bool $plate = false,
        public readonly float $platePadding = 0.07,
        public readonly float $radius = 0.06,
    ) {
    }

    /** @param array<string,mixed> $overrides */
    public function with(array $overrides): self
    {
        return new self(
            scale:        (float) ($overrides['scale'] ?? $this->scale),
            minPx:        (int) ($overrides['minPx'] ?? $this->minPx),
            maxPx:        (int) ($overrides['maxPx'] ?? $this->maxPx),
            padding:      (float) ($overrides['padding'] ?? $this->padding),
            corner:       (string) ($overrides['corner'] ?? $this->corner),
            plate:        (bool) ($overrides['plate'] ?? $this->plate),
            platePadding: (float) ($overrides['platePadding'] ?? $this->platePadding),
            radius:       (float) ($overrides['radius'] ?? $this->radius),
        );
    }

    /**
     * Works out the badge box so it ALWAYS fits inside the image.
     *
     * Worth reading before changing: the obvious version of this is wrong, and was
     * wrong in the prototype this package came from. That version applied the
     * minimum-size floor and only then clamped against the image WIDTH, with
     * nothing constraining height — so on a wide, short thumbnail the badge landed
     * off the top edge. A 400x120 photo put it at y = -62 and the code was
     * silently clipped to something unscannable.
     *
     * Here the floor is an intent, BOTH axes constrain the result, and if what is
     * left is too small to scan the badge is skipped rather than drawn broken.
     *
     * The aspect ratio is passed in rather than assumed: a branded Trace-It PNG is
     * 1024x1362, taller than wide because of the label banner, so anything
     * assuming a square badge distorts it.
     *
     * @param float $qrAspect height / width of the QR image.
     */
    public function plan(int $imageWidth, int $imageHeight, float $qrAspect): BadgePlan
    {
        // With no plate there is no plate padding: the badge box IS the QR box.
        $platePadFrac = $this->plate ? $this->platePadding : 0.0;

        $shortSide = min($imageWidth, $imageHeight);
        $pad       = (int) round($shortSide * $this->padding);

        $qrWidth = (int) round($shortSide * $this->scale);
        $qrWidth = max($this->minPx, min($this->maxPx, $qrWidth));

        // The plate is what has to fit, not the QR: it is larger on every side.
        $factorW = 1 + 2 * $platePadFrac;
        $factorH = $qrAspect + 2 * $platePadFrac;

        $fitW = ($imageWidth - 2 * $pad) / $factorW;
        $fitH = ($imageHeight - 2 * $pad) / $factorH;

        $qrWidth = (int) floor(min($qrWidth, $fitW, $fitH));

        if ($qrWidth < 40) {
            return BadgePlan::doesNotFit(sprintf(
                'a %dx%d image is too small to carry a scannable code',
                $imageWidth,
                $imageHeight
            ));
        }

        $platePadding = (int) round($qrWidth * $platePadFrac);
        $qrHeight     = (int) round($qrWidth * $qrAspect);
        $plateWidth   = $qrWidth + $platePadding * 2;
        $plateHeight  = $qrHeight + $platePadding * 2;

        $right  = $imageWidth - $plateWidth - $pad;
        $bottom = $imageHeight - $plateHeight - $pad;

        [$x, $y] = match ($this->corner) {
            'bottom-left' => [$pad, $bottom],
            'top-right'   => [$right, $pad],
            'top-left'    => [$pad, $pad],
            default       => [$right, $bottom],
        };

        return new BadgePlan(
            fits: true,
            qrWidth: $qrWidth,
            qrHeight: $qrHeight,
            plateWidth: $plateWidth,
            plateHeight: $plateHeight,
            platePadding: $platePadding,
            x: $x,
            y: $y,
            radius: max(2, (int) round($qrWidth * $this->radius)),
            plate: $this->plate,
        );
    }
}
