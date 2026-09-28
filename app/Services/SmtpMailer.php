<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Minimal, dependency-free SMTP client (SSL on 465, STARTTLS on 587, or plain).
 * Supports HTML + text bodies and attachments (used for .ics calendar invites).
 */
final class SmtpMailer
{
    private $sock = null;
    private array $log = [];

    public function __construct(private array $cfg) {}

    /**
     * @param array $msg to, to_name, subject, html, text, attachments[] = [name, mime, content]
     * @throws \RuntimeException on failure with a readable message
     */
    public function send(array $msg): void
    {
        $host = (string)($this->cfg['host'] ?? '');
        $port = (int)($this->cfg['port'] ?? 587);
        $enc  = strtolower((string)($this->cfg['encryption'] ?? 'tls'));
        if ($host === '') throw new \RuntimeException('SMTP host is not set.');

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $this->sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) throw new \RuntimeException("Could not connect to $host:$port ($errstr).");
        stream_set_timeout($this->sock, 20);

        try {
            $this->expect(220);
            $ehloHost = parse_url((string)config('app.url'), PHP_URL_HOST) ?: 'localhost';
            $this->cmd("EHLO $ehloHost", 250);
            if ($enc === 'tls') {
                $this->cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed.');
                }
                $this->cmd("EHLO $ehloHost", 250);
            }
            $user = (string)($this->cfg['username'] ?? '');
            if ($user !== '') {
                $this->cmd('AUTH LOGIN', 334);
                $this->cmd(base64_encode($user), 334);
                $this->cmd(base64_encode((string)($this->cfg['password'] ?? '')), 235, 'Login rejected. Check the username and password (Gmail and Outlook need an app password).');
            }
            $from = (string)($this->cfg['from_email'] ?: $user);
            $this->cmd('MAIL FROM:<' . $this->clean($from) . '>', 250);
            $this->cmd('RCPT TO:<' . $this->clean((string)$msg['to']) . '>', [250, 251]);
            $this->cmd('DATA', 354);
            $data = $this->build($msg, $from);
            // Dot-stuffing
            $data = preg_replace('/^\./m', '..', $data);
            fwrite($this->sock, $data . "\r\n.\r\n");
            $this->expect(250);
            $this->cmd('QUIT', 221);
        } finally {
            if ($this->sock) fclose($this->sock);
            $this->sock = null;
        }
    }

    private function build(array $msg, string $from): string
    {
        $fromName = (string)($this->cfg['from_name'] ?? '');
        $boundaryAlt = 'alt_' . bin2hex(random_bytes(8));
        $boundaryMix = 'mix_' . bin2hex(random_bytes(8));
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $h = [];
        $h[] = 'Date: ' . date('r');
        $h[] = 'From: ' . $this->addr($from, $fromName);
        $h[] = 'To: ' . $this->addr((string)$msg['to'], (string)($msg['to_name'] ?? ''));
        if (!empty($msg['reply_to'])) $h[] = 'Reply-To: ' . $this->clean((string)$msg['reply_to']);
        $h[] = 'Subject: ' . $this->encodeHeader((string)$msg['subject']);
        $h[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $h[] = 'MIME-Version: 1.0';

        $text = (string)($msg['text'] ?? strip_tags((string)($msg['html'] ?? '')));
        $html = (string)($msg['html'] ?? nl2br(htmlspecialchars($text)));
        $alt = "--$boundaryAlt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($text))
             . "--$boundaryAlt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($html))
             . "--$boundaryAlt--";

        $atts = $msg['attachments'] ?? [];
        if (!$atts) {
            $h[] = "Content-Type: multipart/alternative; boundary=\"$boundaryAlt\"";
            return implode("\r\n", $h) . "\r\n\r\n" . $alt;
        }
        $h[] = "Content-Type: multipart/mixed; boundary=\"$boundaryMix\"";
        $body = "--$boundaryMix\r\nContent-Type: multipart/alternative; boundary=\"$boundaryAlt\"\r\n\r\n$alt\r\n";
        foreach ($atts as $a) {
            $name = preg_replace('/[^\w.\- ]/', '_', (string)$a['name']);
            $mime = (string)($a['mime'] ?? 'application/octet-stream');
            $body .= "--$boundaryMix\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                   . chunk_split(base64_encode((string)$a['content']));
        }
        $body .= "--$boundaryMix--";
        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }

    private function addr(string $email, string $name): string
    {
        $email = $this->clean($email);
        return $name !== '' ? $this->encodeHeader($name) . " <$email>" : $email;
    }

    private function encodeHeader(string $s): string
    {
        $s = str_replace(["\r", "\n"], '', $s);
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private function clean(string $s): string { return str_replace(["\r", "\n", '<', '>'], '', trim($s)); }

    private function cmd(string $line, int|array $expect, ?string $friendly = null): string
    {
        fwrite($this->sock, $line . "\r\n");
        return $this->expect($expect, $friendly);
    }

    private function expect(int|array $codes, ?string $friendly = null): string
    {
        $codes = (array)$codes;
        $resp = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException($friendly ?? ('Mail server said: ' . trim(substr($resp, 0, 200)) ?: 'no response'));
        }
        return $resp;
    }
}
