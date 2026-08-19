<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/**
 * Missing or unusable configuration — no API key, a placeholder base URL, an
 * unwritable cache directory, an image host that is not allowlisted.
 */
final class Misconfigured extends TraceItException
{
}
