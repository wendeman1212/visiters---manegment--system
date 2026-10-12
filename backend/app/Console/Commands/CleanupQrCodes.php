<?php

namespace App\Console\Commands;

use App\Models\QRToken;
use App\Models\VisitGroupMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupQrCodes extends Command
{
    protected $signature = 'vms:cleanup-qr';

    protected $description = 'Delete used QR tokens and expired codes of closed visits; keep QRs for open visits valid until checkout';

    public function handle(): int
    {
        $openVisitIds = DB::table('visit_requests')
            ->select('id')
            ->whereIn('status', ['approved', 'checked_in']);

        // Used tokens are consumed at checkout. Expired tokens are only removed
        // once their visit is closed — while a visit is approved/checked_in the
        // QR must stay valid (re-verification at the gate, re-entry).
        $deleted = QRToken::where(function ($q) {
            $q->where('status', 'used')
                ->orWhereNotNull('used_at');
        })->orWhere(function ($expired) use ($openVisitIds) {
            $expired->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->whereNotIn('visit_request_id', $openVisitIds);
        })->delete();

        // Clear expired (unused) group member pass codes only for closed visits.
        $cleared = VisitGroupMember::whereNotNull('qr_token')
            ->whereNull('qr_used_at')
            ->whereNotNull('qr_expires_at')
            ->where('qr_expires_at', '<', now())
            ->whereNotIn('visit_request_id', $openVisitIds)
            ->update(['qr_token' => null, 'qr_expires_at' => null]);

        $this->info("QR cleanup: deleted {$deleted} token(s), cleared {$cleared} expired group member pass(es).");

        return 0;
    }
}
