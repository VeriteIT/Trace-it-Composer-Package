<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/**
 * Base for everything this package throws, so a caller can catch one type.
 *
 * The subclasses split along the line that decides how a CMS should react:
 *
 *   InvalidPostId, Misconfigured  a bug or a bad setting. Fix it; retrying will
 *                                 not help.
 *   ApiError, TransportError      transient or upstream. A publish routine should
 *                                 log and carry on — a QR code is not worth
 *                                 failing an editor's publish action over. The
 *                                 code gets created on the next publish, or on
 *                                 first render.
 */
abstract class TraceItException extends \RuntimeException
{
}
