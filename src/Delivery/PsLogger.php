<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Delivery;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 over PrestaShop's own log (Advanced Parameters → Logs), where a shop
 * owner looks. Context is appended as JSON; nothing secret is ever passed in.
 */
final class PsLogger extends AbstractLogger
{
    private const SEVERITY = [
        'emergency' => 4, 'alert' => 4, 'critical' => 4, 'error' => 3,
        'warning' => 2, 'notice' => 1, 'info' => 1, 'debug' => 1,
    ];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // The shop's log is for the owner: warnings and failures only, never
        // the library's per-request debug lines.
        if (\in_array((string) $level, ['debug', 'info', 'notice'], true)) {
            return;
        }
        $text = 'Trident: ' . $message . ($context !== [] ? ' ' . json_encode($context, \JSON_UNESCAPED_SLASHES) : '');
        try {
            \PrestaShopLogger::addLog(mb_substr($text, 0, 1000), self::SEVERITY[(string) $level] ?? 2, null, 'TridentCache');
        } catch (\Throwable) {
            error_log($text);
        }
    }
}
