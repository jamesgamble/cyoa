<?php
/**
 * MailerException — thrown by any MailerTransport for a delivery
 * failure. The message MUST be an allow-listed short token so the
 * queue can persist it as `last_error` without risking a raw
 * provider reply reaching an operator's browser (see
 * EmailQueueService::redact()).
 */

declare(strict_types=1);

namespace App\Mailer;

class MailerException extends \RuntimeException {}
