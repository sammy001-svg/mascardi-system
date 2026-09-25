<?php

namespace Mascardi\Mail;

/**
 * Taking email apart, and putting it together.
 *
 * Reading: a raw message becomes its headers, its plain-text and HTML
 * bodies, its attachments and its inline images, whatever mix of
 * multipart nesting, transfer encodings and character sets it arrived in.
 * Email from the wild is frequently not quite what the standards say, so
 * every step here prefers "show something reasonable" to "refuse".
 *
 * Writing: a message from the compose form becomes a standards-conforming
 * RFC 5322 message with a plain-text part, an HTML part, and any
 * attachments, ready both to send and to file in Sent.
 */
final class Mime
{
    private const CRLF = "\r\n";

    // =================================================================
    // Reading
    // =================================================================

    /**
     * @return array{
     *   headers: array<string,string>,
     *   subject: string, from: array{name:string,email:string}|null,
     *   to: list<array{name:string,email:string}>, cc: list<array{name:string,email:string}>,
     *   reply_to: list<array{name:string,email:string}>,
     *   date: ?int, message_id: string, references: string,
     *   text: string, html: string,
     *   attachments: list<array{name:string, mime:string, size:int, data:string, cid:string, inline:bool}>
     * }
     */
    public static function parse(string $raw): array
    {
        [$headerBlock, $body] = self::split($raw);
        $headers = self::headers($headerBlock);

        $out = [
            'headers'     => $headers,
            'subject'     => self::decodeHeader($headers['subject'] ?? ''),
            'from'        => self::addresses($headers['from'] ?? '')[0] ?? null,
            'to'          => self::addresses($headers['to'] ?? ''),
            'cc'          => self::addresses($headers['cc'] ?? ''),
            'reply_to'    => self::addresses($headers['reply-to'] ?? ''),
            'date'        => self::date($headers['date'] ?? ''),
            'message_id'  => trim($headers['message-id'] ?? ''),
            'references'  => trim($headers['references'] ?? ''),
            'text'        => '',
            'html'        => '',
            'attachments' => [],
        ];

        self::walk($headers, $body, $out, 0);

        // A message with only HTML still deserves a text version for the
        // reply quote and the list preview, and vice versa.
        if ($out['text'] === '' && $out['html'] !== '') {
            $out['text'] = self::htmlToText($out['html']);
        }

        return $out;
    }

    /** Only the headers — for message lists, from a HEADER.FIELDS fetch. */
    public static function parseHeaders(string $block): array
    {
        $h = self::headers($block);

        return [
            'subject' => self::decodeHeader($h['subject'] ?? ''),
            'from'    => self::addresses($h['from'] ?? '')[0] ?? null,
            'to'      => self::addresses($h['to'] ?? ''),
            'date'    => self::date($h['date'] ?? ''),
            // multipart/mixed is where attachments live; close enough for
            // a paperclip in a list without fetching every body.
            'has_attachments' => stripos($h['content-type'] ?? '', 'multipart/mixed') !== false,
        ];
    }

    private static function walk(array $headers, string $body, array &$out, int $depth): void
    {
        if ($depth > 12) {
            return; // a pathological message is not worth a stack overflow
        }

        [$type, $params] = self::contentType($headers['content-type'] ?? 'text/plain');
        $disposition = strtolower(trim(strtok($headers['content-disposition'] ?? '', ';') ?: ''));
        $filename = self::filename($headers);

        if (str_starts_with($type, 'multipart/') && !empty($params['boundary'])) {
            foreach (self::parts($body, $params['boundary']) as $part) {
                [$ph, $pb] = self::split($part);
                self::walk(self::headers($ph), $pb, $out, $depth + 1);
            }
            return;
        }

        if ($type === 'message/rfc822' && $disposition !== 'attachment') {
            // A forwarded message: its text reads as part of this one.
            $inner = self::parse($body);
            $out['text'] .= ($out['text'] !== '' ? "\n\n" : '') . "---------- Forwarded message ----------\n" . $inner['text'];
            $out['attachments'] = array_merge($out['attachments'], $inner['attachments']);
            return;
        }

        $data = self::decodeBody($body, strtolower(trim($headers['content-transfer-encoding'] ?? '')));
        $isAttachment = $disposition === 'attachment' || ($filename !== '' && !str_starts_with($type, 'text/'));

        if (!$isAttachment && $type === 'text/plain' && $out['text'] === '') {
            $out['text'] = self::toUtf8($data, $params['charset'] ?? '');
            return;
        }

        if (!$isAttachment && $type === 'text/html' && $out['html'] === '') {
            $out['html'] = self::toUtf8($data, $params['charset'] ?? '');
            return;
        }

        if (!$isAttachment && str_starts_with($type, 'text/')) {
            return; // a second text body of the same kind adds nothing
        }

        $cid = trim($headers['content-id'] ?? '', " <>\t");

        $out['attachments'][] = [
            'name'   => $filename !== '' ? $filename : ('attachment-' . (count($out['attachments']) + 1) . self::extension($type)),
            'mime'   => $type,
            'size'   => strlen($data),
            'data'   => $data,
            'cid'    => $cid,
            'inline' => $cid !== '' && $disposition !== 'attachment',
        ];
    }

