<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Logging;

use Psr\Log\AbstractLogger;

/**
 * Writes PSR-3 records to the WordPress debug log.
 *
 * Extends {@see AbstractLogger} so the nine level methods arrive from the one
 * `log()` implementation below, rather than being spelled out as nine
 * one-line delegations.
 *
 * Records below the configured level are dropped before formatting, so a
 * `debug()` call in a hot path costs a comparison when debugging is off.
 *
 * @extends AbstractLogger
 */
final class WpLogger extends AbstractLogger
{
    /**
     * The levels this logger writes, most severe first.
     *
     * @var list<string>
     */
    private const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /**
     * The least severe level that is written.
     *
     * @var string
     */
    private readonly string $threshold;

    /**
     * @param string $level The least severe level to write; anything quieter is
     *                      dropped. An unrecognised name falls back to `error`
     *                      rather than to `debug`, because a typo in a level
     *                      name should not start logging everything.
     */
    public function __construct(string $level = 'error')
    {
        $this->threshold = in_array($level, self::LEVELS, true) ? $level : 'error';
    }

    /**
     * {@inheritDoc}
     *
     * The message is interpolated with its context, which is what PSR-3 means by
     * a message template. `error_log()` is used rather than `WP_DEBUG_LOG`
     * handling of our own: WordPress routes it to `debug.log` when
     * `WP_DEBUG_LOG` is on, and to the server log otherwise, which is the
     * behaviour a plugin should not try to guess at.
     *
     * @param mixed              $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (! is_string($level) || ! $this->shouldWrite($level)) {
            return;
        }

        error_log(sprintf(
            '[wp-mini-laravel] %s: %s',
            strtoupper($level),
            $this->interpolate((string) $message, $context)
        ));
    }

    /**
     * Whether a level is severe enough to write.
     *
     * @param string $level
     *
     * @return bool
     */
    private function shouldWrite(string $level): bool
    {
        $asked = array_search($level, self::LEVELS, true);
        $threshold = array_search($this->threshold, self::LEVELS, true);

        // An unknown level is written rather than dropped: PSR-3 lets a caller
        // invent one, and silently discarding it would hide the record a caller
        // considered worth logging.
        if ($asked === false || $threshold === false) {
            return true;
        }

        // LEVELS runs most severe first, so a lower index is more severe.
        return $asked <= $threshold;
    }

    /**
     * Replaces `{placeholders}` with their context values.
     *
     * A context key with no placeholder is left alone; it is not appended,
     * because the record's message is the caller's to shape.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     *
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];

        foreach ($context as $key => $value) {
            if (! is_scalar($value) && ! $value instanceof \Stringable) {
                continue;
            }

            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr($message, $replace);
    }
}
