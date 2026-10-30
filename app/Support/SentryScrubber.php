<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry's before_send (config/sentry.php): guests' and hosts' phone numbers
 * and email addresses never leave the server (plan section 20). Masks them in
 * the message, exception values, breadcrumbs, extra, contexts, tags and the
 * request, and drops values under sensitive keys (phone, email, password,
 * token…). send_default_pii is off as well, so no IP or cookies are sent.
 */
class SentryScrubber
{
    public const MASK = '[Filtered]';

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';

    /** 9 to 15 digits (Western or Arabic-Indic), optional +/00 and single spaces or dashes between digits. */
    private const PHONE = '/(?<![\w.:\/\-])(?:\+|00)?[0-9٠-٩۰-۹](?:[\s\-]?[0-9٠-٩۰-۹]){8,14}(?![\w.:\/\-])/u';

    private const SENSITIVE_KEYS = '/phone|mobile|whatsapp|e-?mail|password|passwd|secret|token|authorization|cookie|iban|account_number|display_name/i';

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        if (($message = $event->getMessage()) !== null) {
            $event->setMessage(static::text($message), array_map(static::text(...), array_map('strval', $event->getMessageParams())),
                $event->getMessageFormatted() !== null ? static::text($event->getMessageFormatted()) : null);
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(static::text($exception->getValue()));
        }

        $event->setBreadcrumb(array_map(function ($crumb) {
            if ($crumb->getMessage() !== null) {
                $crumb = $crumb->withMessage(static::text($crumb->getMessage()));
            }
            foreach ($crumb->getMetadata() as $name => $value) {
                $crumb = $crumb->withMetadata($name, static::value($value, (string) $name));
            }

            return $crumb;
        }, $event->getBreadcrumbs()));

        $event->setExtra(static::value($event->getExtra()));
        $event->setTags(array_map(fn ($tag) => static::text((string) $tag), $event->getTags()));
        $event->setRequest(static::value($event->getRequest()));

        foreach ($event->getContexts() as $name => $context) {
            if (is_array($context)) {
                $event->setContext($name, static::value($context));
            }
        }

        if ($user = $event->getUser()) {
            $user->setEmail(null);
            $user->setIpAddress(null);
            $user->setUsername(null);
        }

        return $event;
    }

    public static function text(string $text): string
    {
        return (string) preg_replace([self::EMAIL, self::PHONE], self::MASK, $text);
    }

    /**
     * @template T
     *
     * @param  T  $value
     * @return T|string
     */
    public static function value(mixed $value, string $key = ''): mixed
    {
        if ($key !== '' && preg_match(self::SENSITIVE_KEYS, $key) && $value !== null && $value !== '' && ! is_bool($value)) {
            return self::MASK;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = static::value($v, is_string($k) ? $k : '');
            }

            return $out;
        }

        return is_string($value) ? static::text($value) : $value;
    }
}
