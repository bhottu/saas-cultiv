<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Flatten lang/<locale>/*.php into lang/<locale>.json.
 *
 * Why this command exists
 * -----------------------
 * The views call __(...) with a whole English sentence as the key, e.g.
 * __('Brand Name'). That is a deliberate choice to avoid rewriting ~1600 call sites,
 * and it is the style Laravel supports through its JSON translation files: the
 * translator checks lang/<locale>.json FIRST, before ever looking at a grouped PHP
 * file. A key like 'Brand Name' has no dot, so parseKey() treats it as a GROUP name,
 * and the grouped lookup would search for a file literally called `Brand Name.php`
 * and never find it. Long story short: without the JSON file, every one of those
 * calls silently renders its own key back, i.e. English, in every locale.
 *
 * So the module PHP files stay the single place a translator edits, and this command
 * flattens them into the JSON the translator actually reads. Never hand-edit the
 * JSON: `TranslationSyncTest` fails if the two ever drift apart.
 */
class LangSyncCommand extends Command
{
    protected $signature = 'lang:sync
                            {--locale=* : Limit to specific locales (default: all found)}
                            {--check : Report drift without writing; exit 1 if stale}';

    protected $description = 'Flatten lang/<locale>/*.php into lang/<locale>.json';

    public function handle(): int
    {
        $locales = $this->option('locale') ?: $this->discoverLocales();

        if ($locales === []) {
            $this->components->error('No lang/<locale> directories found.');

            return self::FAILURE;
        }

        $fallback = (string) config('locale.fallback');
        $check = (bool) $this->option('check');
        $stale = [];

        foreach ($locales as $locale) {
            $merged = $this->buildLines($locale, $fallback);
            $path = lang_path("{$locale}.json");
            $encoded = $this->encode($merged);
            $current = is_file($path) ? (string) file_get_contents($path) : null;

            if ($current === $encoded) {
                $this->components->twoColumnDetail($locale, '<fg=green>in sync</>');

                continue;
            }

            $stale[] = $locale;

            if ($check) {
                $this->components->twoColumnDetail($locale, "<fg=yellow>stale</> (".count($merged).' keys)');

                continue;
            }

            file_put_contents($path, $encoded);
            $this->components->twoColumnDetail($locale, '<fg=green>written</> ('.count($merged).' keys)');
        }

        if ($check && $stale !== []) {
            $this->components->error('Run `php artisan lang:sync` to regenerate: '.implode(', ', $stale));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Every key the app can ask for, resolved for one locale.
     *
     * The union matters more than it looks. If a key is missing from this locale's
     * JSON, the translator falls through to the grouped-file lookup, and there
     * parseKey() reads a dot-less key as a GROUP name: __('Dashboard') searches for
     * lang/id/Dashboard.php. On a case-insensitive filesystem (Windows, macOS) that
     * matches lang/id/dashboard.php and returns the whole file — an array where a
     * string is expected, which blows up the view with an htmlspecialchars() error.
     * On Linux it silently returns English instead. Neither is acceptable, so every
     * key known to any locale is written out, untranslated ones carrying the
     * fallback string. Translation then degrades to English on purpose, which is
     * exactly what config('app.fallback_locale') promises.
     *
     * @return array<string, string>
     */
    private function buildLines(string $locale, string $fallback): array
    {
        $primary = $this->mergeModuleFiles($locale);
        $fallbackLines = $locale === $fallback ? [] : $this->mergeModuleFiles($fallback);

        // Fallback first, primary over it, so a real translation always wins.
        $merged = array_merge($fallbackLines, $primary);
        ksort($merged);

        return $merged;
    }

    /**
     * Merge every module file for one locale.
     *
     * Sorted by filename so the merge is deterministic, and array_merge so a later
     * file legitimately overrides an earlier one on an accidental duplicate.
     *
     * Only string values are flattened. The JSON is a flat sentence map, and a file
     * is allowed to hold a nested group instead — lang/{locale}/labels.php does, so
     * that runtime values such as the "POS" sales channel can be reached by a dotted
     * key. Copying that group into the JSON as well would publish `channel`,
     * `payment` and `module` as bare keys, and __('channel') would then return an
     * array — the same failure this command exists to prevent.
     *
     * @return array<string, string>
     */
    private function mergeModuleFiles(string $locale): array
    {
        $merged = [];
        $files = glob(lang_path($locale).'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $lines = require $file;

            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $key => $value) {
                if (is_string($value)) {
                    $merged[$key] = $value;
                }
            }
        }

        ksort($merged);

        return $merged;
    }

    /**
     * JSON with real UTF-8 characters and escaped slashes.
     *
     * Without the flag overrides the file would be full of \u00e9 sequences, which are
     * valid but make the translation files unreadable and unreviewable in a diff.
     */
    private function encode(array $lines): string
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        return json_encode($lines, $flags).PHP_EOL;
    }

    /** @return array<int, string> */
    private function discoverLocales(): array
    {
        $locales = [];

        foreach (glob(lang_path('*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $locales[] = basename($dir);
        }

        return $locales;
    }
}