    /** @return array{0:string, 1:string} header block, body */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");

        if ($pos === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /** @return array<string,string> lower-cased names; repeated headers joined */
    private static function headers(string $block): array
    {
        $block = preg_replace("/\n[ \t]+/", ' ', str_replace("\r\n", "\n", $block)) ?? $block;
        $out = [];

        foreach (explode("\n", $block) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            // Received: and friends repeat; the first of anything we read is
            // the one that matters.
            if (!isset($out[$name])) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @return array{0:string, 1:array<string,string>} */
    private static function contentType(string $value): array
    {
        $bits = explode(';', $value);
        $type = strtolower(trim(array_shift($bits)));

        return [$type ?: 'text/plain', self::params(implode(';', $bits))];
    }

    /** name="x"; charset=utf-8; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf */
    private static function params(string $s): array
    {
        $out = [];

        preg_match_all('/([a-z0-9*_.-]+)\s*=\s*("((?:[^"\\\\]|\\\\.)*)"|[^;]*)/i', $s, $m, PREG_SET_ORDER);

        foreach ($m as $p) {
            $key = strtolower($p[1]);
            $val = isset($p[3]) && $p[2] !== '' && $p[2][0] === '"' ? stripcslashes($p[3]) : trim($p[2]);
            $out[$key] = $val;
        }

        // RFC 2231: filename*0*=, filename*1*= ... and filename*=charset''value
        foreach (['filename', 'name'] as $base) {
            $pieces = [];
            foreach ($out as $k => $v) {
                if (preg_match('/^' . $base . '\*(\d+)\*?$/', $k, $mm)) {
                    $pieces[(int) $mm[1]] = $v;
                }
            }
            if ($pieces) {
                ksort($pieces);
                $out[$base . '*'] = implode('', $pieces);
            }
            if (isset($out[$base . '*'])) {
                $v = $out[$base . '*'];
                if (preg_match("/^([^']*)'[^']*'(.*)$/", $v, $mm)) {
                    $out[$base] = self::toUtf8(rawurldecode($mm[2]), $mm[1]);
                } else {
                    $out[$base] = rawurldecode($v);
                }
            }
        }

        return $out;
    }

    private static function filename(array $headers): string
    {
        $disp = self::params(substr($headers['content-disposition'] ?? '', strpos(($headers['content-disposition'] ?? '') . ';', ';')));
        $type = self::params(substr($headers['content-type'] ?? '', strpos(($headers['content-type'] ?? '') . ';', ';')));
        $name = $disp['filename'] ?? $type['name'] ?? '';

        // Nothing path-like survives: this name is offered as a download.
        $name = self::decodeHeader($name);
        $name = basename(str_replace('\\', '/', $name));

        return trim(preg_replace('/[\x00-\x1f\x7f"]/', '', $name) ?? '');
    }

    /** @return string[] */
    private static function parts(string $body, string $boundary): array
    {
        $body = str_replace("\r\n", "\n", $body);
        $delim = '--' . $boundary;
        $chunks = explode("\n" . $delim, "\n" . $body);
        array_shift($chunks); // the preamble

        $parts = [];

        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break; // closing delimiter
            }

            // Drop the rest of the delimiter line.
            $nl = strpos($chunk, "\n");
            $parts[] = $nl === false ? '' : substr($chunk, $nl + 1);
        }

        return $parts;
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? ''),
            'quoted-printable' => quoted_printable_decode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))),
            default            => $body,
        };
    }

    public static function toUtf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \"'"));

        if ($charset === '' || $charset === 'US-ASCII' || $charset === 'ASCII') {
            $charset = mb_check_encoding($text, 'UTF-8') ? 'UTF-8' : 'WINDOWS-1252';
        }

        // Mail clients routinely label Windows-1252 as ISO-8859-1.
        if ($charset === 'ISO-8859-1' || $charset === 'LATIN1') {
            $charset = 'WINDOWS-1252';
        }

        if ($charset !== 'UTF-8') {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);

            if (!is_string($converted) || $converted === '') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            }

            $text = is_string($converted) ? $converted : $text;
        }

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /** =?UTF-8?B?...?= and =?ISO-8859-1?Q?...?= in a header. */
    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return self::toUtf8($value, '');
        }

        // Whitespace between two encoded words is not part of the text.
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value) ?? $value;

        $decoded = preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', static function ($m) {
            $charset = preg_replace('/\*.*$/', '', $m[1]); // drop RFC 2231 language
            $data = strtoupper($m[2]) === 'B'
                ? (string) base64_decode($m[3])
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));

            return self::toUtf8($data, $charset);
        }, $value);

        return $decoded ?? $value;
    }

    /**
     * "Jane <jane@x.com>, bob@y.com, \"Smith, J\" <j@z.com>"
     *
     * @return list<array{name:string,email:string}>
     */
    public static function addresses(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $out = [];
        $current = '';
        $inQuote = false;
        $inAngle = false;

        // Split on commas that are not inside quotes or angle brackets.
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $c = $value[$i];

            if ($c === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $inQuote = !$inQuote;
            } elseif ($c === '<' && !$inQuote) {
                $inAngle = true;
            } elseif ($c === '>' && !$inQuote) {
                $inAngle = false;
            }

            if ($c === ',' && !$inQuote && !$inAngle) {
                $out[] = $current;
                $current = '';
                continue;
            }

            $current .= $c;
        }
        $out[] = $current;

        $list = [];

        foreach ($out as $one) {
            $one = trim($one);

            if ($one === '') {
                continue;
            }

            if (preg_match('/^(.*)<([^>]+)>\s*$/s', $one, $m)) {
                $name = trim($m[1], " \t\"'");
                $email = trim($m[2]);
            } else {
                $name = '';
                $email = trim($one, " <>");
            }

            $list[] = [
                'name'  => self::decodeHeader(stripslashes($name)),
                'email' => strtolower($email),
            ];
        }

        return $list;
    }

    private static function date(string $value): ?int
    {
        $value = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $value) ?? $value);
        $ts = $value !== '' ? strtotime($value) : false;

        return $ts === false ? null : $ts;
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|h[1-6]|li|blockquote)>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;

        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }

    private static function extension(string $mime): string
    {
        return match ($mime) {
            'image/png' => '.png', 'image/jpeg' => '.jpg', 'image/gif' => '.gif',
            'application/pdf' => '.pdf', 'text/calendar' => '.ics',
            default => '',
        };
    }

    // =================================================================
    // Writing
    // =================================================================

    /**
     * A complete message, ready to send and to file in Sent.
     *
     * @param array{
     *   from_email:string, from_name:string,
     *   to:string[], cc:string[], subject:string, text:string,
     *   in_reply_to?:string, references?:string,
     *   attachments?:list<array{name:string, mime:string, data:string}>
     * } $m
     * @return array{raw:string, message_id:string}
     */
    public static function build(array $m): array
    {
        $domain = substr(strrchr($m['from_email'], '@') ?: '@localhost', 1);
        $messageId = '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain . '>';

        $h = [];
        $h[] = 'From: ' . self::formatAddress($m['from_name'], $m['from_email']);
        $h[] = 'To: ' . implode(', ', $m['to']);

        if (!empty($m['cc'])) {
            $h[] = 'Cc: ' . implode(', ', $m['cc']);
        }
        // Bcc is deliberately never written into the message.

        $h[] = 'Subject: ' . self::encodeHeader($m['subject']);
        $h[] = 'Date: ' . date('r');
        $h[] = 'Message-ID: ' . $messageId;

        if (!empty($m['in_reply_to'])) {
            $h[] = 'In-Reply-To: ' . self::cleanId($m['in_reply_to']);
            $refs = trim(($m['references'] ?? '') . ' ' . self::cleanId($m['in_reply_to']));
            $h[] = 'References: ' . self::foldIds($refs);
        }

        $h[] = 'MIME-Version: 1.0';
        $h[] = 'X-Mailer: Shanfix BMS';

        $text = str_replace(["\r\n", "\r"], "\n", $m['text']);
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55">'
              . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))
              . '</div>';

        $alt = 'alt_' . bin2hex(random_bytes(8));
        $alternative = 'Content-Type: multipart/alternative; boundary="' . $alt . '"' . self::CRLF . self::CRLF
            . '--' . $alt . self::CRLF
            . 'Content-Type: text/plain; charset=UTF-8' . self::CRLF
            . 'Content-Transfer-Encoding: quoted-printable' . self::CRLF . self::CRLF
            . self::qp($text) . self::CRLF
            . '--' . $alt . self::CRLF
            . 'Content-Type: text/html; charset=UTF-8' . self::CRLF
            . 'Content-Transfer-Encoding: quoted-printable' . self::CRLF . self::CRLF
            . self::qp($html) . self::CRLF
            . '--' . $alt . '--';

        $attachments = $m['attachments'] ?? [];

        if (!$attachments) {
            $raw = implode(self::CRLF, $h) . self::CRLF . $alternative . self::CRLF;
            return ['raw' => $raw, 'message_id' => $messageId];
        }

        $mix = 'mix_' . bin2hex(random_bytes(8));
        $h[] = 'Content-Type: multipart/mixed; boundary="' . $mix . '"';

        $body = '--' . $mix . self::CRLF . $alternative . self::CRLF;

        foreach ($attachments as $a) {
            $name = self::encodeHeader($a['name']);
            $body .= '--' . $mix . self::CRLF
                . 'Content-Type: ' . $a['mime'] . '; name="' . $name . '"' . self::CRLF
                . 'Content-Transfer-Encoding: base64' . self::CRLF
                . 'Content-Disposition: attachment; filename="' . $name . '"' . self::CRLF . self::CRLF
                . rtrim(chunk_split(base64_encode($a['data']), 76, self::CRLF)) . self::CRLF;
        }

        $body .= '--' . $mix . '--';

        return ['raw' => implode(self::CRLF, $h) . self::CRLF . self::CRLF . $body . self::CRLF, 'message_id' => $messageId];
    }

    public static function formatAddress(string $name, string $email): string
    {
        $name = trim(str_replace(["\r", "\n"], '', $name));

        if ($name === '') {
            return $email;
        }

        return (preg_match('/[^\x20-\x7e]/', $name)
            ? self::encodeHeader($name)
            : '"' . addcslashes($name, '"\\') . '"') . ' <' . $email . '>';
    }

    public static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        if (!preg_match('/[^\x20-\x7e]/', $value)) {
            return $value;
        }

        return mb_encode_mimeheader($value, 'UTF-8', 'B', self::CRLF);
    }

    private static function qp(string $text): string
    {
        return quoted_printable_encode(str_replace("\n", self::CRLF, str_replace(["\r\n", "\r"], "\n", $text)));
    }

    /** Only a well-formed <id@host>, so header injection has nowhere to go. */
    private static function cleanId(string $id): string
    {
        return preg_match('/^<[^<>\s]+>$/', trim($id)) ? trim($id) : '';
    }

    private static function foldIds(string $ids): string
    {
        preg_match_all('/<[^<>\s]+>/', $ids, $m);

        // Keep the thread's root and the most recent few, as clients do.
        $list = $m[0];
        if (count($list) > 10) {
            $list = array_merge([$list[0]], array_slice($list, -9));
        }

        return implode(self::CRLF . ' ', $list);
    }
}
