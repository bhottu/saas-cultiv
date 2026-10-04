<?php

/*
 | Cultiv One - English strings for the stock area.
 |
 | The application already calls __('') with the English sentence as its key, so this
 | file is an identity map: value === key. It exists for two reasons. It documents
 | which strings are user-facing, and it means a missing translation in another
 | locale degrades to English through a file that definitely exists rather than
 | relying on the raw key leaking into the UI.
 */

return [
    '+ Add Warehouse' => '+ Add Warehouse',
    'Current stock' => 'Current stock',
    'Current stock is maintained by stock movements.' => 'Current stock is maintained by stock movements.',
    'Direction *' => 'Direction *',
    // Adjustments announce their outcome by direction: adding stock and reducing it are
    // different actions to the user, and one generic "Stock adjusted." told them nothing
    // about which one had just happened.
    'Failed to add stock.' => 'Failed to add stock.',
    'Failed to reduce stock.' => 'Failed to reduce stock.',
    'New balance: :balance.' => 'New balance: :balance.',
    'No inventory record' => 'No inventory record',
    'No inventory-tracked products.' => 'No inventory-tracked products.',
    'Not configured' => 'Not configured',
    'Opening stock, damaged goods, stock count…' => 'Opening stock, damaged goods, stock count…',
    'Product *' => 'Product *',
    'Products tracked' => 'Products tracked',
    'Quantity *' => 'Quantity *',
    'Reason *' => 'Reason *',
    'Save adjustment' => 'Save adjustment',
    'Stock added successfully.' => 'Stock added successfully.',
    'Stock adjustment / opening balance' => 'Stock adjustment / opening balance',
    'Stock in / opening balance' => 'Stock in / opening balance',
    'Stock out / reduction' => 'Stock out / reduction',
    'Stock reduced successfully.' => 'Stock reduced successfully.',
    'This product does not track inventory.' => 'This product does not track inventory.',
    'Use an adjustment to enter opening stock. Minimum and maximum stock are thresholds, not quantities.' => 'Use an adjustment to enter opening stock. Minimum and maximum stock are thresholds, not quantities.',
];
