<?php

namespace Mascardi\Mail;

use PDO;
use RuntimeException;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lib/Smtp.php';

/**
 * One email account belonging to a member of staff.
 *
 * A user may now have up to MAIL_MAX_ACCOUNTS (5) accounts. Every entry
 * point accepts the signed-in user's id; no account id ever arrives from a
 * request without being validated against that user first — you can never
 * reach somebody else's account through here.
 *
 * The active account for a session is chosen by mailBox() in _page.php via
 * an account id stored in the session, not in a GET/POST parameter that a
 * script or a link from another page could supply.
 */
final class Mailbox
{
    private ?ImapClient $imap = null;

    /** Display order for the folders every mailbox has. */
    private const ROLE_ORDER = ['inbox' => 0, 'drafts' => 1, 'sent' => 2,
                                'archive' => 3, 'spam' => 4, 'trash' => 5];

    private function __construct(private array $account)
    {
    }

    // ── The account ──────────────────────────────────────────────────────────

    private static function db(): PDO
    {
        $db = \getDB();
        \mailMigrate($db);

        return $db;
    }

    /**
     * All accounts for one user, in display order.
     *
     * @return list<array>
     */
    public static function accountsFor(int $userId): array
    {
        try {
            $st = self::db()->prepare(
                'SELECT * FROM mail_accounts WHERE user_id = ? ORDER BY is_default DESC, sort_order ASC, id ASC'
            );
            $st->execute([$userId]);

            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('mail accountsFor: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * One specific account, validated as belonging to $userId.
     * Returns null when the row does not exist or does not belong to this user.
     */
    public static function accountFor(int $userId, ?int $accountId = null): ?array
    {
        try {
            if ($accountId !== null) {
                $st = self::db()->prepare(
                    'SELECT * FROM mail_accounts WHERE id = ? AND user_id = ? LIMIT 1'
                );
                $st->execute([$accountId, $userId]);
            } else {
                // Default: the row marked is_default, or the oldest one.
                $st = self::db()->prepare(
                    'SELECT * FROM mail_accounts WHERE user_id = ? ORDER BY is_default DESC, id ASC LIMIT 1'
                );
                $st->execute([$userId]);
            }

            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            error_log('mail accountFor: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Open a mailbox for the given account.
     *
     * @param int      $userId    The signed-in user's id (never from a request).
     * @param int|null $accountId The specific account row, or null for the default.
     */
    public static function for(int $userId, ?int $accountId = null): ?self
    {
        $row = self::accountFor($userId, $accountId);

        return $row ? new self($row) : null;
    }

    /**
     * Connect (add or update) a mailbox account for $userId.
     *
     * When $accountId is null a new row is created (up to the MAIL_MAX_ACCOUNTS
     * limit). When it is an existing row id that belongs to this user, that row
     * is updated.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function connectAccount(
        int $userId,
        string $email,
        string $password,
        string $displayName,
        string $signature,
        string $accountLabel = '',
        ?int $accountId = null
    ): array {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That does not look like an email address.'];
        }

        $db = self::db();

        // When editing an existing account, fetch it first to validate ownership.
        $existing = null;
        $count    = 0; // number of accounts this user already has (filled below)

        if ($accountId !== null) {
            $existing = self::accountFor($userId, $accountId);
            if (!$existing) {
                return ['ok' => false, 'error' => 'That account was not found.'];
            }
        } else {
            // Adding a new one — check the cap.
            try {
                $st = $db->prepare('SELECT COUNT(*) FROM mail_accounts WHERE user_id = ?');
                $st->execute([$userId]);
                $count = (int)$st->fetchColumn();
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'That could not be checked just now.'];
            }

            if ($count >= MAIL_MAX_ACCOUNTS) {
                return ['ok' => false, 'error' => 'You already have ' . MAIL_MAX_ACCOUNTS
                    . ' email accounts connected. Disconnect one before adding another.'];
            }

            // Check if this user already has this email connected (as a different account).
            try {
                $st = $db->prepare('SELECT id FROM mail_accounts WHERE user_id = ? AND email = ?');
                $st->execute([$userId, $email]);
                if ($st->fetchColumn()) {
                    return ['ok' => false, 'error' => 'You have already connected that email address.'];
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'That could not be checked just now.'];
            }
        }

        // Changing an email address? Make sure nobody else already claimed it.
        $isEmailChange = $existing && strcasecmp((string)$existing['email'], $email) !== 0;
        if (!$existing || $isEmailChange) {
            try {
                $st = $db->prepare('SELECT user_id FROM mail_accounts WHERE email = ? AND user_id <> ?');
                $st->execute([$email, $userId]);
                if ($st->fetchColumn()) {
                    return ['ok' => false, 'error' => 'Somebody else on the team has already connected that mailbox.'];
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'That could not be checked just now.'];
            }
        }

        // Keep the saved password when the field is left blank and the address has not changed.
        if ($password === '' && $existing && !$isEmailChange) {
            $password = (string)\mailDecrypt((string)$existing['password_enc']);
        }

        if ($password === '') {
            return ['ok' => false, 'error' => 'Enter the password for this mailbox.'];
        }

        // Prove the credentials work before saving anything.
        try {
            $probe = new self(['email' => $email, 'password_enc' => \mailEncrypt($password)]);
            $probe->imap();
            $roles = $probe->discoverRoles();
            $probe->close();
        } catch (AuthFailed) {
            return ['ok' => false, 'error' => 'The mail server did not accept that email address and password.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // Is this the user's first account? Make it the default.
        $isFirst = ($existing === null && $count === 0);

        $data = [
            'email'         => $email,
            'password_enc'  => \mailEncrypt($password),
            'display_name'  => mb_substr(trim($displayName), 0, 120) ?: null,
            'account_label' => mb_substr(trim($accountLabel), 0, 80) ?: null,
            'signature'     => mb_substr(trim($signature), 0, 2000) ?: null,
            'is_default'    => $isFirst ? 1 : ($existing ? (int)$existing['is_default'] : 0),
            'sent_folder'   => $roles['sent'],
            'trash_folder'  => $roles['trash'],
            'last_ok_at'    => date('Y-m-d H:i:s'),
            'last_error'    => null,
        ];

        try {
            if ($existing) {
                $set = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($data)));
                $st  = $db->prepare("UPDATE mail_accounts SET $set WHERE id = ? AND user_id = ?");
                $st->execute([...array_values($data), (int)$existing['id'], $userId]);
            } else {
                $data['user_id']   = $userId;
                $data['sort_order'] = $count; // append at the end
                $cols = implode(', ', array_keys($data));
                $qs   = implode(', ', array_fill(0, count($data), '?'));
                $db->prepare("INSERT INTO mail_accounts ($cols) VALUES ($qs)")
                   ->execute(array_values($data));
            }
        } catch (\Throwable $e) {
            error_log('mail connectAccount: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'The mailbox could not be saved.'];
        }

        return ['ok' => true];
    }

    /**
     * Set one account as the default for a user.
     * Clears is_default on all others first.
     */
    public static function setDefault(int $userId, int $accountId): bool
    {
        try {
            $db = self::db();
            // Validate ownership.
            $st = $db->prepare('SELECT id FROM mail_accounts WHERE id = ? AND user_id = ?');
            $st->execute([$accountId, $userId]);
            if (!$st->fetchColumn()) return false;

            $db->prepare('UPDATE mail_accounts SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
            $db->prepare('UPDATE mail_accounts SET is_default = 1 WHERE id = ? AND user_id = ?')
               ->execute([$accountId, $userId]);

            return true;
        } catch (\Throwable $e) {
            error_log('mail setDefault: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Remove one account. When it was the default, promote the oldest remaining one.
     */
    public static function disconnect(int $userId, int $accountId): void
    {
        try {
            $db = self::db();
            $st = $db->prepare('SELECT is_default FROM mail_accounts WHERE id = ? AND user_id = ?');
            $st->execute([$accountId, $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) return; // not owned by this user

            $db->prepare('DELETE FROM mail_accounts WHERE id = ? AND user_id = ?')
               ->execute([$accountId, $userId]);

            // Promote the next oldest account to default if needed.
            if ((int)$row['is_default'] === 1) {
                $db->prepare(
                    'UPDATE mail_accounts SET is_default = 1
                     WHERE user_id = ? ORDER BY id ASC LIMIT 1'
                )->execute([$userId]);
            }
        } catch (\Throwable $e) {
            error_log('mail disconnect: ' . $e->getMessage());
        }
    }

    public function id(): int          { return (int)$this->account['id']; }
    public function email(): string    { return (string)$this->account['email']; }
    public function displayName(): string { return (string)($this->account['display_name'] ?? ''); }
    public function signature(): string   { return (string)($this->account['signature'] ?? ''); }
    public function label(): string {
        $l = trim((string)($this->account['account_label'] ?? ''));
        return $l !== '' ? $l : $this->email();
    }
    public function isDefault(): bool  { return (int)($this->account['is_default'] ?? 0) === 1; }

    // ── Reading ──────────────────────────────────────────────────────────────

    /**
     * Folders, the fixed ones first in the order every mail client uses.
     *
     * @return list<array{name:string, label:string, role:?string, unseen:int}>
     */
    public function folders(): array
    {
        $folders = $this->imap()->folders();

        foreach ($folders as &$f) {
            // Unread counts only where they are worth a round trip.
            $f['unseen'] = in_array($f['role'], ['inbox', 'spam'], true)
                ? $this->imap()->status($f['name'])['unseen']
                : 0;
        }
        unset($f);

        usort($folders, static function ($a, $b) {
            $ra = self::ROLE_ORDER[$a['role'] ?? ''] ?? 50;
            $rb = self::ROLE_ORDER[$b['role'] ?? ''] ?? 50;

            return $ra <=> $rb ?: strcasecmp($a['label'], $b['label']);
        });

        return $folders;
    }

    /**
     * One page of a folder.
     *
     * @return array{total:int, messages:list<array>}
     */
    public function messages(string $folder, int $page = 1, int $perPage = 30, string $search = ''): array
    {
        $this->imap()->select($folder, true);
        $uids  = $this->imap()->search($search);
        $total = count($uids);

        $slice = array_slice($uids, max(0, ($page - 1) * $perPage), $perPage);
        $rows  = $this->imap()->summaries($slice);

        $messages = [];

        // In the order search gave them — newest first — not fetch order.
        foreach ($slice as $uid) {
            if (!isset($rows[$uid])) continue;

            $r = $rows[$uid];
            $h = Mime::parseHeaders($r['headers']);

            $messages[] = [
                'uid'             => $uid,
                'subject'         => $h['subject'] !== '' ? $h['subject'] : '(no subject)',
                'from'            => $h['from'],
                'to'              => $h['to'],
                'date'            => $h['date'] ?? (strtotime($r['date']) ?: null),
                'size'            => $r['size'],
                'seen'            => in_array('\\Seen', $r['flags'], true),
                'flagged'         => in_array('\\Flagged', $r['flags'], true),
                'answered'        => in_array('\\Answered', $r['flags'], true),
                'has_attachments' => $h['has_attachments'],
            ];
        }

        return ['total' => $total, 'messages' => $messages];
    }

    /** A whole message, parsed. Opening it marks it read, as anywhere else. */
    public function message(string $folder, int $uid): ?array
    {
        $this->imap()->select($folder);
        $raw = $this->imap()->raw($uid);

        if ($raw === null) return null;

        $this->imap()->flag([$uid], '\\Seen', true);

        $m           = Mime::parse($raw);
        $m['uid']    = $uid;
        $m['folder'] = $folder;

        return $m;
    }

    public function unreadInInbox(): int
    {
        return $this->imap()->status('INBOX')['unseen'];
    }

    // ── Changing ─────────────────────────────────────────────────────────────

    public function setSeen(string $folder, array $uids, bool $seen): void
    {
        $this->imap()->select($folder);
        $this->imap()->flag($uids, '\\Seen', $seen);
    }

    public function setFlagged(string $folder, array $uids, bool $flagged): void
    {
        $this->imap()->select($folder);
        $this->imap()->flag($uids, '\\Flagged', $flagged);
    }

    /**
     * Delete: to Trash, as every client does. Deleting from Trash or Spam is
     * for good, because that is what a person means the second time.
     */
    public function delete(string $folder, array $uids): void
    {
        $role = ImapClient::role($folder);
        $this->imap()->select($folder);

        if (in_array($role, ['trash', 'spam'], true) || !$this->trashFolder()) {
            $this->imap()->destroy($uids);

            return;
        }

        $this->imap()->move($uids, $this->trashFolder());
    }

    public function move(string $folder, array $uids, string $to): void
    {
        $known = array_column($this->imap()->folders(), 'name');

        if (!in_array($to, $known, true)) {
            throw new RuntimeException('There is no folder called ' . $to . '.');
        }

        $this->imap()->select($folder);
        $this->imap()->move($uids, $to);
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    /**
     * Send as this person, and file a copy in their Sent folder.
     *
     * @return array{ok:bool, error?:string, warning?:string}
     */
    public function send(array $in): array
    {
        $to  = self::parseRecipients($in['to']  ?? '');
        $cc  = self::parseRecipients($in['cc']  ?? '');
        $bcc = self::parseRecipients($in['bcc'] ?? '');

        foreach ([$to, $cc, $bcc] as $list) {
            if ($list['invalid']) {
                return ['ok' => false,
                        'error' => 'These do not look like email addresses: ' . implode(', ', $list['invalid'])];
            }
        }

        if (!$to['formatted']) {
            return ['ok' => false, 'error' => 'Add at least one person to send it to.'];
        }

        $text = rtrim((string)($in['text'] ?? ''));

        if ($this->signature() !== '' && !str_contains($text, $this->signature())) {
            $text .= "\n\n-- \n" . $this->signature();
        }

        $built = Mime::build([
            'from_email'  => $this->email(),
            'from_name'   => $this->displayName(),
            'to'          => $to['formatted'],
            'cc'          => $cc['formatted'],
            'subject'     => trim((string)($in['subject'] ?? '')) !== ''
                                ? trim((string)$in['subject']) : '(no subject)',
            'text'        => $text,
            'in_reply_to' => $in['in_reply_to'] ?? '',
            'references'  => $in['references']  ?? '',
            'attachments' => $in['attachments'] ?? [],
        ]);

        $smtp = new Smtp(
            (string)\getSetting('mail_smtp_host', ''),
            (int)   \getSetting('mail_smtp_port', '465'),
            (string)\getSetting('mail_smtp_security', 'ssl'),
            $this->email(),
            $this->password(),
            $this->email()
        );

        // Bcc goes in the envelope and nowhere else, which is what makes it blind.
        $result = $smtp->sendRaw(
            $this->email(),
            array_merge($to['emails'], $cc['emails'], $bcc['emails']),
            $built['raw']
        );

        if (!$result['ok']) {
            return ['ok' => false, 'error' => 'The message was not sent: ' . ($result['error'] ?? 'unknown error')];
        }

        // Filing the copy is separate from sending it. The message has gone
        // either way, so a failure here is a warning, not an error.
        $warning = null;

        try {
            $sent = $this->sentFolder();

            if ($sent) {
                $this->imap()->append($sent, $built['raw']);
            } else {
                $warning = 'Sent, but this mailbox has no Sent folder to keep a copy in.';
            }
        } catch (\Throwable $e) {
            $warning = 'Sent, but a copy could not be saved in Sent: ' . $e->getMessage();
        }

        if (!empty($result['refused'])) {
            $warning = trim(($warning ? $warning . ' ' : '')
                     . 'The server refused: ' . implode(', ', $result['refused']) . '.');
        }

        return ['ok' => true] + ($warning ? ['warning' => $warning] : []);
    }

    /**
     * "Jane <jane@x.com>, bob@y.com" — split, checked, and returned both as
     * header-ready addresses and as bare ones for the envelope.
     *
     * @return array{formatted:string[], emails:string[], invalid:string[]}
     */
    public static function parseRecipients(string $input): array
    {
        $out = ['formatted' => [], 'emails' => [], 'invalid' => []];

        // Commas and semicolons both, since people type both.
        foreach (Mime::addresses(str_replace(';', ',', $input)) as $a) {
            $email = $a['email'];

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out['invalid'][] = $a['name'] !== '' ? $a['name'] . ' <' . $email . '>' : $email;
                continue;
            }

            if (in_array($email, $out['emails'], true)) continue;

            $out['emails'][]    = $email;
            $out['formatted'][] = Mime::formatAddress($a['name'], $email);
        }

        return $out;
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    private function password(): string
    {
        $p = \mailDecrypt((string)$this->account['password_enc']);

        if ($p === null) {
            throw new RuntimeException('The saved password for this mailbox can no longer be read. '
                                     . 'Connect it again.');
        }

        return $p;
    }

    private function imap(): ImapClient
    {
        if ($this->imap !== null) return $this->imap;

        $imap = new ImapClient(
            (string)\getSetting('mail_imap_host', ''),
            (int)   \getSetting('mail_imap_port', '993'),
            (string)\getSetting('mail_imap_security', 'ssl')
        );
        $imap->connect();
        $imap->login($this->email(), $this->password());

        return $this->imap = $imap;
    }

    public function close(): void
    {
        $this->imap?->logout();
        $this->imap = null;
    }

    /** Remember that this mailbox worked, or why it did not. */
    public function noteResult(?string $error): void
    {
        if (empty($this->account['id'])) return;

        try {
            self::db()->prepare('UPDATE mail_accounts SET last_ok_at = ?, last_error = ? WHERE id = ?')
                      ->execute([
                          $error === null ? date('Y-m-d H:i:s') : ($this->account['last_ok_at'] ?? null),
                          $error === null ? null : mb_substr($error, 0, 255),
                          (int)$this->account['id'],
                      ]);
        } catch (\Throwable $e) { /* a note about health must not break the page */ }
    }

    /** @return array{sent:?string, trash:?string} */
    private function discoverRoles(): array
    {
        $found = ['sent' => null, 'trash' => null];

        foreach ($this->imap()->folders() as $f) {
            $role = $f['role'] ?? '';

            if (array_key_exists($role, $found) && $found[$role] === null) {
                $found[$role] = $f['name'];
            }
        }

        return $found;
    }

    private function sentFolder(): ?string
    {
        return $this->account['sent_folder'] ?? null ?: $this->discoverRoles()['sent'];
    }

    private function trashFolder(): ?string
    {
        return $this->account['trash_folder'] ?? null ?: $this->discoverRoles()['trash'];
    }
}
