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
            'clauses' => [
                // ── PASTE THE EARLY PAYMENT CONCESSION CLAUSES HERE ──
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
 * Whether a variant can be printed. The standard agreement always can; an
 * early-payment variant only once its wording has been supplied.
 */
function creditVariantReady(string $key): bool
{
    if ($key === 'standard') return true;
    [, $def] = creditVariant($key);
    return !empty($def['clauses']);
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
