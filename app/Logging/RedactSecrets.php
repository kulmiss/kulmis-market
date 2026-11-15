<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\LogRecord;

/**
 * Removes secrets from every log line before it's written: API keys,
 * bot tokens, bearer tokens, passwords, OTP/reset codes and card-like
 * numbers. Applied to all channels (config/logging.php "tap").
 */
class RedactSecrets
{
    /** @var array<string, string> pattern => replacement */
    public const PATTERNS = [
        '/\b\d{8,10}:[A-Za-z0-9_-]{30,}\b/' => '[telegram-token]',                       // Telegram bot token
        '/(Bearer\s+)[A-Za-z0-9|._~+\/=-]{10,}/i' => '$1***',
        '/("?(?:password|password_confirmation|current_password|secret|token|api_key|webhook_secret|two_factor_secret|code|recovery_code|account_number)"?\s*[:=]\s*)("[^"]*"|[^\s,}&]+)/i' => '$1"***"',
        '/\b(?:\d[ -]?){13,19}\b/' => '[number]',                                        // card / long account numbers
        '/base64:[A-Za-z0-9+\/=]{40,}/' => 'base64:***',                                // APP_KEY / BACKUP_KEY
    ];

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if (! $handler instanceof ProcessableHandlerInterface) {
                continue;
            }
            $handler->pushProcessor(fn (LogRecord $r) => $r->with(
                message: self::clean($r->message),
                context: self::cleanArray($r->context),
            ));
        }
    }

    public static function clean(string $s): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $s);
    }

    /** @param array<mixed> $a @return array<mixed> */
    private static function cleanArray(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_string($k) && preg_match('/pass|secret|token|key|code|account_number/i', $k)) {
                $a[$k] = '***';
            } elseif (is_string($v)) {
                $a[$k] = self::clean($v);
            } elseif (is_array($v)) {
                $a[$k] = self::cleanArray($v);
            }
        }

        return $a;
    }
}
