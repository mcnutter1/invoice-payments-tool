<?php

namespace App\Console\Commands;

use App\Services\Tax\TaxTableSync;
use Illuminate\Console\Command;

class SyncTaxTables extends Command
{
    protected $signature = 'finance:tax-tables';

    protected $description = 'Check irs.gov, ssa.gov and the configured feed for new tax brackets and limits';

    public function handle(TaxTableSync $sync): int
    {
        $r = $sync->sync();
        $this->line($r['changes'] ? 'Updated: '.implode(', ', $r['changes']) : 'No changes.');
        foreach ($r['errors'] as $e) $this->error($e);

        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
