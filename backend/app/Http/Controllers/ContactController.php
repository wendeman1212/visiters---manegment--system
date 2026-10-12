<?php

namespace App\Http\Controllers;

use App\Mail\VmsNotificationMail;
use App\Models\CompanySetting;
use App\Models\SystemNotification;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    /**
     * Handle a public contact inquiry.
     * The submitted message is emailed and sent by SMS to the company contact details
     * configured in the company settings (email) and the SMS gateway integration (phone).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'phone'   => 'nullable|string|max:50',
            'message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $company      = CompanySetting::first();
        $companyEmail = $company?->email;
        $companyPhone = $company?->phone;

        $body = "Name: {$request->name}"
              . ($request->phone ? "\nPhone: {$request->phone}" : '')
              . "\nEmail: {$request->email}"
              . "\n\nMessage:\n{$request->message}";

        $emailStatus = 'no-company-email';

        if ($companyEmail) {
            try {
                Mail::to($companyEmail)->send(new VmsNotificationMail(
                    'Website Contact Inquiry',
                    'New Contact Message',
                    $body,
                    $company->company_name ?? 'Administration Team',
                    [
                        'sender_name'  => $request->name,
                        'sender_email' => $request->email,
                        'sender_phone' => $request->phone ?? '',
                    ]
                ));
                $emailStatus = 'sent';
            } catch (\Throwable $e) {
                Log::error('Contact email send failed: ' . $e->getMessage());
                $emailStatus = 'failed';
            }
        } else {
            Log::warning('No company email configured; contact message was not emailed.');
        }

        $smsStatus = 'no-company-phone';

        if ($companyPhone) {
            $smsBody = "[VMS Contact] {$request->name}" . ($request->phone ? " ({$request->phone})" : '') . ": " . \Illuminate\Support\Str::limit($request->message, 140);
            $smsStatus = SmsService::send($companyPhone, $smsBody);
        } else {
            Log::warning('No company phone configured; contact message was not sent by SMS.');
        }

        SystemNotification::create([
            'event'          => 'Contact Message',
            'recipient_type' => 'email',
            'recipient_id'   => $companyEmail ?? 'no-company-email',
            'title'          => 'Contact Message from ' . $request->name,
            'message'        => $request->message,
            'data'           => [
                'sender_name'  => $request->name,
                'sender_email' => $request->email,
                'sender_phone' => $request->phone ?? '',
                'email_status' => $emailStatus,
                'sms_status'   => $smsStatus,
            ],
        ]);

        if ($emailStatus === 'sent') {
            return back()->with('success', 'Your message has been sent to the company. Thank you!');
        }

        $reason = $emailStatus === 'no-company-email'
            ? 'No company email address is configured.'
            : 'The email could not be delivered by the mail server.';

        return back()->with('error', 'Your message could not be delivered by email. ' . $reason . ' Please try again later.')->withInput();
    }
}
