<?php
/**
 * MockTransport — the in-memory transport every test uses.
 *
 * Records every message send() would deliver so tests can assert on
 * subject, recipient, and rendered body. Never opens a socket. A
 * failure mode can be primed with `failNextWith()` to exercise the
 * queue's retry and error-redaction paths.
 */

declare(strict_types=1);

namespace App\Mailer;

final class MockTransport implements MailerTransport
{
    /** @var array<int, array<string,mixed>> */
    public array $sent = [];
    private ?string $nextError = null;
    private int $failuresQueued = 0;

    public function failNextWith(string $message, int $count = 1): void
    {
        $this->nextError = $message;
        $this->failuresQueued = max(1, $count);
    }

    public function send(array $message): void
    {
        if ($this->failuresQueued > 0 && $this->nextError !== null) {
            $this->failuresQueued--;
            $err = $this->nextError;
            if ($this->failuresQueued === 0) { $this->nextError = null; }
            throw new MailerException($err);
        }
        $this->sent[] = $message;
    }
}
