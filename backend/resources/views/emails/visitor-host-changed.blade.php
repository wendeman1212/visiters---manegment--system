<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Changed</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f4f8; margin: 0; padding: 0; }
        .container { max-width: 520px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 28px 32px; text-align: center; }
        .header h1 { margin: 0; font-size: 18px; letter-spacing: 1px; }
        .header p { margin: 6px 0 0; opacity: 0.85; font-size: 13px; }
        .body { padding: 28px 32px; }
        .field { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        .field:last-child { border-bottom: none; }
        .label { color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; font-size: 11px; }
        .value { font-weight: 700; color: #1a1a2e; }
        .changed { color: #ef4444; }
        .changed .value { color: #10b981; }
        .footer { text-align: center; padding: 20px 32px; font-size: 11px; color: #aaa; border-top: 1px solid #f0f0f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>HOST ASSIGNMENT UPDATED</h1>
            <p>Your visit host has been changed</p>
        </div>
        <div class="body">
            <div class="field changed">
                <span class="label">Previous Host</span>
                <span class="value" style="color:#ef4444">{{ $previousHostName }}</span>
            </div>
            <div class="field changed">
                <span class="label">New Host</span>
                <span class="value" style="color:#10b981">{{ $newHostName }}</span>
            </div>
            <div class="field">
                <span class="label">Visitor</span>
                <span class="value">{{ $visitorName }}</span>
            </div>
            <div class="field">
                <span class="label">Company</span>
                <span class="value">{{ $companyName }}</span>
            </div>
            <div class="field">
                <span class="label">Appointment</span>
                <span class="value">{{ $appointmentDate }}</span>
            </div>
            <div class="field">
                <span class="label">Purpose</span>
                <span class="value">{{ $purpose }}</span>
            </div>
        </div>
        <div class="footer">
            VMS Enterprise &middot; Visitor Management System<br>
            Please contact your new host for any questions regarding your visit.
        </div>
    </div>
</body>
</html>
