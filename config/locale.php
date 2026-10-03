<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Language
    |--------------------------------------------------------------------------
    |
    | Indonesian is the product language for Cultiv One, so it is the default for
    | every account that has never picked one. English remains fully supported as
    | the fallback locale, which is what makes untranslated strings degrade to
    | English rather than to a raw translation key.
    |
    */

    'default' => env('APP_LOCALE', 'id'),

    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Supported Languages
    |--------------------------------------------------------------------------
    |
    | The single source of truth for what the language switcher offers, which codes
    | are accepted, and how each one presents itself. `SetLocale` refuses anything
    | not listed here, so a hand-edited users.locale can never push the app into a
    | locale that has no translation files at all.
    |
    | `name` is the language's OWN name — "Bahasa Indonesia", "English" — because a
    | language list written in the language you are currently reading is hard to
    | scan: hunting for "English" among translated labels is guesswork, while
    | hunting for "English" among native labels is not. `english` is kept alongside
    | for places that need a single-language label (reports, emails, audit logs).
    |
    */

    'supported' => [
        'id' => [
            'name' => 'Bahasa Indonesia',
            'english' => 'Indonesian',
            'flag' => '🇮🇩',
        ],
        'en' => [
            'name' => 'English',
            'english' => 'English',
            'flag' => '🇬🇧',
        ],
    ],

];