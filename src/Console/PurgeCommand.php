<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Console;

use Azhimov\Idempotency\Contracts\IdempotencyStore;
use Azhimov\Idempotency\Contracts\PurgesExpiredRecords;
use Illuminate\Console\Command;

final class PurgeCommand extends Command
{
    /** @var string */
    protected $signature = 'idempotency:purge';

    /** @var string */
    protected $description = 'Delete expired idempotency responses and abandoned claims';

    public function handle(IdempotencyStore $store): int
    {
        if (! $store instanceof PurgesExpiredRecords) {
            $this->components->info('The configured idempotency store expires records on its own; nothing to purge.');

            return self::SUCCESS;
        }

        $purged = $store->purgeExpired();

        $this->components->info("Purged {$purged} expired idempotency record(s).");

        return self::SUCCESS;
    }
}
