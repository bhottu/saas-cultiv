<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The translated JSON the translator actually reads must match the module PHP files
 * a translator edits.
 *
 * Cultiv One uses whole English sentences as translation keys — `__('Brand Name')` —
 * so that the ~1600 existing call sites do not have to be rewritten. Laravel only
 * resolves that key style through lang/<locale>.json; a key with no dot is otherwise
 * parsed as a group name and searched for as a file. `lang:sync` flattens the module
 * files into that JSON.
 *
 * That split creates a silent failure mode: edit lang/id/settings.php, forget to
 * re-run the command, the tests stay green and Indonesian users keep seeing English.
 * This test is the tripwire — it fails the moment the two disagree.
 */
class TranslationSyncTest extends TestCase
{
    public function test_the_generated_json_matches_the_module_files(): void
    {
        $fallback = (string) config('locale.fallback');
        $stale = [];

        foreach ($this->locales() as $locale) {
            // Mirrors LangSyncCommand::buildLines(): every key known to any locale is
            // written out, untranslated ones carrying the fallback string.
            $primary = $this->mergedModuleLines($locale);
            $fallbackLines = $locale === $fallback ? [] : $this->mergedModuleLines($fallback);
            $expected = array_merge($fallbackLines, $primary);
            ksort($expected);

            $jsonPath = lang_path("{$locale}.json");

            if (! is_file($jsonPath)) {
                $stale[] = "{$locale}: lang/{$locale}.json does not exist — run `php artisan lang:sync`";

                continue;
            }

            $actual = json_decode((string) file_get_contents($jsonPath), true);

            if ($actual !== $expected) {
                $missing = array_diff(array_keys($expected), array_keys($actual ?: []));
                $changed = array_intersect_key($expected, $actual ?: []);
                $changed = array_keys(array_filter($changed, fn ($k) => $expected[$k] !== $actual[$k]));

                $stale[] = sprintf(
                    '%s: out of sync (%d missing, %d changed) — run `php artisan lang:sync`',
                    $locale,
                    count($missing),
                    count($changed)
                );
            }
        }

        $this->assertSame([], $stale, implode(PHP_EOL, $stale));
    }

    public function test_both_declared_locales_have_module_files(): void
    {
        foreach (array_keys(config('locale.supported')) as $code) {
            $this->assertDirectoryExists(
                lang_path($code),
                "The supported locale `{$code}` has no lang/{$code} directory."
            );
        }
    }

    /**
     * Every key must resolve to a STRING in every locale.
     *
     * This is the test that catches the failure mode the sync command exists to
     * prevent. A dot-less key such as `__('Dashboard')` is parsed as the group
     * "Dashboard"; on a case-insensitive filesystem that resolves to
     * lang/id/dashboard.php and yields the whole file — an array. Blade then dies
     * with "htmlspecialchars(): Argument #1 must be of type string, array given",
     * and the page is a hard 500. Asserting on file naming would be indirect; asking
     * the translator directly is exactly what the view will do.
     */
    /**
     * Every literal key a view asks for must exist in the JSON.
     *
     * A key that is absent does not merely render in English. Laravel falls back to the
     * English line, then to treating the sentence as a group name, which on a
     * case-insensitive filesystem resolves to a module PHP file and hands `__()` an
     * array — so the page dies with "htmlspecialchars(): Argument #1 must be of type
     * string, array given". Both quote styles are matched on purpose: an earlier
     * extraction pass only looked for single quotes and missed three double-quoted
     * call sites, which shipped as live 500s.
     */
    public function test_every_literal_key_used_in_a_view_exists_in_the_json(): void
    {
        $locales = $this->locales();
        $missing = [];
        $unresolved = [];

        $views = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        // Only whole literals count. `__('labels.channel.'.$label)` is a concatenation,
        // not a key, and is guarded by test_runtime_labels_resolve_through_the_
        // labels_namespace() instead — so the quote must be followed by the end of
        // the call rather than by more PHP.
        $pattern = '/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*[,)]/';

        foreach ($views as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match_all($pattern, $source, $matches)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(resource_path('views')) + 1));

