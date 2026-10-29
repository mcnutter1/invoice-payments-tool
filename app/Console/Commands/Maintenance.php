<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\PrivateDocument;
use App\Models\PublicToken;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Maintenance extends Command
{
    use Heartbeat;

    protected $signature = 'finance:maintenance';

    protected $description = 'Expire signing envelopes, prune transient data.';

    public function handle(): int
    {
        app(\App\Support\Context::class)->actorType = 'system';

        return $this->beat('maintenance', function () {
            $expired = 0;
            foreach (ContractVersion::whereIn('status', ['sent', 'partially_signed'])->where('expires_at', '<', now())->get() as $v) {
                $v->update(['status' => 'expired']);
                Contract::whereKey($v->contract_id)->where('current_version_id', $v->id)->update(['status' => 'expired']);
                PublicToken::where('contract_version_id', $v->id)->where('purpose', 'contract_sign')->whereNull('revoked_at')->update(['revoked_at' => now()]);
                Audit::log($v->business_id, 'contract.expired', $v, null, null, null, 'system');
                $expired++;
            }
            DB::table('auth_callback_nonces')->where('expires_at', '<', now())->delete();
            // Uploaded bank CSVs are only needed during the import wizard.
            $csv = 0;
            foreach (PrivateDocument::where('kind', 'import_csv')->where('created_at', '<', now()->subDays(7))->get() as $doc) {
                @unlink($doc->absolutePath());
                $doc->delete();
                $csv++;
            }

            return ['expired_envelopes' => $expired, 'pruned_csv' => $csv];
        });
    }
}
