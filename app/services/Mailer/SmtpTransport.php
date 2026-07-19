<?php
/**
 * SmtpTransport — a compact, bundled SMTP client.
 *
 * Handles the SMTP command dialogue the Branching Paths queue needs:
 * EHLO, optional STARTTLS, AUTH LOGIN, MAIL FROM, RCPT TO, DATA. TLS
 * mode ('tls') opens the connection with `tls://`; STARTTLS upgrades
 * a plaintext socket in place; 'none' is only useful for local relays.
 *
 * The class is intentionally small — it is not a general-purpose SMTP
 * library. It writes RFC 5322 headers, encodes multi-line bodies with
 * dot-stuffing, and supports UTF-8 subjects via encoded-word. Every
 * error is wrapped in a MailerException whose message is safe to
 * persist (no raw server reply, no credentials).
 *
 * Tests never construct this class; they use MockTransport.
 */

declare(strict_types=1);

namespace App\Mailer;

final class SmtpTransport implements MailerTransport
{
    /** @var array<string,mixed> */
    private array $config;
    /** @var resource|null */
    private $socket = null;

    /** @param array<string,mixed> $config decrypted smtp_settings row */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $message): void
    {
        $host = (string) ($this->config['host'] ?? '');
        $port = (int)    ($this->config['port'] ?? 587);
        $enc  = (string) ($this->config['encryption'] ?? 'starttls');
        $user = (string) ($this->config['username'] ?? '');
        $pass = (string) ($this->config['password'] ?? '');

        if ($host === '' || $port < 1) {
            throw new MailerException('smtp_not_configured');
        }

        $dsn = ($enc === 'tls' ? 'tls://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
        ]]);
        $errno = 0; $errstr = '';
        $this->socket = @stream_socket_client(
            $dsn, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx
        );
        if ($this->socket === false) {
            throw new MailerException('smtp_connect_failed');
        }
        stream_set_timeout($this->socket, 30);

        try {
            $this->expect(220);
            $this->cmd('EHLO ' . self::localHostname(), 250);

            if ($enc === 'starttls') {
                $this->cmd('STARTTLS', 220);
                $ok = @stream_socket_enable_crypto(
                    $this->socket, true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
                if ($ok !== true) {
                    throw new MailerException('smtp_starttls_failed');
                }
                $this->cmd('EHLO ' . self::localHostname(), 250);
            }

            if ($user !== '') {
                $this->cmd('AUTH LOGIN', 334);
                $this->cmd(base64_encode($user), 334);
                $this->cmd(base64_encode($pass), 235);
            }

            $from = (string) ($message['from_email'] ?? $this->config['from_email'] ?? '');
            $to   = (string) ($message['to_email'] ?? '');
            if ($from === '' || $to === '') {
                throw new MailerException('smtp_missing_envelope');
            }
            $this->cmd('MAIL FROM:<' . $from . '>', 250);
            $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd('DATA', 354);
            $this->write($this->buildRfc5322($message));
            $this->write("\r\n.\r\n");
            $this->readResponse(250);
            $this->cmd('QUIT', [221, 250]);
        } catch (MailerException $e) {
            $this->closeSocket();
            throw $e;
        } catch (\Throwable $e) {
            $this->closeSocket();
            throw new MailerException('smtp_protocol_error');
        }
        $this->closeSocket();
    }

    /** @param array<string,mixed> $m */
    private function buildRfc5322(array $m): string
    {
        $fromEmail = (string) ($m['from_email'] ?? '');
        $fromName  = (string) ($m['from_name']  ?? '');
        $replyTo   = (string) ($m['reply_to']   ?? '');
        $to        = (string) ($m['to_email']   ?? '');
        $toName    = (string) ($m['to_name']    ?? '');
        $subject   = (string) ($m['subject']    ?? '(no subject)');
        $text      = (string) ($m['text']       ?? '');
        $html      = (string) ($m['html']       ?? '');
        $boundary  = 'bp_' . bin2hex(random_bytes(8));
        $date      = gmdate('D, d M Y H:i:s') . ' +0000';
        $messageId = '<' . bin2hex(random_bytes(12)) . '@branching-paths>';

        $headers = [];
        $headers[] = 'From: ' . self::fmtAddress($fromEmail, $fromName);
        $headers[] = 'To: '   . self::fmtAddress($to, $toName);
        if ($replyTo !== '') { $headers[] = 'Reply-To: ' . self::fmtAddress($replyTo, ''); }
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'Date: ' . $date;
        $headers[] = 'Message-ID: ' . $messageId;
        $headers[] = 'MIME-Version: 1.0';

        if ($html !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body  = "--$boundary\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= self::dotStuff($text) . "\r\n";
            $body .= "--$boundary\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= self::dotStuff($html) . "\r\n";
            $body .= "--$boundary--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = self::dotStuff($text);
        }
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function fmtAddress(string $email, string $name): string
    {
        if ($name === '') { return '<' . $email . '>'; }
        // Escape quotes / backslashes in the display name.
        $safe = addcslashes($name, "\"\\");
        return '"' . $safe . '" <' . $email . '>';
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7e]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function dotStuff(string $body): string
    {
        // Normalise line endings and prefix any bare-dot line with an
        // extra dot so it does not terminate the DATA payload.
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            if (strncmp($line, '.', 1) === 0) { $line = '.' . $line; }
        }
        return implode("\r\n", $lines);
    }

    /** @param int|int[] $expected */
    private function cmd(string $line, $expected): array
    {
        $this->write($line . "\r\n");
        return $this->readResponse($expected);
    }

    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new MailerException('smtp_socket_closed');
        }
        $sent = @fwrite($this->socket, $data);
        if ($sent === false || $sent < strlen($data)) {
            throw new MailerException('smtp_write_failed');
        }
    }

    /** @param int|int[] $expected */
    private function readResponse($expected): array
    {
        $lines = [];
        while (true) {
            if ($this->socket === null) {
                throw new MailerException('smtp_socket_closed');
            }
            $line = @fgets($this->socket, 8192);
            if ($line === false) { throw new MailerException('smtp_read_failed'); }
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) < 4) { throw new MailerException('smtp_protocol_error'); }
            if ($line[3] === ' ') { break; }
        }
        $codeStr = substr($lines[0], 0, 3);
        $code = (int) $codeStr;
        $expected = is_array($expected) ? $expected : [$expected];
        if (!in_array($code, $expected, true)) {
            // Deliberately do NOT include the server reply in the
            // exception message — replies may echo credentials or
            // recipient addresses. We surface only the numeric code.
            throw new MailerException('smtp_' . $code);
        }
        return $lines;
    }

    private function expect(int $code): void { $this->readResponse($code); }

    private function closeSocket(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private static function localHostname(): string
    {
        $host = gethostname();
        return is_string($host) && $host !== '' ? $host : 'localhost';
    }
}
