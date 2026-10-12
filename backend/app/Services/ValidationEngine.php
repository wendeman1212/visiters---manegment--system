<?php

namespace App\Services;

use App\Models\Blacklist;
use App\Models\Lockdown;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitRequest;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ValidationEngine
{
    /**
     * Validate a new visit request.
     *
     * @param array $data Input data
     * @return array Result array with 'passed', 'errors' list, and 'blacklist_match' flag.
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $blacklistMatch = false;

        // 1. Identity Verification
        if (empty($data['visitor_name'])) {
            $errors[] = "Visitor name is mandatory.";
        }
        if (empty($data['id_document_no'])) {
            $errors[] = "Identity Document (ID/Passport) number is mandatory.";
        }
        if (empty($data['visitor_email']) || !filter_var($data['visitor_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "A valid visitor email address is required.";
        }
        // Phone is recommended but not a hard block for logged-in visitors

        // 2. Blacklist Check (Identity Validation)
        if (!empty($data['id_document_no'])) {
            $blacklisted = Blacklist::where('id_document_no', $data['id_document_no'])->first();
            if ($blacklisted) {
                $blacklistMatch = true;
                $errors[] = "Security Violation: Visitor is on the organization blacklist. Reason: " . $blacklisted->reason;
                return [
                    'passed' => false,
                    'errors' => $errors,
                    'blacklist_match' => true
                ];
            }
        }

        // 3. Duplicate/Overlapping Booking Check for Visitor
        if (!empty($data['visitor_email'])) {
            $visitor = Visitor::where('email', $data['visitor_email'])->first();
            if ($visitor) {
                $requestedTime = Carbon::parse($data['requested_time']);
                $duration = intval($data['duration'] ?? 60);
                $endTime = $requestedTime->copy()->addMinutes($duration);

                // Check for overlapping active requests (DB-agnostic: DATE_ADD is
                // MySQL-only, so the overlap window is computed in PHP instead).
                $overlap = VisitRequest::where('visitor_id', $visitor->id)
                    ->whereIn('status', ['pending', 'approved', 'checked_in'])
                    ->get()
                    ->first(function ($existing) use ($requestedTime, $endTime) {
                        $existingStart = Carbon::parse($existing->requested_time);
                        $existingEnd   = $existingStart->copy()->addMinutes((int) ($existing->duration ?? 60));
                        return $existingStart < $endTime && $requestedTime < $existingEnd;
                    });

                if ($overlap) {
                    $errors[] = "Duplicate Booking: Visitor already has an active overlapping appointment (Request ID: {$overlap->id}).";
                }
            }
        }

        // 4. Host Validation
        $host = null;
        if (empty($data['host_id'])) {
            $errors[] = "A Host must be specified.";
        } else {
            $host = User::where('id', $data['host_id'])->where('role', 'host')->first();
            if (!$host) {
                $errors[] = "The specified Host does not exist.";
            } elseif ($host->status !== 'active') {
                $host = null; // treat as invalid so dept check skips
                $errors[] = "The Host account is currently inactive.";
            } else {
                // Host department match validation — assigned host must belong to the visit request's department
                if (!empty($data['department_id']) && $host->department_id != $data['department_id']) {
                    $errors[] = "The assigned Host (ID: {$host->id}) does not belong to the resolved department (ID: {$data['department_id']}). Assignment mismatch detected.";
                }

                // Host availability checks — respects CEO policy_allow_weekends setting
                $allowWeekends = DB::table('cache')->where('key', 'policy_allow_weekends')->first();
                $weekendsAllowed = ($allowWeekends && $allowWeekends->value == 'true');

                $requestedTime = Carbon::parse($data['requested_time']);
                $dayOfWeek = $requestedTime->dayOfWeek;
                if (!$weekendsAllowed && ($dayOfWeek == Carbon::SATURDAY || $dayOfWeek == Carbon::SUNDAY)) {
                    $errors[] = "Requested visit date is on a weekend. Organization policy forbids weekend visits except with manager overrides.";
                }
            }
        }

        // 5. Department Visitor Capacity Validation
        if (!empty($data['host_id']) && $host) {
            $dept = Department::find($host->department_id);
            if ($dept) {
                $requestedTime = Carbon::parse($data['requested_time']);
                // Count current active visitors in this department at this hour
                $activeCount = VisitRequest::where('status', 'checked_in')
                    ->whereHas('host', function($q) use ($dept) {
                        $q->where('department_id', $dept->id);
                    })
                    ->count();

                if ($activeCount >= $dept->capacity_limit) {
                    $errors[] = "Department visitor capacity reached ({$dept->capacity_limit}). Please choose a different date/slot.";
                }
            }
        }

        // 6. Organization Business Hours Policy — respects CEO policy_enforce_biz_hours setting
        $bizHoursPolicy = DB::table('cache')->where('key', 'policy_enforce_biz_hours')->first();
        $enforceBizHours = !($bizHoursPolicy && $bizHoursPolicy->value == 'false'); // default ON

        if ($enforceBizHours && !empty($data['requested_time'])) {
            $time = Carbon::parse($data['requested_time']);
            $hour = $time->hour;
            if ($hour < 8 || $hour >= 19) {
                $errors[] = "Visits must be requested during standard operating hours (08:00 to 19:00).";
            }
        }

        // 7. Site Level Capacity & Emergency Lockdown Checks (company/branch scoped)
        if (Lockdown::isLocked(
            isset($data['company_id']) ? (int) $data['company_id'] : null,
            isset($data['branch_id']) && $data['branch_id'] ? (int) $data['branch_id'] : null
        )) {
            $errors[] = "This location is in EMERGENCY LOCKDOWN. No check-ins or visitor scheduling are permitted until the lockdown is lifted.";
        }

        return [
            'passed' => empty($errors),
            'errors' => $errors,
            'blacklist_match' => $blacklistMatch
        ];
    }
}
