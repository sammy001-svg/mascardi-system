<?php
/**
 * Carl — the navbar button and her panel.
 *
 * Included from includes/header.php, immediately before the notification bell.
 *
 * Voice is browser-native: SpeechSynthesis to speak, SpeechRecognition to
 * listen. No audio ever leaves the machine and there is no service to pay for or
 * configure — but recognition is Chrome and Edge only, so the microphone is
 * shown only where it works and typing is always available.
 *
 * Speaking is off until the person turns it on, and the choice is remembered.
 * An assistant that starts talking out loud in a shared office the first time
 * someone opens the system is one they switch off and never switch back on.
 */
if (!defined('CARL_WIDGET')) {
    define('CARL_WIDGET', true);
    if (authRole() === 'visitor_book') return;   // public kiosk: no assistant
?>
<style>
/* The shared .topbar-icon-btn is transparent with a grey icon, which left Carl
   invisible against the navbar. She is the one control here that is not a
   utility icon, so she gets her own colour rather than borrowing the set. */
.carl-btn{
    position:relative; width:auto; padding:0 13px 0 10px; gap:8px;
    background:linear-gradient(135deg,#a855f7,#7c3aed) !important;
    border-color:transparent !important; color:#fff !important;
    box-shadow:0 2px 10px rgba(124,58,237,.34);
    font-size:12.5px; font-weight:700; letter-spacing:.01em;
}
.carl-btn:hover{
    background:linear-gradient(135deg,#9333ea,#6d28d9) !important; color:#fff !important;
    box-shadow:0 4px 16px rgba(124,58,237,.46);
}
.carl-btn i{ font-size:13.5px; }
.carl-btn .carl-label{ line-height:1; }
@media(max-width:640px){ .carl-btn{ padding:0; width:36px; } .carl-btn .carl-label{ display:none; } }

.carl-btn .carl-dot{
    position:absolute; top:-4px; right:-4px; min-width:16px; height:16px; border-radius:10px; padding:0 4px;
    background:#ef4444; color:#fff; font-size:9.5px; font-weight:800; line-height:16px; text-align:center;
    box-shadow:0 0 0 2px var(--carl-surface); display:none; justify-content:center; align-items:center;
}
.carl-btn.has-news .carl-dot{ display:flex; animation:carlPulse 2s infinite; }
@keyframes carlPulse{ 0%,100%{opacity:1} 50%{opacity:.35} }
/* On dark backgrounds the halo ring must match the bar, not stay white. */
[data-theme="dark"] .carl-btn .carl-dot{ box-shadow:0 0 0 2px var(--surface,#0f172a); }

/* Carl's own palette.
   Declared here with concrete values rather than leaning on the host theme, so
   the card is solid on every page whatever --surface happens to resolve to.
   A page that forgets to define the theme variables still gets an opaque card
   rather than one you can read the dashboard through. */
.carl-panel{
    --carl-surface:     #ffffff;
    --carl-surface-alt: #f5f6fa;
    --carl-border:      #e2e8f0;
    --carl-text:        #0f172a;
    --carl-text-2:      #64748b;
}
[data-theme="dark"] .carl-panel{
    --carl-surface:     #131c2e;
    --carl-surface-alt: #1c2740;
    --carl-border:      #2a3442;
    --carl-text:        #e8eaed;
    --carl-text-2:      #94a3b8;
}

/* Drops from the top, anchored under the button it belongs to, rather than
   sliding in from the edge — a side drawer reads as a separate place you have
   navigated to, where this should read as the button opening. */
.carl-panel{
    position:fixed; top:68px; right:18px;
    width:420px; max-width:calc(100vw - 36px);
    height:auto; max-height:min(76vh, 720px);
    background:var(--carl-surface); color:var(--carl-text);
    border:1px solid var(--carl-border); border-radius:16px;
    box-shadow:0 24px 64px rgba(2,6,23,.30), 0 3px 10px rgba(2,6,23,.14);
    z-index:1090; overflow:hidden;
    display:flex; flex-direction:column;
    transform-origin:top right;
    transform:translateY(-14px) scale(.96);
    opacity:0; visibility:hidden;
    transition:transform .22s cubic-bezier(.2,.8,.2,1),
               opacity   .16s ease,
               visibility 0s linear .22s;
}
.carl-panel.open{
    transform:translateY(0) scale(1);
    opacity:1; visibility:visible;
    transition:transform .24s cubic-bezier(.2,.8,.2,1),
               opacity   .16s ease,
               visibility 0s;
}
/* Someone who prefers less motion still gets the card, just without the travel. */
@media (prefers-reduced-motion: reduce){
    .carl-panel, .carl-panel.open{ transition:opacity .12s ease, visibility 0s; transform:none; }
}
.carl-backdrop{
    position:fixed; inset:0; background:rgba(2,6,23,.28); z-index:1089;
    opacity:0; visibility:hidden; transition:opacity .2s;
}
.carl-backdrop.open{ opacity:1; visibility:visible; }

.carl-head{
    background:var(--carl-surface); border-bottom:1px solid var(--carl-border);
    padding:16px 18px;
    display:flex; align-items:center; gap:12px; flex:0 0 auto;
}
.carl-ava{
    width:42px; height:42px; border-radius:50%; flex:0 0 42px;
    background:linear-gradient(135deg,#a855f7,#6d28d9); color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:17px; font-weight:800;
}
.carl-who{ flex:1; min-width:0; }
.carl-who b{ font-size:15px; display:block; letter-spacing:-.2px; }
.carl-who span{ font-size:11.5px; color:var(--carl-text-2); display:flex; align-items:center; gap:5px; }
.carl-who span i{ font-size:6px; color:#16a34a; }
.carl-head-btn{
    background:transparent; border:1px solid var(--carl-border); color:var(--carl-text-2);
    width:34px; height:34px; border-radius:9px; cursor:pointer; font-size:13px;
}
.carl-head-btn:hover{ border-color:#a855f7; color:#a855f7; }
.carl-head-btn.on{ background:#a855f7; border-color:#a855f7; color:#fff; }

.carl-body{ flex:1; overflow-y:auto; padding:18px; background:var(--carl-surface); }
.carl-msg{ margin-bottom:16px; }
.carl-msg .bubble{
    display:inline-block; max-width:92%; padding:11px 14px; border-radius:14px;
    font-size:13.8px; line-height:1.62; white-space:pre-wrap;
}
.carl-msg.from-carl .bubble{ background:var(--carl-surface-alt); border-bottom-left-radius:4px; }
.carl-msg.from-user{ text-align:right; }
.carl-msg.from-user .bubble{
    background:linear-gradient(135deg,#a855f7,#7c3aed); color:#fff; border-bottom-right-radius:4px;
}
.carl-rich{ margin-top:10px; }

.carl-tiles{ display:grid; grid-template-columns:repeat(auto-fit,minmax(96px,1fr)); gap:8px; margin:4px 0 10px; }
.carl-tile{ background:var(--carl-surface-alt); border:1px solid var(--carl-border);
    border-radius:10px; padding:11px 10px; text-align:center; }
.carl-tile .v{ font-size:17px; font-weight:800; letter-spacing:-.4px; }
.carl-tile .k{ font-size:10.5px; color:var(--carl-text-2); margin-top:2px; }
.carl-tile.carl-good .v{ color:#16a34a; }
.carl-tile.carl-warn .v{ color:#b45309; }
.carl-tile.carl-bad  .v{ color:#dc2626; }

.carl-act{
    display:flex; align-items:center; gap:9px; padding:10px 12px; margin-top:7px;
    border:1px solid var(--carl-border); border-radius:10px; font-size:13px;
    font-weight:600; text-decoration:none; color:var(--carl-text); background:var(--carl-surface);
}
.carl-act:hover{ border-color:#a855f7; color:#a855f7; }
.carl-act i{ color:#a855f7; }

.carl-chips{ display:flex; flex-wrap:wrap; gap:7px; margin:8px 0 4px; }
.carl-chip{
    background:var(--carl-surface-alt); border:1px solid var(--carl-border);
    border-radius:20px; padding:7px 13px; font-size:12.5px; font-weight:600;
    cursor:pointer; color:var(--carl-text);
}
.carl-chip:hover{ border-color:#a855f7; color:#a855f7; }
.carl-chip.carl-yes{ background:#a855f7; border-color:#a855f7; color:#fff; }

.carl-p{ font-size:13.5px; margin:0 0 8px; }
.carl-note{ font-size:11.5px; color:var(--carl-text-2); margin:8px 0 0; }
.carl-list{ margin:6px 0 8px; }
.carl-row{ display:flex; justify-content:space-between; font-size:12.5px; padding:5px 2px;
    border-bottom:1px solid var(--carl-border); }
.carl-row:last-child{ border-bottom:0; }
.carl-flag{ display:flex; gap:9px; align-items:flex-start; background:#fff7ed; border:1px solid #fed7aa;
    color:#9a3412; border-radius:10px; padding:10px 12px; font-size:12.5px; margin:2px 0 8px; }
.carl-advice{ border:1px solid var(--carl-border); border-left-width:3px; border-radius:10px;
    padding:11px 13px; margin-bottom:9px; }
.carl-advice.carl-bad{ border-left-color:#dc2626; }
.carl-advice.carl-warn{ border-left-color:#f59e0b; }
.carl-advice.carl-good{ border-left-color:#16a34a; }
.carl-advice .t{ font-size:13px; font-weight:700; }
.carl-advice .w{ font-size:12px; color:var(--carl-text-2); margin:3px 0 6px; }
/* Real records behind a figure — names, owners, dates. */
.carl-recs{ margin:6px 0 10px; }
.carl-rec{
    border:1px solid var(--carl-border); border-radius:10px;
    padding:10px 12px; margin-bottom:8px; background:var(--carl-surface);
}
.carl-rec-t{ font-size:13.5px; font-weight:700; margin-bottom:6px; }
.carl-rec-t a{ color:#a855f7; text-decoration:none; }
.carl-rec-t a:hover{ text-decoration:underline; }
.carl-rec-f{ display:flex; justify-content:space-between; gap:12px; font-size:12px; padding:2px 0; }
.carl-rec-f span{ color:var(--carl-text-2); flex:0 0 auto; }
.carl-rec-f b{ font-weight:600; text-align:right; word-break:break-word; }
.carl-rec-f b.carl-t-bad{ color:#dc2626; }
.carl-rec-f b.carl-t-warn{ color:#b45309; }
/* Cards that are themselves the link — a document to print, a deal to open.
   The greyed variant is a span, not an anchor, so a document that is not yet
   available cannot be clicked at all rather than opening and failing. */
a.carl-rec, span.carl-rec{ display:block; text-decoration:none; color:inherit; }
a.carl-rec{ transition:border-color .15s, background .15s; }
a.carl-rec:hover{ border-color:#a855f7; background:var(--carl-surface-alt); }
.carl-rec > b{ display:block; font-size:13.5px; font-weight:700; color:var(--carl-text); }
.carl-rec > b i{ width:16px; margin-right:6px; color:#a855f7; }
.carl-rec > span{ display:block; font-size:12px; color:var(--carl-text-2); margin-top:2px; }
.carl-rec > em{ display:block; font-size:11.5px; font-style:normal; color:#b45309; margin-top:4px; }
a.carl-rec > em{ color:#7c3aed; }
.carl-rec.is-off{ opacity:.55; }
.carl-rec.is-off > b{ font-weight:600; }
.carl-rec.is-off > em{ color:var(--carl-text-2); }

.carl-notice{ margin:8px 0 4px; padding:9px 12px; border-radius:9px; font-size:12.5px;
    line-height:1.6; background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
.carl-confirm{ background:var(--carl-surface-alt); border-radius:10px; padding:11px 13px; margin:4px 0; }
.carl-confirm .r{ display:flex; justify-content:space-between; font-size:13px; padding:3px 0; }
.carl-ok{ display:flex; align-items:center; gap:8px; background:#f0fdf4; border:1px solid #bbf7d0;
    color:#15803d; border-radius:10px; padding:10px 12px; font-size:13px; font-weight:600; }

.carl-foot{ border-top:1px solid var(--carl-border); padding:12px 14px; flex:0 0 auto;
           background:var(--carl-surface); }
.carl-input{ display:flex; gap:8px; align-items:flex-end; }
.carl-input textarea{
    flex:1; resize:none; border:1px solid var(--carl-border); border-radius:11px;
    padding:10px 12px; font-size:13.5px; font-family:inherit; max-height:110px;
    background:var(--carl-surface); color:var(--carl-text);
}
.carl-input textarea:focus{ outline:none; border-color:#a855f7; }
.carl-send, .carl-mic{
    width:40px; height:40px; border-radius:11px; border:0; cursor:pointer; flex:0 0 40px;
    display:flex; align-items:center; justify-content:center; font-size:14px;
}
.carl-send{ background:linear-gradient(135deg,#a855f7,#7c3aed); color:#fff; }
.carl-mic{ background:var(--carl-surface-alt); color:var(--carl-text-2);
    border:1px solid var(--carl-border); }
.carl-mic.listening{ background:#dc2626; color:#fff; border-color:#dc2626; animation:carlPulse 1.2s infinite; }
.carl-typing{ display:flex; gap:4px; padding:11px 14px; }
.carl-typing i{ width:6px; height:6px; border-radius:50%; background:var(--carl-text-2);
    animation:carlBounce 1.3s infinite; }
.carl-typing i:nth-child(2){ animation-delay:.18s } .carl-typing i:nth-child(3){ animation-delay:.36s }
@keyframes carlBounce{ 0%,60%,100%{transform:translateY(0);opacity:.4} 30%{transform:translateY(-5px);opacity:1} }
/* Typewriter cursor — a blinking bar after the last character. */
.carl-cursor{ display:inline-block; width:2px; height:1em; background:currentColor;
    vertical-align:text-bottom; margin-left:1px;
    animation:carlBlink .7s step-start infinite; }
@keyframes carlBlink{ 0%,100%{opacity:1} 50%{opacity:0} }
@media(max-width:520px){
    .carl-panel{ top:60px; right:10px; left:10px; width:auto; max-width:none;
                 max-height:calc(100vh - 78px); }
}
</style>

<button type="button" class="topbar-icon-btn carl-btn" id="carlBtn"
        title="Ask <?= e(CARL_NAME) ?>" aria-label="Ask <?= e(CARL_NAME) ?>">
    <i class="fa fa-wand-magic-sparkles"></i>
    <span class="carl-label"><?= e(CARL_NAME) ?></span>
    <span class="carl-dot"></span>
</button>

<div class="carl-backdrop" id="carlBackdrop"></div>
<aside class="carl-panel" id="carlPanel" aria-label="<?= e(CARL_NAME) ?>, your assistant">
    <div class="carl-head">
        <div class="carl-ava"><?= e(strtoupper(substr(CARL_NAME, 0, 1))) ?></div>
        <div class="carl-who">
            <b><?= e(CARL_NAME) ?></b>
            <span><i class="fa fa-circle"></i>Here to help</span>
        </div>
        <button type="button" class="carl-head-btn" id="carlVoiceToggle"
                title="Read answers aloud"><i class="fa fa-volume-xmark"></i></button>
        <button type="button" class="carl-head-btn" id="carlClose" title="Close"><i class="fa fa-xmark"></i></button>
    </div>

    <div class="carl-body" id="carlBody"></div>

    <div class="carl-foot">
        <div class="carl-input">
            <button type="button" class="carl-mic" id="carlMic" title="Speak to Carl" style="display:none">
                <i class="fa fa-microphone"></i>
            </button>
            <textarea id="carlText" rows="1" placeholder="Ask <?= e(CARL_NAME) ?> anything…"></textarea>
            <button type="button" class="carl-send" id="carlSend" title="Send"><i class="fa fa-paper-plane"></i></button>
        </div>
    </div>
</aside>

<script>
(function () {
    'use strict';
    var API   = '<?= BASE_URL ?>/modules/carl/api/ask.php';
    var NAME  = <?= json_encode(CARL_NAME) ?>;
    var panel = document.getElementById('carlPanel');
    var back  = document.getElementById('carlBackdrop');
    var body  = document.getElementById('carlBody');
    var text  = document.getElementById('carlText');
    var micBtn = document.getElementById('carlMic');
    var vBtn  = document.getElementById('carlVoiceToggle');
    var btn   = document.getElementById('carlBtn');
    var loaded = false, busy = false;

    // Every authenticated POST is CSRF-checked. The token is published in a meta
    // tag by the header — read it from there rather than assuming a global.
    var CSRF = (function () {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }());

    // ── Speaking ────────────────────────────────────────────────────────────
    // Off by default and remembered per browser. See the note at the top of this
    // file: an assistant that speaks unprompted in a shared office gets muted
    // permanently on day one.
    var canSpeak = 'speechSynthesis' in window;
    var speakOn  = false;
    try { speakOn = localStorage.getItem('carlVoice') === 'on'; } catch (e) {}

    function paintVoice() {
        vBtn.classList.toggle('on', speakOn);
        vBtn.innerHTML = '<i class="fa fa-volume-' + (speakOn ? 'high' : 'xmark') + '"></i>';
        vBtn.title = speakOn ? 'Stop reading answers aloud' : 'Read answers aloud';
    }
    if (!canSpeak) vBtn.style.display = 'none'; else paintVoice();

    vBtn.addEventListener('click', function () {
        speakOn = !speakOn;
        try { localStorage.setItem('carlVoice', speakOn ? 'on' : 'off'); } catch (e) {}
        if (!speakOn) window.speechSynthesis.cancel();
        paintVoice();
    });

    function speak(t) {
        if (!speakOn || !canSpeak || !t) return;
        try {
            window.speechSynthesis.cancel();
            var u = new SpeechSynthesisUtterance(t);
            u.rate = 1.02; u.pitch = 1.05; u.lang = 'en-GB';
            // Prefer a British English voice where the device has one, so she
            // sounds consistent rather than whatever the machine defaults to.
            var vs = window.speechSynthesis.getVoices() || [];
            var pick = vs.find(function (v) { return /en-GB/i.test(v.lang) && /female|Google UK English Female|Serena|Kate/i.test(v.name); })
                    || vs.find(function (v) { return /en-GB/i.test(v.lang); })
                    || vs.find(function (v) { return /^en/i.test(v.lang); });
            if (pick) u.voice = pick;
            window.speechSynthesis.speak(u);
        } catch (e) {}
    }
    if (canSpeak && window.speechSynthesis.onvoiceschanged !== undefined) {
        window.speechSynthesis.onvoiceschanged = function () {};   // populates getVoices()
    }

    // ── Listening ───────────────────────────────────────────────────────────
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    var rec = null, listening = false;
    if (SR) {
        micBtn.style.display = '';
        rec = new SR();
        rec.lang = 'en-GB'; rec.interimResults = false; rec.maxAlternatives = 1;
        rec.onresult = function (e) {
            var said = e.results[0][0].transcript;
            text.value = said;
            send();
        };
        rec.onend = function () { listening = false; micBtn.classList.remove('listening'); };
        rec.onerror = rec.onend;
        micBtn.addEventListener('click', function () {
            if (listening) { rec.stop(); return; }
            try { rec.start(); listening = true; micBtn.classList.add('listening'); } catch (e) {}
        });
    }

    // ── Rendering ───────────────────────────────────────────────────────────
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /**
     * Append a message bubble to the chat body.
     *
     * @param {string}   role      'carl' | 'user'
     * @param {string}   said      Plain-text content of the bubble.
     * @param {string}   html      Optional rich panel below the bubble.
     * @param {boolean}  animate   If true (Carl messages only), reveal the text
     *                             character-by-character like it is being typed.
     * @param {Function} onDone    Called when the message is fully rendered.
     */
    function add(role, said, html, animate, onDone) {
        var d = document.createElement('div');
        d.className = 'carl-msg from-' + role;

        var bubble = document.createElement('div');
        bubble.className = 'bubble';
        d.appendChild(bubble);

        var richDiv = null;
        if (html) {
            richDiv = document.createElement('div');
            richDiv.className = 'carl-rich';
            // Rich HTML only appears after typing is done — inserting it early
            // makes the panel jump while the bubble is still being written.
            d.appendChild(richDiv);
        }

        body.appendChild(d);
        body.scrollTop = body.scrollHeight;

        if (!animate || role !== 'carl' || !said) {
            // Instant render for user messages and history replay.
            bubble.textContent = said || '';
            if (richDiv) richDiv.innerHTML = html;
            body.scrollTop = body.scrollHeight;
            if (onDone) onDone();
            return d;
        }

        // ── Typewriter ────────────────────────────────────────────────────────
        // The whole reply is typed, start to finish. This used to print the
        // first 80 characters and dump the remainder at once, which is worse
        // than not animating at all: the eye starts following along and is then
        // overtaken by a wall of text.
        //
        // The rate adapts instead. Short replies are typed at a comfortable
        // reading pace; long ones speed up so the whole message still lands
        // within a few seconds. Nobody waits twenty seconds to be told a number.
        var chars = Array.from(said);            // multi-byte safe
        var TARGET = Math.min(5200, Math.max(900, chars.length * 17));
        var perFrame = Math.max(1, chars.length / (TARGET / 16.7));   // chars per ~60fps frame

        var cursor = document.createElement('span');
        cursor.className = 'carl-cursor';
        bubble.appendChild(cursor);

        var i = 0, raf = null, finished = false;

        function finish() {
            if (finished) return;
            finished = true;
            if (raf) cancelAnimationFrame(raf);
            // Whatever has not been printed yet goes in now.
            if (i < chars.length) cursor.insertAdjacentText('beforebegin', chars.slice(i).join(''));
            i = chars.length;
            cursor.remove();
            d.removeEventListener('click', finish);
            // The card appears once the sentence has finished, so the panel does
            // not jump while the bubble is still growing.
            if (richDiv) { richDiv.innerHTML = html; }
            body.scrollTop = body.scrollHeight;
            if (onDone) onDone();
        }

        function step() {
            var take = Math.ceil(perFrame);
            if (i < chars.length) {
                cursor.insertAdjacentText('beforebegin', chars.slice(i, i + take).join(''));
                i += take;
                // Only follow the text down if the reader has not scrolled up to
                // re-read something — yanking them back is infuriating.
                if (body.scrollHeight - body.scrollTop - body.clientHeight < 80) {
                    body.scrollTop = body.scrollHeight;
                }
                raf = requestAnimationFrame(step);
            } else {
                finish();
            }
        }

        // Impatience is legitimate: tapping the message prints the rest at once.
        d.addEventListener('click', finish);
        d.__carlFinish = finish;      // so a new question can cut the previous reply short

        raf = requestAnimationFrame(step);
        return d;
    }

    function thinking(on) {
        var t = document.getElementById('carlThinking');
        if (!on) { if (t) t.remove(); return; }
        if (t) return;
        var d = document.createElement('div');
        d.id = 'carlThinking'; d.className = 'carl-msg from-carl';
        d.innerHTML = '<div class="bubble carl-typing"><i></i><i></i><i></i></div>';
        body.appendChild(d); body.scrollTop = body.scrollHeight;
    }

    // ── Talking to Carl ─────────────────────────────────────────────────────
    function send(preset) {
        var msg = (preset != null ? preset : text.value).trim();
        if (!msg || busy) return;
        busy = true;

        // If Carl is still typing the last answer, print it in full before the
        // new question goes up — a half-finished sentence stranded above a new
        // one reads like she was interrupted and lost her place.
        var last = body.querySelector('.carl-msg.from-carl:last-child');
        if (last && typeof last.__carlFinish === 'function') last.__carlFinish();

        add('user', msg, '', false, null);
        text.value = ''; text.style.height = 'auto';
        thinking(true);

        fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ message: msg })
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
            thinking(false);
            if (!j || !j.ok) {
                busy = false;
                add('carl', 'Sorry — something went wrong at my end. Try again?', '', false, null);
                return;
            }
            // Speech starts WITH the typing, not after it. Waiting for the
            // sentence to finish printing and only then hearing it read out
            // makes Carl feel slow and repeats what has just been read.
            speak(j.say);
            add('carl', j.say, j.html, true, function () {
                busy = false;
                // Navigation is delayed so Carl finishes her sentence before the
                // page changes under the person reading it.
                if (j.go) setTimeout(function () { window.location.href = j.go; }, speakOn ? 1400 : 400);
            });
        })
        .catch(function () {
            thinking(false); busy = false;
            add('carl', 'I could not reach the system just then. Check your connection and try again.', '', false, null);
        });
    }

    // Suggestion chips and Carl's own action buttons.
    body.addEventListener('click', function (e) {
        var chip = e.target.closest ? e.target.closest('[data-ask]') : null;
        if (chip) { e.preventDefault(); send(chip.dataset.ask); }
    });

    document.getElementById('carlSend').addEventListener('click', function () { send(); });
    text.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    text.addEventListener('input', function () {
        text.style.height = 'auto';
        text.style.height = Math.min(text.scrollHeight, 110) + 'px';
    });

    // ── Opening and closing ─────────────────────────────────────────────────
    function open() {
        panel.classList.add('open'); back.classList.add('open');
        btn.classList.remove('has-news');
        if (!loaded) { loaded = true; load(false); }
        setTimeout(function () { text.focus(); }, 260);
    }
    function close() {
        panel.classList.remove('open'); back.classList.remove('open');
        if (canSpeak) window.speechSynthesis.cancel();
        if (listening && rec) rec.stop();
    }
    btn.addEventListener('click', function () {
        panel.classList.contains('open') ? close() : open();
    });
    document.getElementById('carlClose').addEventListener('click', close);
    back.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('open')) close();
    });

    function load(greet) {
        fetch(API + (greet ? '?greet=1' : ''), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.ok) return;
                if (!body.childElementCount) {
                    // History replayed instantly — no typewriter for old messages.
                    (j.history || []).forEach(function (m) {
                        add(m.role === 'user' ? 'user' : 'carl', m.body, m.html || '', false, null);
                    });
                }
                if (j.greeting) {
                    speak(j.greeting.say);
                    add('carl', j.greeting.say, j.greeting.html, true, null);
                }
                if (!body.childElementCount) {
                    add('carl', 'Hello. I am ' + NAME + '. Ask me for a briefing, or say \u201chelp\u201d '
                              + 'to see what I can do.', '', true, null);
                }
                // Shown to managers only, and only while something is actually wrong.
                // Carl still answers; this explains why she is answering plainly.
                if (j.notice) {
                    var n = document.createElement('div');
                    n.className = 'carl-notice';
                    n.textContent = j.notice;
                    body.appendChild(n);
                    body.scrollTop = body.scrollHeight;
                }
            })
            .catch(function () {});
    }

    // ── The morning greeting ────────────────────────────────────────────────
    // Asked for once per page load; the server decides whether one is actually
    // due, so opening five tabs does not produce five greetings.
    (function greetIfDue() {
        fetch(API + '?greet=1', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.ok || !j.greeting) return;
                loaded = true;
                (j.history || []).forEach(function (m) {
                    if (m.skill !== 'greeting') add(m.role === 'user' ? 'user' : 'carl', m.body, m.html || '', false, null);
                });
                // Typewriter + voice for the proactive greeting.
                add('carl', j.greeting.say, j.greeting.html, true, function () {
                    speak(j.greeting.say);
                });
                btn.classList.add('has-news');
                // The panel opens itself only for the greeting.
                setTimeout(function () { open(); }, 900);
            })
            .catch(function () {});
    }());

    // ── Proactive alert polling ─────────────────────────────────────────────
    (function checkAlerts() {
        var ALERTS_API = '<?= BASE_URL ?>/modules/carl/api/alerts.php';
        fetch(ALERTS_API, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (j && j.ok && j.count > 0) {
                    btn.classList.add('has-news');
                    var dot = btn.querySelector('.carl-dot');
                    if (dot) {
                        dot.textContent = j.count > 9 ? '9+' : j.count;
                    }
                }
            })
            .catch(function () {});
    }());
}());
</script>
<?php } ?>
