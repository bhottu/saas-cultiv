<?php

namespace App\Console\Commands;

use App\Models\PaymentSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Operator check for the QRIS.PW integration.
 *
 * Answers the two questions that were impossible to answer from the outside:
 *  1. are the credentials actually LOADED (a stale config cache looks identical to
 *     a missing .env value from the browser), and
 *  2. does the provider accept them.
 *
 * Secrets are never printed. A value is only ever reported as present/absent and as
 * a short fingerprint, and the probe reports the HTTP status — never the body.
 */
class PaymentsDoctor extends Command
{
    protected $signature = 'payments:doctor
                            {--probe : Also send one live create-payment call and report only its HTTP status}';

    protected $description = 'Report whether the payment provider is configured correctly (never prints secrets)';

    public function handle(): int
    {
        $this->line('');
        $this->line('  QRIS.PW integration check');
        $this->line('  ===========================');

        $this->row('Environment', (string) config('app.env'));
        $this->row('App URL', (string) config('app.url'));
        $this->row('Config cached', app()->configurationIsCached()
            ? 'YES  <-- run optimize:clear && config:cache after editing .env'
            : 'no');

        $this->line('');

        // Read the raw .env as well, so a stale cache shows up as a MISMATCH rather
        // than being indistinguishable from a key that was never added.
        $checks = [
            'QRISPW_API_KEY' => [(string) config('services.qrispw.api_key'), $this->rawEnvValue('QRISPW_API_KEY')],
            'QRISPW_API_SECRET' => [(string) config('services.qrispw.api_secret'), $this->rawEnvValue('QRISPW_API_SECRET')],
        ];

        $healthy = true;

        foreach ($checks as $name => [$loaded, $fromFile]) {
            if ($loaded === '') {
                $this->row($name, 'MISSING - not loaded by the application');
                $healthy = false;

                continue;
            }

            $fingerprint = substr(hash('sha256', $loaded), 0, 8);

            if ($fromFile !== null && $fromFile !== $loaded) {
                $this->row($name, "loaded #{$fingerprint} but .env holds a DIFFERENT value (stale config cache)");
                $healthy = false;

                continue;
            }

            $this->row($name, "set (#{$fingerprint}, ".strlen($loaded).' chars)');
        }

        $this->row('Create endpoint', (string) config('services.qrispw.endpoints.create'));
        $this->row('Status endpoint', (string) config('services.qrispw.endpoints.status'));

        $this->line('');

        // Kasera is optional: reported for visibility, never fatal. A QRIS.PW-only
        // platform is a perfectly healthy configuration; these rows exist so an
        // operator who DID select Kasera can see at a glance why it is not working.
        $kaseraKey = trim((string) config('services.kasera.api_key'));

        $this->row('Active gateway', PaymentSetting::activeGateway());
        $this->row('KASERA_API_KEY', $kaseraKey === ''
            ? 'not set (fine while QRIS.PW is active)'
            : 'set (#'.substr(hash('sha256', $kaseraKey), 0, 8).', '.strlen($kaseraKey).' chars)');
        $this->row('Kasera webhook', config('services.kasera.webhook_secret')
            ? 'secret set'
            : 'NO SECRET - Kasera deliveries would be rejected (403)');

        $this->line('');

        if (! $healthy) {
            $this->error('  The integration is NOT usable. Fix the items above, then re-run:');
            $this->line('    php artisan optimize:clear && php artisan config:cache');
            $this->line('');
            $this->line('  PHP-FPM and the queue worker read the CACHED config, so reload them too:');
            $this->line('    sudo systemctl reload php8.2-fpm');

            return self::FAILURE;
        }

        if ($this->option('probe')) {
            $this->probe();
        } else {
            $this->info('  Configuration looks complete. Re-run with --probe to verify the provider accepts it.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * One real call, reporting only the HTTP status.
     *
     * The request carries a deliberately invalid amount so the provider is asked to
     * reject it: that proves DNS, TLS and the credentials all work while creating no
     * real payment that would later need refunding.
     */
    private function probe(): void
    {
        $this->line('  Probing provider (one request, no payment is created)...');

        try {
            $response = Http::withHeaders([
                    'X-API-Key' => config('services.qrispw.api_key'),
                    'X-API-Secret' => config('services.qrispw.api_secret'),
                ])
                ->timeout(15)
                ->post(config('services.qrispw.endpoints.create'), [
                    'amount' => 0,
                    'order_id' => 'PROBE-DOCTOR',
                ]);
        } catch (\Throwable $e) {
            $this->error('  Could not reach the provider: '.$e->getMessage());
            $this->line('  This is a network/DNS/TLS problem, not a credential problem.');

            return;
        }

        $status = $response->status();
        $this->row('HTTP status', (string) $status);

        match (true) {
            $status === 401, $status === 403 => $this->error('  The provider rejected these credentials.'),
            $status === 200, $status === 400, $status === 422 => $this->info('  The provider accepted the credentials (the dummy order was rejected as intended).'),
            $status === 429 => $this->warn('  The provider is rate limiting this server.'),
            default => $this->warn('  Unexpected status. Check storage/logs/laravel.log for "qrispw.*".'),
        };

        $this->line('');
    }

    /** The value in the .env FILE, bypassing the config layer entirely. */
    private function rawEnvValue(string $key): ?string
    {
        $path = base_path('.env');

        if (! File::exists($path)) {
            return null;
        }

        foreach (File::lines($path) as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.*)$/', $line, $m)) {
                return trim(trim($m[1]), "\"'");
            }
        }

        return null;
    }

    private function row(string $label, string $value): void
    {
        $this->line(sprintf('  %-18s %s', $label, $value));
    }
}