<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LaravelOCI\LaravelOciDriver\OciAdapter;
use LaravelOCI\LaravelOciDriver\OciClient;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deletes expired pre-authenticated requests created by this package.
 */
#[AsCommand(
    name: 'oci:par:prune',
    description: 'Delete expired pre-authenticated requests created by laravel-oci-driver'
)]
final class OciParPruneCommand extends Command
{
    public $signature = 'oci:par:prune {disk=oci : The OCI disk to prune} {--dry-run : Only report what would be deleted}';

    public $description = 'Delete expired pre-authenticated requests created by laravel-oci-driver';

    public function handle(): int
    {
        $diskArgument = $this->argument('disk');
        $disk = is_string($diskArgument) && $diskArgument !== '' ? $diskArgument : 'oci';
        $adapter = Storage::disk($disk)->getAdapter();

        if (! $adapter instanceof OciAdapter) {
            $this->error("Disk [{$disk}] is not an OCI disk.");

            return self::FAILURE;
        }

        $client = $adapter->getClient();
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();
        $count = 0;
        $page = null;

        do {
            $result = $client->listPreAuthenticatedRequests($page);

            foreach ($result['items'] as $par) {
                if (! $this->isExpiredPackagePar($par, $now)) {
                    continue;
                }

                if (! $dryRun) {
                    $client->deletePreAuthenticatedRequest((string) $par['id']);
                }
                $count++;
            }

            $page = $result['next'];
        } while ($page !== null);

        $this->line(($dryRun ? 'Would prune' : 'Pruned')." {$count} expired PARs");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $par
     */
    private function isExpiredPackagePar(array $par, Carbon $now): bool
    {
        $name = (string) ($par['name'] ?? '');
        $expires = $par['timeExpires'] ?? null;

        return isset($par['id'])
            && str_starts_with($name, OciClient::PAR_NAME_PREFIX)
            && is_string($expires)
            && Carbon::parse($expires)->lessThan($now);
    }
}
