<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/**
 * A validated Trace-It post ID.
 *
 * The rules are Trace-It's, copied from sanitizePostId() in its api-qr.ts:
 * letters, digits, underscore and hyphen; must start AND end alphanumeric;
 * lowercased, so post IDs are case-insensitive; 48 characters maximum.
 *
 * Note what is NOT allowed: dots. A numeric CMS post ID is ideal.
 *
 * Validating here rather than letting the API reject it turns a confusing
 * `400 invalid_post_id` from a third party into a local exception naming the
 * offending value. And normalising in one place means our cache keys and
 * Trace-It's IDs cannot drift out of step — if we cached under "Post-1" and
 * Trace-It stored "post-1", every lookup would miss and every publish would
 * look like a new article.
 *
 * IDs are deliberately rejected rather than rewritten. Rewriting could map two
 * distinct posts onto one QR, which is worse than an error.
 */
final class PostId implements \Stringable
{
    public const MAX_LENGTH = 48;

    private const PATTERN = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/';

    private function __construct(private readonly string $value)
    {
    }

    /**
     * @param  string|int $raw The CMS's own post/article identifier.
     * @throws InvalidPostId
     */
    public static function from(string|int $raw): self
    {
        $trimmed = trim((string) $raw);

        if ($trimmed === '') {
            throw new InvalidPostId('postId is required and must not be empty.');
        }

        if (strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidPostId(sprintf(
                'postId must be %d characters or fewer (got %d): "%s".',
                self::MAX_LENGTH,
                strlen($trimmed),
                $trimmed
            ));
        }

        $lowered = strtolower($trimmed);

        if (!preg_match(self::PATTERN, $lowered)) {
            throw new InvalidPostId(sprintf(
                'postId "%s" is not valid. Use only letters, digits, underscore and hyphen, '
                . 'starting and ending with a letter or digit. Dots and slashes are not allowed — '
                . 'if your CMS uses slugs, pass the numeric post ID instead.',
                $trimmed
            ));
        }

        return new self($lowered);
    }

    /** True if $raw would be accepted, without throwing. */
    public static function isValid(string|int $raw): bool
    {
        try {
            self::from($raw);
            return true;
        } catch (InvalidPostId) {
            return false;
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
