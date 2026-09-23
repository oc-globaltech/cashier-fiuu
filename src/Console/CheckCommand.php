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
                ? $this->bad('Fiuu credentials', (string) $result['error_desc'])
                : $this->ok('Fiuu credentials', 'accepted by '.parse_url($fiuu->payUrl(), PHP_URL_HOST));
        } catch (Throwable $e) {
            $this->bad('Fiuu credentials', $e->getMessage());
        }
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
