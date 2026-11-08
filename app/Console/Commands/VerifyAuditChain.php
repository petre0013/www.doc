<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\Audit;
use App\Support\AuditChain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('audit:verify')]
#[Description('Check the audit trail hash chain for tampering')]
class VerifyAuditChain extends Command
{
    public function handle(): int
    {
        $result = AuditChain::verify();
        $intact = $result['broken_at'] === null;

        Setting::put('audit', array_merge(Setting::get('audit', []), [
            'verified_at' => now()->toIso8601String(),
            'verified_ok' => $intact,
            'verified_rows' => $result['checked'],
            'broken_at' => $result['broken_at'],
        ]));

        if ($intact) {
            $this->info("Audit chain intact ({$result['checked']} events checked).");

            return self::SUCCESS;
        }

        Log::critical('Audit chain verification failed', $result);
        Audit::record('audit.chain_broken', null, $result);
        $this->error("Audit chain broken at event {$result['broken_at']}: {$result['reason']}");

        return self::FAILURE;
    }
}
