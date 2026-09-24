<?php
/**
 * tests/e2e/smtp-sink.php — a tiny, local-only SMTP sink used by the
 * release end-to-end check. Accepts plain SMTP on 127.0.0.1, never
 * relays anything, and writes every received message to a directory
 * as N.eml so the check can read verification and invitation links.
 *
 * Usage: php tests/e2e/smtp-sink.php <port> <out-dir>
 */
declare(strict_types=1);

$port = (int) ($argv[1] ?? 2526);
$dir  = (string) ($argv[2] ?? sys_get_temp_dir() . '/bp-smtp-sink');
if (!is_dir($dir)) mkdir($dir, 0775, true);

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) { fwrite(STDERR, "sink: $errstr\n"); exit(1); }
$n = 0;
while ($conn = @stream_socket_accept($server, -1)) {
    stream_set_timeout($conn, 10);
    fwrite($conn, "220 bp-sink ESMTP\r\n");
    $data = ''; $inData = false;
    while (($line = fgets($conn)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                file_put_contents($dir . '/' . (++$n) . '.eml', $data);
                $data = '';
                fwrite($conn, "250 queued\r\n");
                continue;
            }
            $data .= $line;
            continue;
        }
        $cmd = strtoupper(substr(trim($line), 0, 4));
        switch ($cmd) {
            case 'EHLO': fwrite($conn, "250-bp-sink\r\n250 AUTH LOGIN PLAIN\r\n"); break;
            case 'HELO': fwrite($conn, "250 bp-sink\r\n"); break;
            case 'DATA': $inData = true; fwrite($conn, "354 go ahead\r\n"); break;
            case 'QUIT': fwrite($conn, "221 bye\r\n"); break 2;
            case 'AUTH': fwrite($conn, "235 ok\r\n"); break;
            default:     fwrite($conn, "250 ok\r\n");
        }
    }
    fclose($conn);
}