            foreach (array_unique($matches[2]) as $key) {
                $key = stripcslashes($key);

                foreach ($locales as $locale) {
                    $lines = $this->jsonLines($locale);

                    if (! array_key_exists($key, $lines)) {
                        $missing[] = "{$locale}: [{$relative}] '{$key}'";
                    } elseif (! is_string(trans($key, [], $locale))) {
                        $unresolved[] = "{$locale}: [{$relative}] '{$key}' resolved to a non-string";
                    }
                }
            }
        }

        $this->assertSame([], $missing, "Run `php artisan lang:sync`.\n".implode(PHP_EOL, $missing));
        $this->assertSame([], $unresolved, implode(PHP_EOL, $unresolved));
    }

    /**
 * Regression guard for the bug this namespace exists to prevent.
 *
 * A sales channel is literally called "POS", and lang/en/pos.php is a real module
 * file. Laravel treats a dot-less key as a group name, so __('POS') handed the view
 * the entire translation array and sales/create died with "htmlspecialchars():
 * Argument #1 must be of type string, array given". Everything chosen at runtime
 * therefore lives under labels.* — a dotted key that can never be read as a file.
 */
    public function test_runtime_labels_resolve_through_the_labels_namespace(): void
    {
    $bad = [];

    $labels = [
        'channel' => array_keys((array) config('business.sales.channels')),
        'payment' => array_keys((array) config('business.sales.payment_methods')),
        'module' => array_merge(
            array_column((array) config('modules.registry', []), 'name'),
            array_filter(array_column((array) config('modules.manifests', []), 'name')),
        ),
        'order' => (array) \App\Models\Sale::STATUSES,
    ];

    foreach ($labels as $group => $names) {
        foreach ($names as $name) {
            foreach ($this->locales() as $locale) {
                $value = trans("labels.{$group}.{$name}", [], $locale);

                if (! is_string($value) || $value === '') {
                    $bad[] = sprintf(
                        'labels.%s.%s [%s] -> %s',
                        $group,
                        var_export($name, true),
                        $locale,
                        is_string($value) ? 'empty string' : gettype($value)
                    );
                }
            }
        }
    }

    $this->assertSame([], $bad, implode(PHP_EOL, $bad));

    // Keep the premise visible: a bare, un-namespaced key really does resolve to the
    // module file. If this ever stops being true the namespacing can be revisited —
    // until then it is load-bearing.
    $this->assertIsArray(
        trans('pos', [], 'en'),
        'A dot-less key still resolves to the language file, so runtime labels must stay namespaced.'
    );
}

    public function test_every_key_resolves_to_a_string_in_every_locale(): void
    {
        // The universe of keys is the union across every locale, NOT the keys this
        // locale happens to have in its JSON: the failure being guarded against is a
        // key being MISSING from the JSON, and a test that only walks the keys it finds
        // there would walk straight past the very thing it is meant to catch.
        $universe = [];
        foreach ($this->locales() as $locale) {
            $universe = array_merge($universe, array_keys($this->mergedModuleLines($locale)));
        }
        $universe = array_values(array_unique($universe));

        $this->assertNotEmpty($universe, 'No translation keys were discovered.');

        $bad = [];

        foreach ($this->locales() as $locale) {
            foreach ($universe as $key) {
                $value = trans($key, [], $locale);

                if (! is_string($value)) {
                    $bad[] = sprintf('%s: %s() returned %s', $locale, var_export($key, true), gettype($value));
                }
            }
        }

        $this->assertSame([], $bad, implode(PHP_EOL, $bad));
    }

    /**
     * The Indonesian build must actually be Indonesian, not a pass-through.
     *
     * Without this, deleting every Indonesian string would still leave a complete,
     * self-consistent pair of files and a green suite — the whole UI would simply
     * stay English with nobody noticing until a user complained.
     */
    public function test_indonesian_is_translated_and_english_is_the_identity(): void
    {
        $id = $this->mergedModuleLines('id');
        $en = $this->mergedModuleLines('en');

        // "Brand" style terms are legitimately identical in both languages; the point
        // is that the bulk of user-facing copy is genuinely different.
        $translated = 0;
        foreach (array_intersect_key($id, $en) as $key => $value) {
            if ($value !== $en[$key]) {
                $translated++;
            }
        }

        $this->assertGreaterThan(
            20,
            $translated,
            'Indonesian looks like a pass-through copy of English rather than a translation.'
        );

        foreach (['Settings', 'Language', 'Security', 'Team', 'Billing'] as $key) {
            $this->assertNotSame(
                $en[$key],
                $id[$key],
                "`{$key}` was left untranslated in the Indonesian build."
            );
        }
    }

    /** @return array<int, string> */
    private function locales(): array
    {
        $locales = array_keys(config('locale.supported'));
        sort($locales);

        return $locales;
    }

    /** @return array<string, string> */
    private function jsonLines(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            return [];
        }

        return (array) json_decode((string) file_get_contents($path), true);
    }

    /** @return array<string, string> */
    /**
     * Mirrors LangSyncCommand::mergeModuleFiles(): a flat map of the string values,
     * with nested groups left behind in the PHP file. Keeping these two in step is
     * the whole contract of the "JSON matches the module files" assertion above.
     *
     * @return array<string, string>
     */
    private function mergedModuleLines(string $locale): array
    {
        $merged = [];
        $files = glob(lang_path($locale).'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            foreach ((array) require $file as $key => $value) {
                if (is_string($value)) {
                    $merged[$key] = $value;
                }
            }
        }

        ksort($merged);

        return $merged;
    }
}