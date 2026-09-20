<?php
/**
 * sendWhatsApp() — the one line the rest of the system uses to reach a customer.
 *
 * This used to be a Twilio client, sitting alongside a half-built QR bridge in
 * modules/whatsapp and a scattering of wa.me links, so the yard had three
 * WhatsApp systems and no WhatsApp. It is now a thin front on the single
 * connection configured in modules/whatsapp/connect.php, whichever provider that
 * turns out to be.
 *
 * The signature has not changed, because it is called from job completion, from
 * a completed sale and from the settings test page, and breaking those to tidy
 * this up would be a poor trade.
 *
 * What did change is that these messages are now recorded. An automatic
 * "your car is ready" used to vanish the moment it was sent; it now lands in the
 * customer's thread where the person who takes their call can see it was sent,
 * when, and whether it actually arrived.
 */

require_once __DIR__ . '/../modules/whatsapp/_wa.php';

/**
 * @param  string $to      any way a Kenyan number gets written
 * @param  string $message the text, WhatsApp's *bold* markup allowed
 * @param  string $refType what this is about — 'job', 'sale', 'test'
 * @param  int    $refId   the record it is about
 * @return array{ok:bool, error:string, message_id?:int}
 */
function sendWhatsApp(string $to, string $message, string $refType = '', int $refId = 0): array
{
    $chatId = waChatId($to);
    if ($chatId === null) {
        return ['ok' => false, 'error' => 'That is not a usable phone number.'];
    }
    if (!waConfigured()) {
        return ['ok' => false, 'error' => 'WhatsApp is not connected. '
                                        . 'An administrator can link it under WhatsApp Setup.'];
    }

    try {
        $db = getDB();
        waMigrate($db);

        // A thread, so the automatic message sits with everything else that has
        // been said to this person rather than disappearing into a provider log.
        $conv = waConversation($db, $chatId, '', waChatPhone($chatId));
        if (!$conv) return ['ok' => false, 'error' => 'That conversation could not be opened.'];

        // Sent by the system, not by whoever happened to click the button that
        // triggered it — the audit line below records the person.
        $r = waSendText($db, (int)$conv['id'], $message, null);

        if ($refType !== '') {
            try {
                logActivity($r['ok'] ? 'create' : 'blocked', 'wa_messages', $r['message_id'],
                    'Automatic WhatsApp (' . $refType . ($refId ? ' #' . $refId : '') . ') '
                    . ($r['ok'] ? 'sent to ' : 'failed for ') . $chatId
                    . ($r['ok'] ? '.' : ': ' . $r['error']));
            } catch (\Throwable $_) {}
        }
        return ['ok' => $r['ok'], 'error' => $r['error'], 'message_id' => $r['message_id']];
    } catch (\Throwable $e) {
        // Never break the page that was doing something more important — closing
        // a job card, completing a sale — because a notification could not go out.
        error_log('sendWhatsApp: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The message could not be sent.'];
    }
}

/** Whether there is any point offering to send. */
function whatsappEnabled(): bool
{
    return waConfigured();
}
