<?php

namespace App\Console\Commands;

use App\Services\Access\BusinessProvisioner;
use Illuminate\Console\Command;

/** Idempotent post-deploy setup: permissions catalog, private storage, default roles for existing businesses. */
class Bootstrap extends Command
{
    protected $signature = 'finance:bootstrap';

    protected $description = 'Sync permission catalog and system roles; ensure private storage exists.';

    public function handle(BusinessProvisioner $provisioner): int
    {
        BusinessProvisioner::syncPermissions();
        foreach (\App\Models\Business::all() as $b) {
            $provisioner->seedRoles($b);
            $provisioner->seedChart($b);
        }
        $root = config('finance.storage.private_root');
        if (! is_dir($root) && ! @mkdir($root, 0770, true)) {
            $this->error("Cannot create private storage at {$root}");

            return self::FAILURE;
        }
        $this->info('Bootstrap complete.');

        return self::SUCCESS;
    }
}
