<?php

namespace App\Console\Commands;

use App\Services\Expenses\MileageRateSync;
use Illuminate\Console\Command;

class SyncMileageRates extends Command
{
    protected $signature = 'finance:mileage-rates';

    protected $description = 'Check irs.gov for new IRS standard mileage rates';

    public function handle(MileageRateSync $sync): int
    {
        $r = $sync->sync();
        if (! $r['ok']) {
            $this->error('Mileage rate check failed: '.$r['error']);

            return self::FAILURE;
        }
        $this->info($r['added'] ? 'Added: '.implode(', ', $r['added']) : 'No new rates.');

        return self::SUCCESS;
    }
}
