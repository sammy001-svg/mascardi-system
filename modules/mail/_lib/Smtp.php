<?php

namespace Mascardi\Mail;

use RuntimeException;

/**
 * Sending one already-built message, as one particular person.
 *
 * The system's own mailer in includes/mailer.php sends as the company: one
 * host, one login, one from-address, for invoices and alerts. This is the
 * other thing entirely — a member of staff sending their own mail, signed in
 * to the mail server with their own mailbox password, so it leaves from their
 * address with their server's own signing on it rather than being relayed
 * through the notifications account.
 *
 * Only the raw path is here. The message is built by Mime::build() and handed
 * over whole, because a mail client has to be able to send a reply with the
 * right threading headers and the right attachments, and a helper that takes
 * a subject and some HTML cannot do that.
 */
final class Smtp
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;

    /** Kept for the diagnostics screen, and never contains a password. */
    private array $transcript = [];

    public function __construct(
        private string $host,
        private int    $port,
        private string $encryption,
        private string $username,
        private string $password,
        private string $fromEmail,
        private int    $timeout = 20,
    ) {
    }

    public function transcript(): array
    {
        return $this->transcript;
    }

    /**
     * @param  string[] $recipients envelope recipients — Bcc belongs here and
     *                              nowhere else, which is what makes it blind
     * @return array{ok:bool, error?:string, refused?:string[]}
     */
    public function sendRaw(string $envelopeFrom, array $recipients, string $raw): array
    {
        if (trim($this->host) === '') {
            return ['ok' => false, 'error' => 'No outgoing mail server is set up.'];
        }

        $recipients = array_values(array_unique(array_filter(
            $recipients,
            static fn ($r) => filter_var($r, FILTER_VALIDATE_EMAIL)
        )));

        if (!$recipients) {
            return ['ok' => false, 'error' => 'There is nobody valid to send this to.'];
        }

        $this->transcript = [];
        $refused = [];

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $envelopeFrom . '>', [250]);

            // One refused address should not stop the others going.
            foreach ($recipients as $r) {
                try {
                    $this->command('RCPT TO:<' . $r . '>', [250, 251]);
                } catch (\Throwable) {
                    $refused[] = $r;
                }
            }

            if (count($refused) === count($recipients)) {
                throw new RuntimeException('The mail server refused every recipient: '
                                         . implode(', ', $refused));
            }

            $this->command('DATA', [354]);
            // A line of its own beginning with a dot would end the message
            // early, so those are doubled — the rule is as old as SMTP.
            $this->write(preg_replace('/^\./m', '..', $raw) . self::CRLF . '.' . self::CRLF);
            $this->expect([250]);

            $this->command('QUIT', [221, 250]);
            $this->disconnect();

            return ['ok' => true, 'refused' => $refused];
        } catch (\Throwable $e) {
            $this->disconnect();
            error_log('mail send: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Connect and sign in without sending, to prove the settings work. */
    public function test(): array
    {
        $this->transcript = [];

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();
            $this->command('QUIT', [221, 250]);
            $this->disconnect();

            return ['ok' => true, 'transcript' => $this->transcript];
        } catch (\Throwable $e) {
            $this->disconnect();

            return ['ok' => false, 'error' => $e->getMessage(), 'transcript' => $this->transcript];
        }
    }

    // ── The conversation ─────────────────────────────────────────────────────

    private function connect(): void
    {
        $scheme  = $this->encryption === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]]);

        $errno = 0; $errstr = '';
        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context
        );

        if ($socket === false) {
            throw new RuntimeException(
                "Could not connect to {$this->host}:{$this->port} — " . ($errstr ?: 'connection refused')
                . '. Check the host and port, and that the hosting account allows outbound mail.'
            );
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);
        $this->expect([220]);
    }

    private function handshake(): void
    {
        $domain = str_contains($this->fromEmail, '@')
            ? explode('@', $this->fromEmail)[1]
            : 'localhost';

        $this->command('EHLO ' . $domain, [250]);

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', [220]);

            $ok = @stream_socket_enable_crypto(
                $this->socket, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );

            if (!$ok) {
                throw new RuntimeException(
                    'STARTTLS failed — could not negotiate an encrypted connection. '
                    . 'Try port 465 with SSL instead.'
                );
            }

            // The server must be greeted again over the encrypted channel.
            $this->command('EHLO ' . $domain, [250]);
        }
    }

    private function authenticate(): void
    {
        if ($this->username === '') return;

        // AUTH LOGIN is the most widely supported; PLAIN is the fallback.
        try {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334]);
            $this->command(base64_encode($this->password), [235]);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '535') || str_contains($e->getMessage(), '534')) {
                throw new AuthFailed(
                    'The mail server rejected those credentials. For a cPanel mailbox the '
                    . 'username is the full email address.'
                );
            }

            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235]);
        }
    }

    private function command(string $command, array $expected): string
    {
        // Never write a password into the transcript.
        $this->transcript[] = '> ' . (
            preg_match('/^(AUTH|[A-Za-z0-9+\/=]{16,}$)/', $command) ? '[credentials hidden]' : $command
        );

        $this->write($command . self::CRLF);

        return $this->expect($expected);
    }

    private function write(string $data): void
    {
        if ($this->socket === null || fwrite($this->socket, $data) === false) {
            throw new RuntimeException('Lost connection to the mail server while sending.');
        }
    }

    private function expect(array $codes): string
    {
        $response = '';

        while ($this->socket !== null && ($line = fgets($this->socket, 512)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-"; the final line uses "250 ".
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }

        $meta = $this->socket !== null ? stream_get_meta_data($this->socket) : ['timed_out' => true];
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('The mail server did not respond in time.');
        }

        $this->transcript[] = '< ' . trim($response);
        $code = (int) substr(trim($response), 0, 3);

        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('Mail server replied: ' . trim($response));
        }

        return $response;
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
