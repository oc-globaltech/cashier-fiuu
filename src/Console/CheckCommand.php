<?php

namespace OcGlobalTech\CashierFiuu\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use OcGlobalTech\CashierFiuu\Fiuu;
use Throwable;

/**
 * Checks that this application is wired up to bill through Fiuu.
 *
 * Every misconfiguration below shows up in production as a refused payment or
 * a subscription that silently never renews, so it is worth a minute here.
 */
class CheckCommand extends Command
{
    protected $signature = 'cashier:check
                            {--offline : Skip the call that checks the credentials against Fiuu}';

    protected $description = 'Verify the Fiuu credentials, routes and schedule of this application';

    /** @var list<array{string, string, string}> */
    protected array $rows = [];

    protected bool $failed = false;

    public function handle(Fiuu $fiuu): int
    {
        $this->credentials($fiuu);
        $this->hosts($fiuu);
        $this->routes();
        $this->schedule();

        $this->newLine();
        $this->table(['', 'Check', 'Detail'], $this->rows);
        $this->newLine();

        if ($this->failed) {
            $this->error('Cashier is not ready to take payments.');

            return self::FAILURE;
        }

        $this->info('Cashier is ready to take payments.');

        return self::SUCCESS;
    }

    protected function credentials(Fiuu $fiuu): void
    {
        foreach (['merchant_id', 'verify_key', 'secret_key'] as $key) {
            config("cashier.{$key}")
                ? $this->ok("cashier.{$key}", 'set')
                : $this->bad("cashier.{$key}", 'empty; set the matching FIUU_ variable in .env');
        }

        $verify = (string) config('cashier.verify_key');

        if ($verify !== '' && ! preg_match('/^[0-9a-f]{32}$/i', $verify)) {
            $this->caution("cashier.verify_key", 'not the 32 character key Fiuu issues; check you have not swapped it with the secret key');
        }

        if ($this->option('offline') || $this->failed) {
            return;
        }

        try {
            $result = $fiuu->channels();

            isset($result['error_desc'])
                ? $this->bad('Fiuu credentials', $result['error_desc'].$this->modeHint((string) $result['error_desc']))
                : $this->ok('Fiuu credentials', 'accepted by '.parse_url($fiuu->payUrl(), PHP_URL_HOST));
        } catch (Throwable $e) {
            $this->bad('Fiuu credentials', $e->getMessage());
        }

        if ($this->failed) {
            return;
        }

        // The call above only proves the verify key. Every webhook and requery
        // is checked with the secret key, so prove that too, read only.
        try {
            $result = $fiuu->channelSuccessRate();

            // ponytail: Fiuu does not document this endpoint's error shape; a
            // warning, not a failure, until a wrong-key reply has been seen.
            isset($result['error_desc']) || isset($result['error_code'])
                ? $this->caution('cashier.secret_key', 'Fiuu refused a secret-key signed request: '.($result['error_desc'] ?? $result['error_code']).'. Compare FIUU_SECRET_KEY with the portal\'s Transaction Settings')
                : $this->ok('cashier.secret_key', 'accepted by '.parse_url($fiuu->apiUrl(), PHP_URL_HOST));
        } catch (Throwable $e) {
            $this->caution('cashier.secret_key', 'could not be checked: '.$e->getMessage());
        }
    }

    /**
     * A merchant ID only exists on one of Fiuu's two portals, and asking the
     * other one reads as "invalid merchant" rather than "wrong host".
     */
    protected function modeHint(string $error): string
    {
        if (stripos($error, 'merchant') === false) {
            return '';
        }

        return config('cashier.sandbox')
            ? '. If this merchant is on portal.fiuu.com rather than sandbox-portal.fiuu.com, set FIUU_SANDBOX=false'
            : '. If this merchant is on sandbox-portal.fiuu.com, set FIUU_SANDBOX=true';
    }

    protected function hosts(Fiuu $fiuu): void
    {
        $this->ok('Mode', config('cashier.sandbox') ? 'sandbox' : 'production');

        // The sandbox has no published recurring host, so a sandbox that has
        // not been given one cannot renew a single subscription.
        foreach (['recurringUrl', 'cardUrl', 'cardApiUrl'] as $method) {
            try {
                $fiuu->{$method}();
            } catch (Throwable $e) {
                $this->caution('cashier.sandbox_'.strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $method)), $e->getMessage());
            }
        }
    }

    protected function routes(): void
    {
        foreach (['cashier.notify', 'cashier.callback', 'cashier.return'] as $name) {
            if (! $this->getLaravel()['router']->getRoutes()->hasNamedRoute($name)) {
                $this->bad($name, 'route is not registered; remove Cashier::ignoreRoutes() or define it yourself');

                continue;
            }

            $url = route($name);

            str_starts_with($url, 'https://')
                ? $this->ok($name, $url)
                : $this->caution($name, $url.' is not https; Fiuu will not post to it in production');
        }
    }

    protected function schedule(): void
    {
        $scheduled = collect($this->getLaravel()->make(Schedule::class)->events())
            ->contains(fn ($event) => str_contains((string) $event->command, 'cashier:renew'));

        $scheduled
            ? $this->ok('cashier:renew', 'scheduled')
            : $this->bad('cashier:renew', "not scheduled; add Schedule::command('cashier:renew')->hourly() to routes/console.php");
    }

    protected function ok(string $check, string $detail): void
    {
        $this->rows[] = ['<fg=green>OK</>', $check, $detail];
    }

    protected function caution(string $check, string $detail): void
    {
        $this->rows[] = ['<fg=yellow>WARN</>', $check, $detail];
    }

    protected function bad(string $check, string $detail): void
    {
        $this->failed = true;

        $this->rows[] = ['<fg=red>FAIL</>', $check, $detail];
    }
}
