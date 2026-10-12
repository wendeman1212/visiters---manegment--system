<?php

namespace App\Console\Commands;

use App\Models\VisitGroupMember;
use App\Models\VisitorBadge;
use App\Services\QrBadgeService;
use App\Services\QrUrlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backward compatibility for badges that were stored before QR URLs were
 * centralized.
 *
 * Older badge SVG files embed a QR image whose link pointed at
 * "http://localhost:8000/qr/..." — a URL that only works on the server
 * itself. Re-rendering the badge regenerates the embedded QR image with the
 * current base URL (LAN IP in development, domain in production).
 *
 * Note: QR codes that were already PRINTED on paper cannot be rewritten by
 * the server — those badges must be reprinted from the dashboard.
 */
class RefreshQrBadges extends Command
{
    protected $signature = 'vms:refresh-qr-badges {--dry-run : List the badges that would be re-rendered without writing anything}';

    protected $description = 'Re-render stored QR badge SVGs so the encoded QR link uses the current application URL';

    public function handle(): int
    {
        $baseUrl = QrUrlService::baseUrl();
        $this->info("QR base URL: {$baseUrl}");

        $badges = VisitorBadge::whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->get();

        if ($badges->isEmpty()) {
            $this->info('No stored badge files found — nothing to refresh.');

            return self::SUCCESS;
        }

        $refreshed = 0;
        $skipped   = 0;
        $dryRun    = (bool) $this->option('dry-run');

        foreach ($badges as $badge) {
            $member = $badge->visit_group_member_id
                ? VisitGroupMember::find($badge->visit_group_member_id)
                : null;

            if (!$member || !$member->qr_token || !$member->visitRequest) {
                $this->line("Skipped badge #{$badge->id} (no group member / QR token).");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("Would re-render: {$badge->file_path} (member: {$member->name})");
                $refreshed++;

                continue;
            }

            $svg = QrBadgeService::generateMemberBadge($member->visitRequest, $member);
            if ($svg === '') {
                $this->warn("Could not render badge for member #{$member->id}.");
                $skipped++;

                continue;
            }

            Storage::disk('public')->put($badge->file_path, $svg);
            $this->line("Refreshed: {$badge->file_path}");
            $refreshed++;
        }

        $this->info("Done. {$refreshed} badge(s) refreshed, {$skipped} skipped.");

        if (!$dryRun) {
            $this->newLine();
            $this->comment('Already printed paper badges cannot be rewritten — reprint them from the dashboard.');
        }

        return self::SUCCESS;
    }
}
