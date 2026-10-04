<?php

/*
 | Cultiv One - English strings for the expenses area.
 |
 | The application already calls __('') with the English sentence as its key, so this
 | file is an identity map: value === key. It exists for two reasons. It documents
 | which strings are user-facing, and it means a missing translation in another
 | locale degrades to English through a file that definitely exists rather than
 | relying on the raw key leaking into the UI.
 */

return [
    'Add expense' => 'Add expense',
    'Amount (Rp) *' => 'Amount (Rp) *',
    'Date *' => 'Date *',
    'Description *' => 'Description *',
    'Notes' => 'Notes',
    'Payment method' => 'Payment method',
    'Save expense' => 'Save expense',
    'Update expense' => 'Update expense',
    'Expenses' => 'Expenses',

    // Expense categories. The table and model have always existed, but nothing let a
    // user create a row, so the Category dropdown had no possible source.
    'Add Expense Category' => 'Add Expense Category',
    'Categories here classify spending. Product categories are managed separately.' => 'Categories here classify spending. Product categories are managed separately.',
    'Create category' => 'Create category',
    'Edit Expense Category' => 'Edit Expense Category',
    'Expense Categories' => 'Expense Categories',
    'Expense category created.' => 'Expense category created.',
    'Expense category deleted.' => 'Expense category deleted.',
    'Expense category updated.' => 'Expense category updated.',
    'This category is used by expenses. Deactivate it instead of deleting.' => 'This category is used by expenses. Deactivate it instead of deleting.',
    'Delete this category?' => 'Delete this category?',
    'No expense categories yet.' => 'No expense categories yet.',

    // Listing: column headers, statuses and the empty state, matching /sales.
    'Method' => 'Method',
    'Delete this expense?' => 'Delete this expense?',
    'No expenses found.' => 'No expenses found.',
    'Record the first one' => 'Record the first one',
    'Add one' => 'Add one',
    'Back to categories' => 'Back to categories',
    'Manage categories' => 'Manage categories',
    'No category' => 'No category',
    'Please complete the required fields.' => 'Please complete the required fields.',
    'e.g. Utilities' => 'e.g. Utilities',
    'Expense recorded.' => 'Expense recorded.',
    'Expense updated.' => 'Expense updated.',
    'Expense deleted.' => 'Expense deleted.',
    'Back to expenses' => 'Back to expenses',
    'Add Expense' => 'Add Expense',
    'Edit Expense' => 'Edit Expense',
];
