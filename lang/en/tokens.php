<?php

/*
 | Cultiv One - English strings for the tokens area.
 |
 | The application already calls __('') with the English sentence as its key, so this
 | file is an identity map: value === key. It exists for two reasons. It documents
 | which strings are user-facing, and it means a missing translation in another
 | locale degrades to English through a file that definitely exists rather than
 | relying on the raw key leaking into the UI.
 */

return [
    'API Tokens' => 'API Tokens',
    'API Access is available on the :plans plans.' => 'API Access is available on the :plans plans.',
    'Upgrade your plan to create and use API tokens.' => 'Upgrade your plan to create and use API tokens.',
    'Plan and limits' => 'Plan and limits',
    'Rate limit' => 'Rate limit',
    'requests/minute' => 'requests/minute',
    'Monthly quota' => 'Monthly quota',
    'requests/month' => 'requests/month',
    'Used this month' => 'Used this month',
    'Token name' => 'Token name',
    'Token name (e.g. CLI, Mobile app)' => 'Token name (e.g. CLI, Mobile app)',
    'Permissions' => 'Permissions',
    'Create token' => 'Create token',
    'Usage' => 'Usage',
    'The workspace is taken from the token itself — no extra header needed.' => 'The workspace is taken from the token itself — no extra header needed.',
    'Tokens' => 'Tokens',
    'Legacy full-access token' => 'Legacy full-access token',
    'created' => 'created',
    'last used' => 'last used',
    'Rotate this token? The current secret stops working immediately.' => 'Rotate this token? The current secret stops working immediately.',
    'Rotate' => 'Rotate',
    'Revoke this token? Clients using it will get 401.' => 'Revoke this token? Clients using it will get 401.',
    'Revoke' => 'Revoke',
    'No tokens yet.' => 'No tokens yet.',
    'Recent API activity' => 'Recent API activity',
    'No API activity yet.' => 'No API activity yet.',
];
