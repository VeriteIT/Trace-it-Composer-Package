<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/** The request never got an answer — DNS, TLS, timeout, connection refused. */
final class TransportError extends TraceItException
{
}
