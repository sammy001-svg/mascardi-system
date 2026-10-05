<?php
/**
 * What is left of the old finance figures.
 *
 * This file used to be fifteen functions and four hundred lines: cash flow for
 * a period, money by payment method, the receivables ledger, overdue invoices,
 * instalment arrears, deposits held, supplier commitments, unconfirmed
 * payments, twelve months of in and out, expense composition, sparklines,
 * period-over-period deltas and a words-for-a-date-range helper. All of it fed
 * one screen: the old finance dashboard, which answered every question finance
 * might have and so was read closely by nobody.
 *
 * That dashboard is now four figures and four pictures, all about the credit
 * book, and its numbers come from _dash.php. Thirteen of the fifteen functions
 * here had no caller left anywhere in the system. They have gone rather than
 * been left in place: three hundred and seventy lines of unreachable SQL is not
 * neutral — it is thirteen queries that still have to be kept working against
 * the schema, and the next person to read them cannot tell they are dead.
 *
 * Git has them if the long dashboard is ever wanted back.
 *
 * What remains is the one helper the other finance screens still use. The
 * second survivor, finRowsSafe(), already lives in _credit.php beside the
 * queries it guards, so it is not repeated here.
 */

/**
 * Money, shortened for a tile: 1.25M, 840K, 320.
 *
 * Used by the receivables book, the month view, the account page and the
 * company accounts list. Two decimals on millions and one on thousands, with
 * trailing zeros trimmed, because "1.2M" and "1.25M" are both wanted and
 * "1.20M" is neither.
 */
function finShort(float $v): string
{
    $sign = $v < 0 ? '-' : '';
    $a    = abs($v);

    if ($a >= 1000000) return $sign . rtrim(rtrim(number_format($a / 1000000, 2, '.', ''), '0'), '.') . 'M';
    if ($a >= 1000)    return $sign . rtrim(rtrim(number_format($a / 1000, 1, '.', ''), '0'), '.') . 'K';

    return $sign . number_format($a);
}
