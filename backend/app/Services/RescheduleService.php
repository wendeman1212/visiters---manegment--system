<?php

namespace App\Services;

use App\Models\VisitRequest;
use App\Models\VisitorBadge;
use Illuminate\Support\Facades\Log;

/**
 * Applies an appointment reschedule (date, start time and duration) to a single
 * visit record in a consistent way, regardless of who triggered it: a visitor,
 * a host, or an admin/CEO.
 *
 * It deliberately never changes the visit's approval status — a reschedule only
 * moves the schedule. Side artefacts that must track the new schedule are
 * refreshed here so every page (visitor, host, admin, CEO, QR pass) agrees:
 *
 *  - the entry QR token is reissued so its validity window matches the new time
 *    (the previous token's expiry was derived from the OLD time),
 *  - the visitor pass/badge display time and duration are updated.
 */
class RescheduleService
{
    /**
     * Apply a new schedule to a visit.
     *
     * @param  VisitRequest      $visit
     * @param  string|\DateTime  $newTime
     * @param  int               $newDuration minutes
     * @return array{0: mixed, 1: int} [old requested_time, old duration]
     */
    public function apply(VisitRequest $visit, $newTime, int $newDuration): array
    {
        $oldTime     = $visit->requested_time;
        $oldDuration = (int) $visit->duration;

        $visit->requested_time = $newTime;
        $visit->duration       = $newDuration;
        $visit->save();

        $this->refreshQr($visit);
        $this->refreshBadges($visit);

        return [$oldTime, $oldDuration];
    }

    /**
     * Reissue the entry QR for an approved visit so its validity window tracks
     * the new appointment time.
     */
    private function refreshQr(VisitRequest $visit): void
    {
        if ($visit->status !== 'approved' || !$visit->qrToken) {
            return;
        }

        try {
            QRTokenService::generate($visit);
            $visit->refresh();
        } catch (\Throwable $e) {
            Log::error("QR regeneration on reschedule failed for visit #{$visit->id}: " . $e->getMessage());
        }
    }

    /**
     * Keep the visitor pass/badge showing the same time as the appointment.
     */
    private function refreshBadges(VisitRequest $visit): void
    {
        try {
            $when = $visit->requested_time ? $visit->requested_time->format('M d, Y H:i') : '';

            VisitorBadge::where('visit_request_id', $visit->id)->update([
                'scheduled_time' => $when,
                'duration'       => $visit->duration . ' min',
            ]);
        } catch (\Throwable $e) {
            Log::error("VisitorBadge refresh on reschedule failed for visit #{$visit->id}: " . $e->getMessage());
        }
    }
}
