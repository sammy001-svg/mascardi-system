<?php
/**
 * Credit Payment Agreement — early-payment variants.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS FILE HOLDS CONTRACT WORDING. Everything here is printed verbatim onto a
 * document a customer signs. Do not paraphrase, tighten or "improve" any of it.
 * The wording changes on the legal side first, then it is pasted in here
 * exactly as supplied — same as the body of credit_payment_agreement.php.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The agreement has three variants. All three share the identical base document;
 * the two early-payment variants additionally insert their clauses immediately
 * after clause 3(d) (Debt Recovery), continuing the same a) b) c) d) lettering,
 * so an early-payment agreement reads 3(a)…3(d) then 3(e), 3(f)…
 *
 * To add the wording for a variant, fill its `clauses` array. Each entry is:
 *
 *     ['heading' => 'Heading as it appears in the source document:',
 *      'body'    => 'The paragraph text, exactly as supplied.']
 *
 * 'heading' may be an empty string for an unheaded paragraph. Letters are
 * assigned automatically in order, so clauses can be added or reordered without
 * renumbering anything by hand. Text is escaped on output — write plain text and
 * real characters (’ “ ” —), not HTML entities.
 *
 * A variant whose `clauses` array is still empty is deliberately BLOCKED from
 * rendering (see creditVariantReady / the guard in credit_payment_agreement.php)
 * rather than quietly printing the base document under an early-payment title.
 * An early-payment agreement missing its early-payment clauses is a contract
 * that says something different from what both parties agreed, so it must not be
 * printable at all.
 */

if (!function_exists('creditVariants')) {

/**
 * The three variants, keyed by the value passed as ?variant= on the document URL.
 * 'standard' is the default and must always remain first.
 */
function creditVariants(): array
{
    return [
        'standard' => [
            'label'   => 'Credit Agreement',
            'short'   => 'Standard',
            'title'   => 'CREDIT PAYMENT AGREEMENT',
            'icon'    => 'fa-file-signature',
            'clauses' => [],   // The base document. Intentionally empty — never fill this.
        ],

        'concession' => [
            'label'   => 'Early Payment Concession',
            'short'   => 'Concession',
            'title'   => 'CREDIT PAYMENT AGREEMENT',
            'icon'    => 'fa-handshake',
            // Filled in per deal on the credit agreement — the settlement deadline
            // and the refund are negotiated, not company-wide, so they cannot be
            // baked into the sentence.
            'requires' => [
                'concession_date'   => 'early settlement date',
                'concession_amount' => 'refund amount',
            ],
            'clauses' => [
                ['heading' => 'Early Payment Concession:',
                 'body'    => 'Should the Debtor settle the outstanding principal amount in full '
                            . 'on or before {concession_date}, the Creditor shall refund '
                            . 'Ksh {concession_amount}/- to the Debtor. Such refund may, at the '
                            . 'Creditor’s discretion, be paid directly to the Debtor or applied as a '
                            . 'deduction against the final installment'],
            ],
        ],

        'recalc' => [
            'label'   => 'Early Payment Re-calculation',
            'short'   => 'Re-calculation',
            'title'   => 'CREDIT PAYMENT AGREEMENT',
            'icon'    => 'fa-calculator',
            'clauses' => [
                // ── PASTE THE EARLY PAYMENT RE-CALCULATION CLAUSES HERE ──
            ],
        ],
    ];
}

/**
 * Resolve a ?variant= value to a definition, falling back to the standard
 * agreement for anything unrecognised.
 *
 * @return array{0:string,1:array} The canonical key and its definition.
 */
function creditVariant(?string $key): array
{
    $all = creditVariants();
    $key = strtolower(trim((string)$key));
    if ($key === '' || !isset($all[$key])) $key = 'standard';
    return [$key, $all[$key]];
}

/**
 * Formats one required value the way it has to read inside a clause.
 *
 * Returns '' when the value is absent, which is what marks the variant as not
 * yet printable — see creditVariantMissing().
 */
function creditClauseValue(string $field, array $agreement): string
{
    $raw = $agreement[$field] ?? null;
    if ($raw === null || $raw === '' || $raw === '0000-00-00') return '';

    // Dates read as they do in the source document: "30 November 2026".
    if (str_ends_with($field, '_date')) {
        $ts = strtotime((string)$raw);
        return $ts ? date('j F Y', $ts) : '';
    }
    // Money appears with thousands separators and no decimals: "200,000".
    if (str_ends_with($field, '_amount')) {
        $n = (float)$raw;
        return $n > 0 ? number_format($n, 0) : '';
    }
    return trim((string)$raw);
}

/**
 * Which of a variant's required values have not been filled in on this
 * agreement, as human-readable labels. Empty means it is ready to print.
 */
function creditVariantMissing(string $key, array $agreement = []): array
{
    [, $def] = creditVariant($key);
    $missing = [];
    foreach (($def['requires'] ?? []) as $field => $label) {
        if (creditClauseValue($field, $agreement) === '') $missing[] = $label;
    }
    return $missing;
}

/**
 * Whether a variant can be printed for this agreement. The standard agreement
 * always can. An early-payment variant needs both its wording (supplied in this
 * file) and every per-deal value it refers to.
 */
function creditVariantReady(string $key, array $agreement = []): bool
{
    if ($key === 'standard') return true;
    [, $def] = creditVariant($key);
    if (empty($def['clauses'])) return false;
    return !creditVariantMissing($key, $agreement);
}

/**
 * A variant's clauses with every {token} replaced by its value from the
 * agreement. Call creditVariantReady() first — a clause is never printed with an
 * unresolved token in it.
 */
function creditVariantClauses(string $key, array $agreement = []): array
{
    [, $def] = creditVariant($key);
    $map = [];
    foreach (($def['requires'] ?? []) as $field => $_label) {
        $map['{' . $field . '}'] = creditClauseValue($field, $agreement);
    }
    if (!$map) return $def['clauses'];

    $out = [];
    foreach ($def['clauses'] as $c) {
        $c['body']    = strtr((string)($c['body'] ?? ''),    $map);
        $c['heading'] = strtr((string)($c['heading'] ?? ''), $map);
        $out[] = $c;
    }
    return $out;
}

/**
 * The clause letter for a position in section 3, continuing after the four
 * clauses — a) b) c) d) — that are fixed in the base document.
 *
 * $index is 0-based within the variant's own clauses, so 0 → 'e', 1 → 'f'.
 */
function creditClauseLetter(int $index): string
{
    return chr(ord('e') + $index);   // 'd' is the last fixed clause in section 3.
}

} // function_exists('creditVariants')
