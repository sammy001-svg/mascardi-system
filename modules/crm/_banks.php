<?php
/**
 * The company's own bank accounts — where a customer is told to pay.
 *
 * These were written inside proforma.php, beside the letterhead, because the
 * invoice was the only thing that printed them. It is not any more: a payment
 * recorded against a lead now says which of these the money came into, and a
 * receipt prints it back. Two copies of a sort code is one copy too many, so
 * the list moved here and the invoice asks for it.
 *
 * 'label' is what an operator picks from. 'lines' is what prints, in order.
 * 'currency' is on the record because one of these is a USD account while the
 * documents total in shillings — paying into it is a deliberate choice rather
 * than something to stumble into.
 */

if (!function_exists('leadBankAccounts')) {

function leadBankAccounts(): array
{
    return [
        'm_oriental' => [
            'label'    => 'M-Oriental Bank (KES)',
            'currency' => 'KES',
            'lines'    => [
                'Bank'         => 'M- Oriental Bank Limited',
                'Account Name' => 'Mascardi Ventures Limited',
                'Account No'   => '1007044001797',
                'Branch'       => 'Westlands Branch, Nairobi',
                'Branch Code'  => '007',
                'Swift Code'   => 'MORBKENA',
            ],
        ],
        'ncba' => [
            'label'    => 'NCBA (KES)',
            'currency' => 'KES',
            'lines'    => [
                'Bank'                 => 'NCBA',
                'Account Name'         => 'Mascardi Ventures Limited',
                'Account No'           => '4539920024',
                'Paybill Business No'  => '880100',
            ],
        ],
        'im' => [
            'label'    => 'I&M Bank (KES)',
            'currency' => 'KES',
            'lines'    => [
                'Bank'         => 'I&M',
                'Account Name' => 'Mascardi Ventures Limited',
                'Account No'   => '03001705641210',
                'Paybill No'   => '542542',
            ],
        ],
        'absa' => [
            'label'    => 'ABSA (KES)',
            'currency' => 'KES',
            'lines'    => [
                'Bank'                => 'ABSA',
                'Account Name'        => 'Mascardi Ventures Limited',
                'Account No'          => '2051081016',
                'Paybill Business No' => '303030',
            ],
        ],
        // Flagged as USD in the label and on the document — the invoice totals are
        // in Kenya Shillings, so paying into this one is a deliberate choice rather
        // than something to stumble into.
        'm_oriental_usd' => [
            'label'    => 'M-Oriental Bank — USD account',
            'currency' => 'USD',
            'lines'    => [
                'Bank'         => 'M Oriental Bank Limited',
                'Account Name' => 'Mascardi Ventures Limited',
                'Account No'   => '1007051001949',
            ],
        ],
    ];
}

/** One account, or null if the key is not one of ours. */
function leadBank(string $key): ?array
{
    return leadBankAccounts()[$key] ?? null;
}

/** What to call it in a dropdown or on a receipt. */
function leadBankLabel(?string $key): string
{
    if ($key === null || $key === '') return '';
    return (string)(leadBankAccounts()[$key]['label'] ?? $key);
}

/** The account the documents default to, when nobody has chosen. */
function leadBankDefaultKey(): string
{
    return (string)array_key_first(leadBankAccounts());
}

} // function_exists('leadBankAccounts')
