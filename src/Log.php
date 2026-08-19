<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/**
 * Where this package's diagnostics go.
 * ===========================================================================
 * Everything here is a NON-FATAL degradation: something was dropped, skipped or
 * retried, the caller got a usable result, and nobody was going to see an
 * exception. That is the right behaviour — a QR code is never worth failing an
 * editor's publish or breaking an article page over — but it means these
 * messages are the ONLY evidence that a feature quietly stopped working.
 *
 * The default sink is trigger_error, which is the correct dependency-free
 * choice: it respects error_reporting, and log_errors routes it to whatever the
 * host already collects. But on a typical production php.ini display_errors is
 * Off, so nothing reaches a human unless somebody reads the PHP error log —
 * which, on a busy news site, nobody does.
 *
 * So pass `logger` and these become yours:
 *
 *     new TraceIt(['logger' => [$psr3Logger, 'log']]);
 *
 * The signature is deliberately PSR-3's: (string $level, string $message), with
 * $level one of 'warning' or 'notice'. A PSR-3 LoggerInterface::log() can
 * therefore be handed over directly, with no adapter to write. A closure works
 * just as well:
 *
 *     'logger' => fn (string $level, string $message) => Sentry::captureMessage($message)
 *
 * Messages are prefixed 'trace-it: ' before they are handed over, so they stay
 * greppable in a shared log.
 */
final class Log
{
    /** @var (callable(string, string): void)|null */
    private $sink;

    /**
     * @param (callable(string, string): void)|null $sink Receives ($level, $message).
     *        Null uses trigger_error.
     */
    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink;
    }

    /**
     * Something a person should act on: a feature is silently degraded and will
     * stay that way until the configuration changes.
     */
    public function warning(string $message): void
    {
        $this->write('warning', $message);
    }

    /**
     * Informational. The package handled it and the outcome is still correct.
     */
    public function notice(string $message): void
    {
        $this->write('notice', $message);
    }

    private function write(string $level, string $message): void
    {
        $message = 'trace-it: ' . $message;

        if ($this->sink !== null) {
            /*
             * A logger that throws must not take down the publish it was only
             * reporting on. That would invert the whole point of routing these
             * through here rather than throwing in the first place.
             */
            try {
                ($this->sink)($level, $message);
            } catch (\Throwable $e) {
                trigger_error(
                    'trace-it: the configured logger threw (' . $e->getMessage()
                    . '), original message: ' . $message,
                    E_USER_WARNING
                );
            }

            return;
        }

        trigger_error($message, $level === 'warning' ? E_USER_WARNING : E_USER_NOTICE);
    }
}
