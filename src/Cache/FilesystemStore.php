<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr\Cache;

use VeriteIt\TraceItQr\Misconfigured;

/**
 * Default Store: one JSON file per post, plus a lock file, in a directory.
 *
 * Chosen because it needs no services and no configuration — a custom CMS can
 * install this package and have working caching immediately. It is genuinely fine
 * for a newsroom: reads are a single file_get_contents, and writes only happen
 * once per article, ever.
 *
 * Swap it for Redis when you outgrow it (many app servers, or a read-only
 * filesystem). Nothing outside this class assumes files.
 */
final class FilesystemStore implements Store
{
    private string $dir;

    public function __construct(?string $directory = null)
    {
        $this->dir = rtrim($directory ?? (sys_get_temp_dir() . '/trace-it-qr'), '/\\');

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new Misconfigured(sprintf(
                'Cache directory "%s" does not exist and could not be created. Pass a writable '
                . 'path as cacheDir, or supply your own Store implementation.',
                $this->dir
            ));
        }

        if (!is_writable($this->dir)) {
            throw new Misconfigured(sprintf('Cache directory "%s" is not writable.', $this->dir));
        }
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * Hashed so the filename can never be influenced by request input, even
     * though PostId has already constrained the character set. Two layers is
     * cheap; a path built from user data is not.
     */
    private function path(string $postId, string $ext): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $postId) . '.' . $ext;
    }

    public function get(string $postId): ?array
    {
        $file = $this->path($postId, 'json');
        if (!is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function put(string $postId, array $record): void
    {
        $this->writeAtomic(
            $this->path($postId, 'json'),
            (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    public function forget(string $postId): void
    {
        @unlink($this->path($postId, 'json'));
        @unlink($this->path($postId, 'png'));
    }

    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        usort($out, static fn(array $a, array $b) => strcmp(
            (string) ($b['createdAt'] ?? ''),
            (string) ($a['createdAt'] ?? '')
        ));

        return $out;
    }

    public function lock(string $postId, callable $work): mixed
    {
        // A separate lock file, not the data file: a reader must never block on a
        // record being written, and a writer must not truncate what readers see.
        $handle = fopen($this->path($postId, 'lock'), 'c');
        if ($handle === false) {
            // Locking is an optimisation against duplicate spend, not a
            // correctness requirement — Trace-It's create is idempotent anyway.
            // So proceed rather than fail the caller's publish.
            trigger_error('trace-it: could not acquire mint lock; proceeding unlocked', E_USER_NOTICE);
            return $work();
        }

        try {
            flock($handle, LOCK_EX);
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Raw PNG bytes cached alongside the record, so rendering needs no network. */
    public function getPng(string $postId): ?string
    {
        $file = $this->path($postId, 'png');
        if (!is_readable($file)) {
            return null;
        }
        $bytes = file_get_contents($file);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    public function putPng(string $postId, string $bytes): void
    {
        $this->writeAtomic($this->path($postId, 'png'), $bytes);
    }

    /** Write-then-rename, so a concurrent reader never sees a partial file. */
    private function writeAtomic(string $target, string $bytes): void
    {
        $tmp = $target . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            throw new Misconfigured(sprintf('Could not write to "%s".', $target));
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new Misconfigured(sprintf('Could not move the temp file into "%s".', $target));
        }
    }
}
