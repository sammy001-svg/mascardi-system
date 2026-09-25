<?php

namespace Mascardi\Mail;

/**
 * Reads the parenthesised structures IMAP answers with.
 *
 *   (UID 7 FLAGS (\Seen) BODY[HEADER.FIELDS (FROM)] <literal>)
 *
 * becomes ['UID', '7', 'FLAGS', ['\Seen'], 'BODY[HEADER.FIELDS (FROM)]', '...'].
 *
 * Literals have already been read off the wire by ImapClient and replaced
 * in the text by MARK + index + MARK, so their content — which is a whole
 * email and may hold anything at all — is never scanned here.
 */
final class Tokenizer
{
    public const MARK = "\x01";

    /**
     * @param string[] $literals
     * @return list<mixed> strings, nulls (NIL) and nested lists
     */
    public static function parse(string $text, array $literals = []): array
    {
        $pos = 0;

        return self::sequence($text, $pos, $literals, false);
    }

    private static function sequence(string $s, int &$i, array $literals, bool $inList): array
    {
        $out = [];
        $n = strlen($s);

        while ($i < $n) {
            $c = $s[$i];

            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $i++;
                continue;
            }

            if ($c === ')') {
                $i++;
                if ($inList) {
                    return $out;
                }
                continue;
            }

            if ($c === '(') {
                $i++;
                $out[] = self::sequence($s, $i, $literals, true);
                continue;
            }

            if ($c === '"') {
                $out[] = self::quoted($s, $i);
                continue;
            }

            if ($c === self::MARK) {
                $end = strpos($s, self::MARK, $i + 1);
                $index = (int) substr($s, $i + 1, $end - $i - 1);
                $out[] = $literals[$index] ?? '';
                $i = $end + 1;
                continue;
            }

            $atom = self::atom($s, $i);
            $out[] = strtoupper($atom) === 'NIL' ? null : $atom;
        }

        return $out;
    }

    private static function quoted(string $s, int &$i): string
    {
        $i++; // opening quote
        $out = '';
        $n = strlen($s);

        while ($i < $n) {
            $c = $s[$i];

            if ($c === '\\' && $i + 1 < $n) {
                $out .= $s[$i + 1];
                $i += 2;
                continue;
            }

            if ($c === '"') {
                $i++;
                return $out;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * An atom, including any [section] — BODY[HEADER.FIELDS (FROM TO)] is
     * one token even though it contains spaces and parentheses.
     */
    private static function atom(string $s, int &$i): string
    {
        $start = $i;
        $n = strlen($s);
        $depth = 0;

        while ($i < $n) {
            $c = $s[$i];

            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
            } elseif ($depth === 0 && ($c === ' ' || $c === '(' || $c === ')' || $c === self::MARK)) {
                break;
            }

            $i++;
        }

        return substr($s, $start, $i - $start);
    }
}
