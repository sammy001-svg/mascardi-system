<?php

namespace Mascardi\Mail;

use RuntimeException;

/**
 * A small IMAP client over a plain socket.
 *
 * Written rather than built on PHP's imap extension, because that
 * extension is not reliably present: it is missing here, many hosts do
 * not enable it, and PHP 8.4 removed it from the core altogether. A
 * mailbox that stopped working after a routine PHP upgrade on the server
 * would be a poor thing to hand the office.
 *
 * It speaks only what the staff mailbox needs — log in, list folders,
 * select one, search, fetch headers and whole messages, set flags, move,
 * append, delete — against a cPanel (Dovecot) server. It is not a general
 * IMAP library and does not try to be.
 *
 * Every command is built from pieces: fixed keywords, numbers the code
 * produced, and strings passed through quote(), which returns either a
 * safely quoted string or a Literal. Nothing a user types reaches the
 * wire unescaped.
 */
final class ImapClient
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;

    private int $tagNo = 0;

    /** @var string[] upper-cased capability names */
    private array $caps = [];

    public function __construct(
        private string $host,
        private int $port = 993,
        private string $security = 'ssl',   // ssl | tls | none
        private int $timeout = 20,
    ) {
    }

    // -----------------------------------------------------------------
    // Session
    // -----------------------------------------------------------------

    public function connect(): void
    {
        $scheme = $this->security === 'ssl' ? 'ssl' : 'tcp';

        $context = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'SNI_enabled'      => true,
        ]]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $scheme . '://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new RuntimeException('Could not reach the mail server ' . $this->host . ':' . $this->port
                . ($errstr ? ' — ' . $errstr : '') . '.');
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $greeting = $this->readLine();

        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new RuntimeException('The mail server did not greet us properly: ' . trim($greeting));
        }

        if ($this->security === 'tls') {
            $this->command('STARTTLS');

            if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not switch the mail connection to encrypted.');
            }
        }
    }

    /**
     * @throws AuthFailed when the server refuses the address or password,
     *                    so the caller can say exactly that
     */
    public function login(string $user, string $password): void
    {
        $result = $this->command(['LOGIN ', $this->quote($user), ' ', $this->quote($password)], false);

        if ($result['status'] !== 'OK') {
            throw new AuthFailed('The mail server did not accept that email address and password.');
        }

        $this->caps = [];

        foreach ($this->command('CAPABILITY')['untagged'] as $u) {
            if (stripos($u['text'], 'CAPABILITY ') === 0) {
                $this->caps = array_map('strtoupper', preg_split('/\s+/', trim(substr($u['text'], 11))) ?: []);
            }
        }
    }

    public function has(string $capability): bool
    {
        return in_array(strtoupper($capability), $this->caps, true);
    }

    public function logout(): void
    {
        if ($this->socket) {
            try {
                $this->command('LOGOUT', false);
            } catch (\Throwable) {
                // Leaving anyway.
            }

            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
    }

    // -----------------------------------------------------------------
    // Folders
    // -----------------------------------------------------------------

    /**
     * Every folder in the account.
     *
     * @return list<array{name:string, label:string, delimiter:string, flags:string[], role:?string}>
     */
    public function folders(): array
    {
        $out = [];

        foreach ($this->command('LIST "" "*"')['untagged'] as $u) {
            if (stripos($u['text'], 'LIST ') !== 0) {
                continue;
            }

            $tokens = Tokenizer::parse(substr($u['text'], 5), $u['literals']);

            if (count($tokens) < 3) {
                continue;
            }

            [$flags, $delimiter, $name] = $tokens;
            $flags = array_map('strval', is_array($flags) ? $flags : []);

            if (in_array('\\Noselect', $flags, true) || in_array('\\NonExistent', $flags, true)) {
                continue;
            }

            $name = (string) $name;
            $delimiter = $delimiter === null ? '' : (string) $delimiter;

            $out[] = [
                'name'      => $name,
                'label'     => self::label($name, $delimiter, self::role($name, $flags)),
                'delimiter' => $delimiter,
                'flags'     => $flags,
                'role'      => self::role($name, $flags),
            ];
        }

        return $out;
    }

    /** @return array{messages:int, unseen:int} */
    public function status(string $folder): array
    {
        $r = $this->command(['STATUS ', $this->quote($folder), ' (MESSAGES UNSEEN)']);
        $out = ['messages' => 0, 'unseen' => 0];

        foreach ($r['untagged'] as $u) {
            if (preg_match('/MESSAGES (\d+)/i', $u['text'], $m)) {
                $out['messages'] = (int) $m[1];
            }
            if (preg_match('/UNSEEN (\d+)/i', $u['text'], $m)) {
                $out['unseen'] = (int) $m[1];
            }
        }

        return $out;
    }

    /** @return array{exists:int} */
    public function select(string $folder, bool $readOnly = false): array
    {
        $r = $this->command([$readOnly ? 'EXAMINE ' : 'SELECT ', $this->quote($folder)]);
        $exists = 0;

        foreach ($r['untagged'] as $u) {
            if (preg_match('/^(\d+) EXISTS/i', $u['text'], $m)) {
                $exists = (int) $m[1];
            }
        }

        return ['exists' => $exists];
    }

    public function create(string $folder): void
    {
        $this->command(['CREATE ', $this->quote($folder)], false);
    }

    // -----------------------------------------------------------------
    // Messages
    // -----------------------------------------------------------------

    /**
     * UIDs in the selected folder, newest first — all of them, or those
     * matching $text in sender, recipients or subject.
     *
     * @return int[]
     */
    public function search(string $text = '', bool $unseenOnly = false): array
    {
        $text = trim($text);
        $cmd = ['UID SEARCH '];

        if ($text !== '' && preg_match('/[^\x20-\x7e]/', $text)) {
            $cmd[] = 'CHARSET UTF-8 ';
        }

        if ($unseenOnly) {
            $cmd[] = 'UNSEEN ';
        }

        if ($text !== '') {
            array_push($cmd,
                'OR OR OR FROM ', $this->quote($text),
                ' TO ', $this->quote($text),
                ' SUBJECT ', $this->quote($text),
                ' CC ', $this->quote($text));
        } elseif (!$unseenOnly) {
            $cmd[] = 'ALL';
        }

        $uids = [];

        foreach ($this->command($cmd)['untagged'] as $u) {
            if (stripos($u['text'], 'SEARCH') === 0) {
                foreach (preg_split('/\s+/', trim(substr($u['text'], 6))) ?: [] as $n) {
                    if (ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }

        rsort($uids);

        return $uids;
    }

    /**
     * What a message list needs, for the given UIDs.
     *
     * @param int[] $uids
     * @return array<int, array{uid:int, flags:string[], size:int, date:string, headers:string}>
     */
    public function summaries(array $uids): array
    {
        if (!$uids) {
            return [];
        }

        $r = $this->command('UID FETCH ' . self::set($uids)
            . ' (UID FLAGS RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE CONTENT-TYPE)])');

        $out = [];

        foreach ($r['untagged'] as $u) {
            $item = $this->fetchItem($u);

            if ($item === null || !isset($item['UID'])) {
                continue;
            }

            $uid = (int) $item['UID'];
            $headers = '';

            foreach ($item as $k => $v) {
                if (str_starts_with((string) $k, 'BODY[')) {
                    $headers = (string) $v;
                }
            }

            $out[$uid] = [
                'uid'     => $uid,
                'flags'   => array_map('strval', (array) ($item['FLAGS'] ?? [])),
                'size'    => (int) ($item['RFC822.SIZE'] ?? 0),
                'date'    => (string) ($item['INTERNALDATE'] ?? ''),
                'headers' => $headers,
            ];
        }

        return $out;
    }

    /** The whole message as it arrived, without marking it read. */
    public function raw(int $uid): ?string
    {
        $r = $this->command('UID FETCH ' . $uid . ' (UID BODY.PEEK[])');

        foreach ($r['untagged'] as $u) {
            $item = $this->fetchItem($u);

            if ($item !== null && isset($item['BODY[]'])) {
                return (string) $item['BODY[]'];
            }
        }

        return null;
    }

    /** @param int[] $uids */
    public function flag(array $uids, string $flag, bool $on = true): void
    {
        if (!$uids || !preg_match('/^\\\\[A-Za-z]+$/', $flag)) {
            return;
        }

        $this->command('UID STORE ' . self::set($uids) . ' ' . ($on ? '+' : '-') . 'FLAGS.SILENT (' . $flag . ')');
    }

    /**
     * Move messages to another folder.
     *
     * MOVE where the server offers it; otherwise copy, mark deleted and
     * expunge — only those messages when UIDPLUS allows it, so nothing
     * else somebody marked deleted elsewhere is swept away with them.
     *
     * @param int[] $uids
     */
    public function move(array $uids, string $to): void
    {
        if (!$uids) {
            return;
        }

        $set = self::set($uids);

        if ($this->has('MOVE')) {
            $this->command(['UID MOVE ' . $set . ' ', $this->quote($to)]);
            return;
        }

        $this->command(['UID COPY ' . $set . ' ', $this->quote($to)]);
        $this->command('UID STORE ' . $set . ' +FLAGS.SILENT (\\Deleted)');
        $this->command($this->has('UIDPLUS') ? 'UID EXPUNGE ' . $set : 'EXPUNGE');
    }

    /** @param int[] $uids  Gone for good — only ever from Trash or Spam. */
    public function destroy(array $uids): void
    {
        if (!$uids) {
            return;
        }

        $set = self::set($uids);
        $this->command('UID STORE ' . $set . ' +FLAGS.SILENT (\\Deleted)');
        $this->command($this->has('UIDPLUS') ? 'UID EXPUNGE ' . $set : 'EXPUNGE');
    }

    /** File a message in a folder — how a sent message reaches Sent. */
    public function append(string $folder, string $raw, bool $seen = true): void
    {
        $this->command([
            'APPEND ', $this->quote($folder), $seen ? ' (\\Seen) ' : ' ',
            // Always a literal: a message is many lines and any bytes.
            new Literal($raw),
        ]);
    }

    // -----------------------------------------------------------------
    // Folder names and roles
    // -----------------------------------------------------------------

    /**
     * What a folder is for, from the server's own special-use flags, or
     * failing those from the names cPanel and common clients use.
     */
    public static function role(string $name, array $flags = []): ?string
    {
        $map = ['\\Sent' => 'sent', '\\Drafts' => 'drafts', '\\Trash' => 'trash', '\\Junk' => 'spam', '\\Archive' => 'archive'];

        foreach ($map as $flag => $role) {
            if (in_array($flag, $flags, true)) {
                return $role;
            }
        }

        if (strtoupper($name) === 'INBOX') {
            return 'inbox';
        }

        $leaf = strtolower(preg_replace('/^INBOX[.\/]/i', '', $name) ?? $name);

        return match ($leaf) {
            'sent', 'sent items', 'sent messages', 'sent mail'           => 'sent',
            'drafts', 'draft'                                            => 'drafts',
            'trash', 'deleted', 'deleted items', 'deleted messages', 'bin' => 'trash',
            'spam', 'junk', 'junk e-mail', 'junk email'                  => 'spam',
            'archive', 'archives'                                        => 'archive',
            default                                                      => null,
        };
    }

    /**
     * What to call a folder. The ones every mailbox has get their ordinary
     * names whatever the server calls them — cPanel's "INBOX.spam" is
     * shown as "Spam" — and everything else keeps its own.
     */
    public static function label(string $name, string $delimiter, ?string $role): string
    {
        return match ($role) {
            'inbox'   => 'Inbox',
            'sent'    => 'Sent',
            'drafts'  => 'Drafts',
            'trash'   => 'Trash',
            'spam'    => 'Spam',
            'archive' => 'Archive',
            default   => self::decodeName($name, $delimiter),
        };
    }

    /** "INBOX.Sent" -> "Sent"; modified UTF-7 names decoded for display. */
    public static function decodeName(string $name, string $delimiter): string
    {
        if (strtoupper($name) === 'INBOX') {
            return 'Inbox';
        }

        $shown = $name;

        if ($delimiter !== '' && stripos($name, 'INBOX' . $delimiter) === 0) {
            $shown = substr($name, 6);
        }

        $decoded = @mb_convert_encoding($shown, 'UTF-8', 'UTF7-IMAP');

        return is_string($decoded) && $decoded !== '' ? $decoded : $shown;
    }

    // -----------------------------------------------------------------
    // The wire
    // -----------------------------------------------------------------

    /** A UID set from integers the code chose — never from user input. */
    private static function set(array $uids): string
    {
        return implode(',', array_map('intval', $uids));
    }

    /**
     * A string as IMAP wants it: quoted when safe, a literal when not.
     *
     * A literal is used for anything with a control character or a byte
     * outside printable ASCII — a password with an accented letter, a
     * search for a name in another script — since quoting cannot carry
     * those. A literal's bytes are sent verbatim after the server asks for
     * them, so there is nothing in them to escape.
     */
    private function quote(string $value): string|Literal
    {
        if (preg_match('/[^\x20-\x7e]/', $value)) {
            return new Literal($value);
        }

        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . '"';
    }

    private function nextTag(): string
    {
        return 'A' . str_pad((string) ++$this->tagNo, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Send a command and read the whole reply.
     *
     * A command is a string, or a list of pieces some of which are Literal
     * objects. Each literal is announced with its length and its bytes
     * are sent only after the server answers "+", as IMAP requires.
     *
     * @param string|array<int, string|Literal> $command
     * @return array{status:string, text:string, untagged:list<array{text:string, literals:string[]}>}
     */
    private function command(string|array $command, bool $mustSucceed = true): array
    {
        $tag = $this->nextTag();
        $buffer = $tag . ' ';

        foreach ((array) $command as $piece) {
            if ($piece instanceof Literal) {
                $this->write($buffer . '{' . strlen($piece->value) . '}' . self::CRLF);

                $go = $this->readLine();

                if (!str_starts_with($go, '+')) {
                    throw new RuntimeException('The mail server refused part of a command: ' . trim($go));
                }

                $buffer = $piece->value;
                continue;
            }

            $buffer .= $piece;
        }

        $this->write($buffer . self::CRLF);
        $result = $this->readResponse($tag);

        if ($mustSucceed && $result['status'] !== 'OK') {
            throw new RuntimeException('The mail server said: ' . ($result['text'] ?: $result['status']));
        }

        return $result;
    }

    /**
     * @return array{status:string, text:string, untagged:list<array{text:string, literals:string[]}>}
     */
    private function readResponse(string $tag): array
    {
        $untagged = [];

        while (true) {
            [$text, $literals] = $this->readLogicalLine();

            if (str_starts_with($text, $tag . ' ')) {
                $rest = substr($text, strlen($tag) + 1);
                $status = strtoupper((string) strtok($rest, ' '));

                return ['status' => $status, 'text' => trim(substr($rest, strlen($status))), 'untagged' => $untagged];
            }

            if (str_starts_with($text, '* ')) {
                $untagged[] = ['text' => substr($text, 2), 'literals' => $literals];
            }
        }
    }

    /**
     * One response, with any literals it carries read in full.
     *
     * A line ending in {N} is followed by exactly N bytes of data, then the
     * rest of the line. Each literal's place in the text is marked with
     * Tokenizer::MARK and its index, so a message body containing
     * parentheses or quotes cannot confuse the parser.
     *
     * @return array{0:string, 1:string[]}
     */
    private function readLogicalLine(): array
    {
        $text = '';
        $literals = [];

        while (true) {
            $line = $this->readLine();

            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $text .= substr($line, 0, -strlen($m[0])) . Tokenizer::MARK . count($literals) . Tokenizer::MARK;
                $literals[] = $this->readBytes((int) $m[1]);
                continue;
            }

            return [$text . rtrim($line, "\r\n"), $literals];
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->socket);

        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            throw new RuntimeException(($meta['timed_out'] ?? false)
                ? 'The mail server stopped answering.'
                : 'The connection to the mail server was lost.');
        }

        return $line;
    }

    private function readBytes(int $n): string
    {
        $data = '';

        while (strlen($data) < $n) {
            $chunk = fread($this->socket, min(65536, $n - strlen($data)));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);

                if (feof($this->socket) || ($meta['timed_out'] ?? false)) {
                    throw new RuntimeException('The mail server stopped part-way through a message.');
                }

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        $done = 0;

        while ($done < $len) {
            $n = @fwrite($this->socket, substr($data, $done));

            if ($n === false || $n === 0) {
                throw new RuntimeException('Could not write to the mail server.');
            }

            $done += $n;
        }
    }

    /**
     * The data items of a "* n FETCH (...)" response, keyed by name.
     *
     * @return array<string, mixed>|null
     */
    private function fetchItem(array $untagged): ?array
    {
        if (!preg_match('/^\d+ FETCH /i', $untagged['text'], $m)) {
            return null;
        }

        $tokens = Tokenizer::parse(substr($untagged['text'], strlen($m[0])), $untagged['literals']);
        $list = $tokens[0] ?? null;

        if (!is_array($list)) {
            return null;
        }

        $out = [];

        for ($i = 0; $i + 1 < count($list); $i += 2) {
            $out[strtoupper((string) $list[$i])] = $list[$i + 1];
        }

        return $out;
    }
}
