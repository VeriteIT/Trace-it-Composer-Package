<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr\Cache;

/**
 * Where the postId -> code mapping lives on YOUR side.
 *
 * Two behaviours matter more than the storage medium, and both are about money:
 *
 *   1. A create charges monthly quota, so a cache hit must not call Trace-It.
 *   2. When an article goes live and 500 readers arrive in the same second, that
 *      must be ONE create, not 500. Implementations do this in lock(), which is
 *      why the interface has a locking method at all rather than plain get/set.
 *
 * FilesystemStore is the default and needs nothing. Swap in Redis or your CMS's
 * own cache by implementing this — nothing else in the package changes.
 */
interface Store
{
    /**
     * @return array<string,mixed>|null The stored record, or null on a miss.
     */
    public function get(string $postId): ?array;

    /** @param array<string,mixed> $record */
    public function put(string $postId, array $record): void;

    public function forget(string $postId): void;

    /** @return list<array<string,mixed>> Every record held, newest first. */
    public function all(): array;

    /**
     * Runs $work under an exclusive lock for this postId, so concurrent callers
     * do not each mint their own code.
     *
     * The implementation MUST re-check the cache inside the lock: by the time a
     * waiter acquires it, the holder has usually done the work, and creating
     * again would charge quota twice for one article.
     *
     * @template T
     * @param  callable():T $work
     * @return T
     */
    public function lock(string $postId, callable $work): mixed;
}
