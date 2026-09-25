<?php

namespace Mascardi\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Makes an incoming HTML email safe to show inside the system.
 *
 * An email is written by a stranger, and shown inside a page that is
 * signed in as a member of staff. So this is one of three layers, and not
 * trusted to be the only one:
 *
 *   1. Here: scripts, frames, forms, event handlers and javascript: links
 *      are removed, and remote images are held back until asked for.
 *   2. The HTML is served from its own address with a Content-Security-
 *      Policy that forbids every script and every network request except
 *      the images the reader has allowed.
 *   3. It is shown in a sandboxed iframe with no script permission and no
 *      access to the page around it.
 *
 * Remote images are held back because loading one tells the sender that
 * the message was opened, when, and from where — which is exactly what
 * tracking pixels are for.
 */
final class HtmlSanitizer
{
    private const DROP = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'textarea', 'option',
        'meta', 'link', 'base', 'noscript', 'template', 'svg', 'math',
    ];

    private const URL_ATTRS = ['href', 'src', 'background', 'action', 'poster', 'cite', 'formaction', 'xlink:href'];

    /**
     * @param array<string,string> $cidMap  Content-ID => data: URI for inline images
     * @return array{html:string, blocked:int}
     */
    public static function clean(string $html, bool $allowRemote, array $cidMap = []): array
    {
        $blocked = 0;

        if (trim($html) === '') {
            return ['html' => '', 'blocked' => 0];
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);

        // The XML declaration makes libxml read the input as UTF-8 rather
        // than guessing Latin-1 and mangling every accented letter.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new DOMXPath($doc);

        foreach (self::DROP as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*')) as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                $value = trim($attr->nodeValue ?? '');

                if (str_starts_with($name, 'on') || $name === 'srcset' || $name === 'formaction') {
                    $el->removeAttribute($attr->nodeName);
                    continue;
                }

                if ($name === 'style') {
                    // url() in a style is a network request like any other.
                    $clean = preg_replace('/url\s*\([^)]*\)/i', 'none', $value) ?? '';
                    $clean = preg_replace('/expression\s*\(|behavior\s*:|-moz-binding/i', '', $clean) ?? '';
                    $el->setAttribute('style', $clean);
                    continue;
                }

                if (!in_array($name, self::URL_ATTRS, true)) {
                    continue;
                }

                $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

                if ($name === 'href') {
                    if (in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) || str_starts_with($value, '#')) {
                        continue;
                    }
                    $el->removeAttribute($attr->nodeName);
                    continue;
                }

                // src, background and the rest.
                if ($scheme === 'cid') {
                    $cid = strtolower(trim(substr($value, 4), '<>'));
                    if (isset($cidMap[$cid])) {
                        $el->setAttribute($attr->nodeName, $cidMap[$cid]);
                    } else {
                        $el->removeAttribute($attr->nodeName);
                    }
                    continue;
                }

                if ($scheme === 'data' && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $value)) {
                    continue;
                }

                if (($scheme === 'http' || $scheme === 'https') && $allowRemote) {
                    continue;
                }

                if ($scheme === 'http' || $scheme === 'https') {
                    $blocked++;
                }

                $el->removeAttribute($attr->nodeName);
            }

            // Every link opens outside the mailbox.
            if (strtolower($el->nodeName) === 'a' && $el->hasAttribute('href')) {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }

        // <style> blocks are kept for the layout — the served page's policy
        // stops any url() in them loading — but @import is removed outright.
        foreach (iterator_to_array($doc->getElementsByTagName('style')) as $style) {
            $style->nodeValue = preg_replace('/@import[^;]+;?/i', '', $style->nodeValue ?? '') ?? '';
            if (!$allowRemote) {
                $style->nodeValue = preg_replace('/url\s*\([^)]*\)/i', 'none', $style->nodeValue) ?? '';
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $out = '';

        if ($body) {
            foreach ($body->childNodes as $child) {
                $out .= $doc->saveHTML($child);
            }
        }

        $head = '';
        foreach (iterator_to_array($doc->getElementsByTagName('style')) as $style) {
            if ($style->parentNode && strtolower($style->parentNode->nodeName) === 'head') {
                $head .= $doc->saveHTML($style);
            }
        }

        return ['html' => $head . $out, 'blocked' => $blocked];
    }

    /** A plain-text message, shown as text: escaped, links made clickable. */
    public static function fromText(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $safe = preg_replace(
            '#\bhttps?://[^\s<>"\']+#i',
            '<a href="$0" target="_blank" rel="noopener noreferrer nofollow">$0</a>',
            $safe
        ) ?? $safe;

        return '<pre style="white-space:pre-wrap;font-family:inherit;margin:0">' . $safe . '</pre>';
    }
}
