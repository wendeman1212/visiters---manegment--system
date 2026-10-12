<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\VisitGroupMember;
use App\Models\VisitRequest;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class QrBadgeService
{
    public static function generateVisitBadge(VisitRequest $visit): string
    {
        $visit->loadMissing(['visitor', 'host.department', 'department', 'location', 'qrToken']);
        $visitor = $visit->resolveVisitor();

        $type = $visit->visit_type === 'company'
            ? 'GROUP REPRESENTATIVE PASS'
            : 'VISITOR PASS';

        return self::render([
            'type'           => $type,
            'token'          => $visit->qrToken ? $visit->qrToken->token_value : '',
            'name'           => $visitor?->name ?? $visit->company_name ?? 'Visitor',
            'id_document_no' => $visitor?->id_document_no ?? '',
            'company'        => $visit->company_name ?: self::companyName(),
            'visit_date'     => $visit->requested_time ? $visit->requested_time->format('D, M d Y') : '',
            'visit_time'     => $visit->requested_time ? $visit->requested_time->format('h:i A') : '',
            'duration'       => $visit->duration ? $visit->duration . ' min' : '',
            'host'           => $visit->host->name ?? '',
            'department'     => $visit->department->name ?? ($visit->host ? ($visit->host->department->name ?? '') : ''),
            'purpose'        => $visit->purpose ?? '',
            'location'       => trim(implode(', ', array_filter([
                $visit->building,
                $visit->floor ? 'Floor ' . $visit->floor : '',
                $visit->meeting_room_id,
            ]))) ?: ($visit->location->name ?? ''),
        ]);
    }

    /**
     * Generate an EXIT badge for the visitor/representative using the visit's exit QR token.
     */
    public static function generateVisitExitBadge(VisitRequest $visit): string
    {
        $visit->loadMissing(['visitor', 'host.department', 'department', 'location']);
        $visitor = $visit->resolveVisitor();

        $type = $visit->visit_type === 'company'
            ? 'GROUP REPRESENTATIVE EXIT PASS'
            : 'VISITOR EXIT PASS';

        return self::render([
            'type'           => $type,
            'token'          => $visit->exit_qr_token ?? '',
            'name'           => $visitor?->name ?? $visit->company_name ?? 'Visitor',
            'id_document_no' => $visitor?->id_document_no ?? '',
            'company'        => $visit->company_name ?: self::companyName(),
            'visit_date'     => $visit->requested_time ? $visit->requested_time->format('D, M d Y') : '',
            'visit_time'     => $visit->requested_time ? $visit->requested_time->format('h:i A') : '',
            'duration'       => $visit->duration ? $visit->duration . ' min' : '',
            'host'           => $visit->host->name ?? '',
            'department'     => $visit->department->name ?? ($visit->host ? ($visit->host->department->name ?? '') : ''),
            'purpose'        => $visit->purpose ?? '',
            'location'       => trim(implode(', ', array_filter([
                $visit->building,
                $visit->floor ? 'Floor ' . $visit->floor : '',
                $visit->meeting_room_id,
            ]))) ?: ($visit->location->name ?? ''),
            'footer'         => 'Present this pass at the security EXIT gate for checkout',
        ]);
    }

    public static function generateMemberBadge(VisitRequest $visit, VisitGroupMember $member): string
    {
        $visit->loadMissing(['host.department', 'department', 'location']);

        return self::render([
            'type'           => 'GROUP MEMBER PASS',
            'token'          => $member->qr_token ?? '',
            'name'           => $member->name ?? 'Group Member',
            'id_document_no' => $member->id_document_no ?? '',
            'company'        => $visit->company_name ?: self::companyName(),
            'visit_date'     => $visit->requested_time ? $visit->requested_time->format('D, M d Y') : '',
            'visit_time'     => $visit->requested_time ? $visit->requested_time->format('h:i A') : '',
            'duration'       => $visit->duration ? $visit->duration . ' min' : '',
            'host'           => $visit->host->name ?? '',
            'department'     => $visit->department->name ?? ($visit->host ? ($visit->host->department->name ?? '') : ''),
            'purpose'        => $visit->purpose ?? '',
            'location'       => trim(implode(', ', array_filter([
                $visit->building,
                $visit->floor ? 'Floor ' . $visit->floor : '',
                $visit->meeting_room_id,
            ]))) ?: ($visit->location->name ?? ''),
        ]);
    }

    /**
     * Generate an EXIT badge for a group member using their exit QR token.
     */
    public static function generateMemberExitBadge(VisitRequest $visit, VisitGroupMember $member): string
    {
        $visit->loadMissing(['host.department', 'department', 'location']);

        return self::render([
            'type'           => 'GROUP MEMBER EXIT PASS',
            'token'          => $member->exit_qr_token ?? '',
            'name'           => $member->name ?? 'Group Member',
            'id_document_no' => $member->id_document_no ?? '',
            'company'        => $visit->company_name ?: self::companyName(),
            'visit_date'     => $visit->requested_time ? $visit->requested_time->format('D, M d Y') : '',
            'visit_time'     => $visit->requested_time ? $visit->requested_time->format('h:i A') : '',
            'duration'       => $visit->duration ? $visit->duration . ' min' : '',
            'host'           => $visit->host->name ?? '',
            'department'     => $visit->department->name ?? ($visit->host ? ($visit->host->department->name ?? '') : ''),
            'purpose'        => $visit->purpose ?? '',
            'location'       => trim(implode(', ', array_filter([
                $visit->building,
                $visit->floor ? 'Floor ' . $visit->floor : '',
                $visit->meeting_room_id,
            ]))) ?: ($visit->location->name ?? ''),
            'footer'         => 'Present this pass at the security EXIT gate for checkout',
        ]);
    }

    /**
     * Generate a badge for a group member, persist it to storage and return the
     * storage-relative path. Used by GroupVisitQrService.
     */
    public function generateBadgeForGroupMember(VisitGroupMember $member): string
    {
        $visit = $member->visitRequest;

        if (!$visit) {
            return '';
        }

        $svg = self::generateMemberBadge($visit, $member);
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $member->name ?: 'member');

        $path = 'badges/group-member-' . $name . '-' . $member->id . '-' . Str::random(6) . '.svg';
        Storage::disk('public')->put($path, $svg);

        return $path;
    }

    protected static function companyName(): string
    {
        $setting = CompanySetting::first();

        return $setting && $setting->company_name
            ? $setting->company_name
            : config('app.name', 'VMS Enterprise');
    }

    /**
     * The payload encoded inside the QR matrix.
     *
     * The existing system-generated visitor number (the QR token) is kept as the
     * visible token label, while the QR matrix encodes a link to the public
     * mobile page. Scanning the badge with a phone opens that page, which uses
     * the visitor number to load the matching visitor record.
     *
     * The URL is built by QrUrlService so it always points at a real,
     * reachable server address (LAN IP in development, domain in production)
     * and never at "localhost" — a phone that scans "localhost" would open
     * its own loopback interface instead of this server.
     */
    public static function scanPayload(string $token): string
    {
        return QrUrlService::generate($token);
    }

    protected static function qrDataUri(string $token): string
    {
        if ($token === '') {
            return '';
        }

        $options = new QROptions([
            'outputBase64'  => true,
            'scale'         => 8,
            'eccLevel'      => EccLevel::M,
            'addQuietzone'  => true,
            'quietzoneSize' => 2,
        ]);

        try {
            return (new QRCode($options))->render(self::scanPayload($token));
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected static function esc($value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected static function render(array $d): string
    {
        $qr = self::qrDataUri($d['token'] ?? '');
        $qrBlock = $qr !== ''
            ? '<image href="' . self::esc($qr) . '" x="140" y="382" width="180" height="180" preserveAspectRatio="xMidYMid meet"/>'
            : '<text x="230" y="475" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="13" fill="#94a3b8">No QR token available</text>';

        $tokenText = self::esc($d['token'] ?? '');
        $tokenLabel = $tokenText !== ''
            ? '<text x="230" y="602" text-anchor="middle" font-family="monospace" font-size="11" fill="#475569">' . $tokenText . '</text>'
            : '';

        $details = '';
        $rows = [
            ['Date', $d['visit_date'] ?? ''],
            ['Time', $d['visit_time'] ?? ''],
            ['Host', $d['host'] ?? ''],
            ['Department', $d['department'] ?? ''],
            ['Purpose', $d['purpose'] ?? ''],
            ['Location', $d['location'] ?? ''],
        ];

        $y = 222;
        foreach ($rows as [$label, $value]) {
            if ($value === '') {
                continue;
            }
            $details .= '<text x="40" y="' . $y . '" font-family="Inter,Arial,sans-serif" font-size="11" fill="#94a3b8">' . self::esc($label) . '</text>';
            $details .= '<text x="150" y="' . $y . '" font-family="Inter,Arial,sans-serif" font-size="12.5" font-weight="600" fill="#1e293b">' . self::esc(self::truncate($value, 42)) . '</text>';
            $y += 22;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="460" height="670" viewBox="0 0 460 670">
  <defs>
    <linearGradient id="hdr" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#1e3a8a"/>
      <stop offset="1" stop-color="#4338ca"/>
    </linearGradient>
  </defs>
  <rect width="460" height="670" rx="16" fill="#ffffff"/>
  <rect width="460" height="128" rx="16" fill="url(#hdr)"/>
  <rect y="96" width="460" height="32" fill="url(#hdr)"/>
  <text x="230" y="30" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="10" font-weight="700" letter-spacing="3" fill="#a5b4fc">VMS ENTERPRISE</text>
  <text x="230" y="64" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="24" font-weight="800" letter-spacing="1" fill="#ffffff">' . self::esc($d['type'] ?? 'VISITOR PASS') . '</text>
  <text x="230" y="92" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="13" font-weight="600" fill="#c7d2fe">' . self::esc($d['company'] ?? '') . '</text>
  <text x="40" y="160" font-family="Inter,Arial,sans-serif" font-size="22" font-weight="800" fill="#0f172a">' . self::esc($d['name'] ?? '') . '</text>
  <text x="40" y="182" font-family="Inter,Arial,sans-serif" font-size="12" fill="#64748b">ID: ' . self::esc($d['id_document_no'] ?? '') . '</text>
  <line x1="40" y1="202" x2="420" y2="202" stroke="#e2e8f0" stroke-width="1"/>
  ' . $details . '
  <rect x="115" y="352" width="230" height="262" rx="12" fill="#f8fafc" stroke="#e2e8f0" stroke-width="1"/>
  ' . $qrBlock . '
  ' . $tokenLabel . '
  <text x="230" y="648" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="10" fill="#94a3b8">' . self::esc($d['footer'] ?? 'Present this pass at the security gate') . '</text>
</svg>';
    }

    protected static function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1) . '…';
    }
}
