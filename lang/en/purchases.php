<?php

/*
 | Cultiv One - English strings for the purchases area.
 |
 | The application already calls __('') with the English sentence as its key, so this
 | file is an identity map: value === key. It exists for two reasons. It documents
 | which strings are user-facing, and it means a missing translation in another
 | locale degrades to English through a file that definitely exists rather than
 | relying on the raw key leaking into the UI.
 */

return [
    'Discount (Rp)' => 'Discount (Rp)',
    'Expected date' => 'Expected date',
    'New purchase' => 'New purchase',
    'Shipping (Rp)' => 'Shipping (Rp)',
    'Supplier *' => 'Supplier *',
    'Purchase Orders' => 'Purchase Orders',
    'Tax (Rp)' => 'Tax (Rp)',
    'Warehouse *' => 'Warehouse *',
    'e.g. 10000' => 'e.g. 10000',
    'e.g. 15000' => 'e.g. 15000',
    'e.g. 2500' => 'e.g. 2500',
    'e.g. Supplier invoice number' => 'e.g. Supplier invoice number',

    // Purchase form UX. The supplier empty state and the validation summary exist
    // because a required dropdown with no options (or a rejected submit that came
    // back with no visible reason) left the user with nothing to act on.
    'Add a supplier first to create a purchase.' => 'Add a supplier first to create a purchase.',
    'Add item' => 'Add item',
    'Add supplier' => 'Add supplier',
    'Back to purchases' => 'Back to purchases',
    'Cannot cancel this purchase.' => 'Cannot cancel this purchase.',
    'Create purchase' => 'Create purchase',
    'Creating…' => 'Creating…',
    'Draft purchase deleted.' => 'Draft purchase deleted.',
    'Items *' => 'Items *',
    'New Purchase Order' => 'New Purchase Order',
    'No suppliers yet.' => 'No suppliers yet.',
    'Only ordered purchases can be received.' => 'Only ordered purchases can be received.',
    'Please complete the required fields.' => 'Please complete the required fields.',
    'Purchase cancelled.' => 'Purchase cancelled.',
    'Purchase order created. Receive it to increase stock.' => 'Purchase order created. Receive it to increase stock.',
    'Purchase received. Stock updated.' => 'Purchase received. Stock updated.',
    'Purchase updated.' => 'Purchase updated.',

    // Listing column headers and empty state, matching /sales.
    'All suppliers' => 'All suppliers',
    'Create the first one' => 'Create the first one',
    'Qty' => 'Qty',
    'Remove item' => 'Remove item',
    'Select a supplier' => 'Select a supplier',
    'Select a warehouse' => 'Select a warehouse',
    'Select product' => 'Select product',
    'Update purchase' => 'Update purchase',
    'Total' => 'Total',
    'Unit cost' => 'Unit cost',
    'Updating…' => 'Updating…',
];
