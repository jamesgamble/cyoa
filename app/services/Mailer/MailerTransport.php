<?php
/**
 * MailerTransport — the interface every SMTP-shaped backend implements.
 *
 * A transport receives one already-rendered message and either sends
 * it or throws a MailerException whose short, human-safe message the
 * queue is allowed to persist. Raw provider replies must NOT leak
 * through this interface; a transport is responsible for redacting
 * anything that could echo recipient details or credentials.
 */

declare(strict_types=1);

namespace App\Mailer;

interface MailerTransport
{
    /**
     * @param array<string,mixed> $message
     *   Keys: from_email, from_name, reply_to, to_email, to_name,
     *   subject, text, html.
     */
    public function send(array $message): void;
}

class MailerException extends \RuntimeException {}
