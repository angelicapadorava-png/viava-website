<?php
/**
 * A minimal local SMTP sink for tests. Listens on 127.0.0.1 only and writes each
 * message it receives to a folder, so nothing can reach a real recipient.
 *
 *   php tests/fake-smtp.php PORT OUTPUT_DIR
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $port, $dir] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:' . (int) $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Could not listen: {$errstr}\n");
    exit(2);
}

$count = 0;
while ($conn = @stream_socket_accept($server, 3600)) {
    fwrite($conn, "220 fake-smtp\r\n");
    $recipients = [];
    while (($line = fgets($conn)) !== false) {
        $command = strtoupper(substr(trim($line), 0, 4));
        if ($command === 'RCPT') {
            $recipients[] = trim($line);
            fwrite($conn, "250 OK\r\n");
        } elseif ($command === 'DATA') {
            fwrite($conn, "354 Go ahead\r\n");
            $message = '';
            while (($data = fgets($conn)) !== false && rtrim($data, "\r\n") !== '.') {
                $message .= $data;
            }
            $count++;
            file_put_contents(sprintf('%s/%04d.eml', $dir, $count), implode("\n", $recipients) . "\n\n" . $message);
            fwrite($conn, "250 Queued\r\n");
        } elseif ($command === 'QUIT') {
            fwrite($conn, "221 Bye\r\n");
            break;
        } else {
            fwrite($conn, "250 OK\r\n");
        }
    }
    fclose($conn);
}
