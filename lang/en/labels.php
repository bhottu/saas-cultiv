<?php

/*
| Cultiv One - Labels that are chosen at runtime.
| ---------------------------------------------------------------------------
| The rest of the application translates whole English sentences straight from
| lang/<locale>.json. That is safe because a sentence can never collide with a
| language file name.
|
| It is NOT safe for values that come from config or the database — sales channels,
| payment methods, module names. Those are short: "POS" happens to be both a sales
| channel and the name of lang/en/pos.php, so __('POS') resolved to the whole module
| array and blew up the page with "htmlspecialchars(): Argument #1 must be of type
| string, array given".
|
| Everything that is looked up by a runtime value therefore lives here, behind a
| dotted key that can never be parsed as a group name. Adding a channel or a module
| means adding a line below, never renaming a file.
*/

return [
    'channel' => [
        'POS'           => 'POS',
        'Online Store'  => 'Online Store',
        'WhatsApp'      => 'WhatsApp',
        'Marketplace'   => 'Marketplace',
        'Manual'        => 'Manual',
    ],

    'payment' => [
        'Cash'          => 'Cash',
        'Bank Transfer' => 'Bank Transfer',
        'QRIS'          => 'QRIS',
        'Debit Card'    => 'Debit Card',
        'Credit Card'   => 'Credit Card',
        'E-Wallet'      => 'E-Wallet',
        'Other'         => 'Other',
    ],

    'module' => [
        'POS'                => 'POS',
        'Point of Sale (POS)' => 'Point of Sale (POS)',
    ],

    // Sale::STATUSES
    'order' => [
        'draft'      => 'Draft',
        'pending'    => 'Pending',
        'processing' => 'Processing',
        'completed'  => 'Completed',
        'cancelled'  => 'Cancelled',
        'refunded'   => 'Refunded',
    ],
];
