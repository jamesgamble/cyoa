<?php
/**
 * EmailQueueService — the worker's core.
 *
 * Given a MailerTransport, a rendered-message template store, and the
 * decrypted SMTP settings, this service:
 *
 *   1. Recovers stale in-flight messages back to 'pending' so a
 *      crashed worker never strands a row.
 *   2. Claims a bounded batch of ready messages.
 *   3. Renders each template, calls the transport, records the
 *      outcome, and either advances the retry backoff or marks the
 *      row failed once retry_limit is reached.
 *
 * Every error message written to the queue is one of a small,
 * pre-approved set of tokens (see MailerTransport). Raw provider
 * replies are never persisted.
 */

declare(strict_types=1);

namespace App;

use App\Mailer\MailerException;
use App\Mailer\MailerTransport;

final class EmailQueueService
{
    private EmailQueueRepository $queue;
    private EmailTemplateRepository $templates;
    private MailerTransport $transport;
    /** @var array<string,mixed> Decrypted SMTP settings. */
    private array $smtp;

    /** @param array<string,mixed> $smtp */
    public function __construct(
        EmailQueueRepository $queue,
        EmailTemplateRepository $templates,
        MailerTransport $transport,
        array $smtp
    ) {
        $this->queue     = $queue;
        $this->templates = $templates;
        $this->transport = $transport;
        $this->smtp      = $smtp;
    }

    /**
     * Run one worker pass. Returns a small report suitable for the
     * CLI script's summary line.
     *
     * @return array{claimed:int, sent:int, retried:int, failed:int, recovered:int}
     */
    public function runOnce(): array
    {
        if (!($this->smtp['enabled'] ?? false)) {
            return ['claimed'=>0, 'sent'=>0, 'retried'=>0, 'failed'=>0, 'recovered'=>0];
        }

        $recovered = $this->queue->recoverStale(300);
        $batchSize = max(1, (int) ($this->smtp['batch_size'] ?? 25));
        $retryLimit = max(0, (int) ($this->smtp['retry_limit'] ?? 5));

        $rows = $this->queue->claimBatch($batchSize);
        $sent = 0; $retried = 0; $failed = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $attempts = (int) $row['attempts'];
            $data = [];
            $decoded = json_decode((string) $row['data_json'], true);
            if (is_array($decoded)) { $data = $decoded; }

            [$subject, $text, $html] = $this->templates->render((string) $row['template_key'], $data);
            $message = [
                'from_email' => (string) $this->smtp['from_email'],
                'from_name'  => (string) $this->smtp['from_name'],
                'reply_to'   => (string) ($this->smtp['reply_to'] ?? ''),
                'to_email'   => (string) $row['to_email'],
                'to_name'    => (string) $row['to_name'],
                'subject'    => $subject,
                'text'       => $text,
                'html'       => $html,
            ];

            try {
                $this->transport->send($message);
                $this->queue->markSent($id);
                $sent++;
            } catch (MailerException $e) {
                $result = $this->queue->markFailedOrRetry(
                    $id, $attempts, $retryLimit, self::redact($e->getMessage())
                );
                if ($result === 'failed') { $failed++; } else { $retried++; }
            } catch (\Throwable $e) {
                $result = $this->queue->markFailedOrRetry(
                    $id, $attempts, $retryLimit, 'transport_error'
                );
                if ($result === 'failed') { $failed++; } else { $retried++; }
            }
        }

        return [
            'claimed'   => count($rows),
            'sent'      => $sent,
            'retried'   => $retried,
            'failed'    => $failed,
            'recovered' => $recovered,
        ];
    }

    /**
     * Redact an error message to a short, allow-listed token. Anything
     * that does not match the transport's known shape is normalised
     * to "transport_error" so recipient addresses and credentials can
     * never end up in the queue's persisted last_error column.
     */
    public static function redact(string $error): string
    {
        $error = trim($error);
        if ($error === '') { return 'transport_error'; }
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $error) === 1) {
            return $error;
        }
        return 'transport_error';
    }
}
