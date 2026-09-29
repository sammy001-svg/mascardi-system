<?php
/**
 * Daily pass for leads nobody has contacted.
 *
 * Run once a day, in the morning, so a message lands at an hour a person would
 * plausibly have sent it:
 *
 *     0 9 * * * php /path/to/modules/crm/cron_nudge.php
 *
 * Safe to run more often than that: every guard lives in nudgeSweep(), so a
 * second pass on the same day finds everybody inside their cooldown and sends
 * nothing.
 *
 * Flags, for a first run on a database with a year of history in it:
 *
 *     --dry          say what would be sent and send none of it
 *     --cap=N        stop after N leads this pass (default from settings)
 *     --days=N       treat silence as N days rather than the configured 14
 *
 * The first run is the one worth rehearsing. A yard switching this on has
 * leads going back years, and all of them are overdue by definition.
 */

define('RUNNING_CRON', true);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_nudge.php';

$argvv = $argv ?? [];
$dry   = in_array('--dry', $argvv, true);
$cap   = null;
$days  = null;
foreach ($argvv as $a) {
    if (str_starts_with($a, '--cap='))  $cap  = max(1, (int)substr($a, 6));
    if (str_starts_with($a, '--days=')) $days = max(1, (int)substr($a, 7));
}

$db = getDB();

$c = nudgeConf();
if ($days !== null) $c['days'] = $days;

echo "Lead follow-up sweep" . ($dry ? " (dry run — nothing will be sent)" : "") . "\n";
echo str_repeat('-', 58) . "\n";

if (!$c['on']) {
    echo "  Switched off (lead_nudge_enabled). Nothing to do.\n";
    exit(0);
}

printf("  Silence counted after %d days, one message then %d days' rest, %d per run.\n",
       $c['days'], $c['cooldown'], $cap ?? $c['cap']);
printf("  Channels: %s\n", implode(' and ', array_keys(array_filter(
       ['WhatsApp' => $c['wa'], 'email' => $c['email']]))) ?: 'none enabled');
echo "\n";

$r = nudgeSweep($db, $dry, $cap, $days);

foreach ($r['rows'] as $row) {
    printf("  %-28s  whatsapp: %-11s email: %s\n",
        mb_substr((string)$row['name'], 0, 28), $row['whatsapp'], $row['email']);
}
if (!$r['rows']) echo "  Nobody is due.\n";

echo "\n" . str_repeat('-', 58) . "\n";
printf("  %d considered, %d still resting, %d %s, %d with nothing that worked.\n",
    $r['considered'], $r['cooling'], $r['sent'],
    $dry ? 'would be contacted' : 'contacted', $r['failed']);

exit(0);
