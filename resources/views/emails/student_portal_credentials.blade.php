<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal Login Credentials — {{ $schoolName }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 24px; }
        .card { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .header { background: #4f46e5; color: #ffffff; padding: 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 700; }
        .header p { margin: 6px 0 0; font-size: 13px; opacity: 0.9; }
        .body { padding: 24px; }
        .greeting { font-size: 15px; font-weight: 600; margin-bottom: 12px; }
        .credential-box { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px; margin: 18px 0; }
        .cred-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .cred-label { color: #64748b; font-weight: 500; }
        .cred-val { font-family: monospace; font-size: 15px; font-weight: 700; color: #0f172a; }
        .btn { display: inline-block; background: #4f46e5; color: #ffffff !important; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; margin-top: 14px; }
        .notice { font-size: 13px; color: #b45309; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 6px; padding: 12px; margin-top: 16px; }
        .footer { background: #f8fafc; padding: 16px 24px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1>{{ $schoolName }}</h1>
            <p>Student Portal Login Access</p>
        </div>
        <div class="body">
            <p class="greeting">Dear {{ $studentName }},</p>
            <p style="font-size: 14px; line-height: 1.5; color: #475569;">
                Your student portal account for <strong>{{ $schoolName }}</strong> has been activated. You can use the credentials below to log in and access your timetable, attendance, marks, and fee vouchers.
            </p>

            <div class="credential-box">
                @if($admissionNo)
                <div class="cred-row">
                    <span class="cred-label">Admission No:</span>
                    <span class="cred-val">{{ $admissionNo }}</span>
                </div>
                @endif
                <div class="cred-row">
                    <span class="cred-label">Username:</span>
                    <span class="cred-val">{{ $username }}</span>
                </div>
                <div class="cred-row" style="margin-bottom: 0;">
                    <span class="cred-label">Temporary Password:</span>
                    <span class="cred-val" style="color: #4f46e5;">{{ $tempPassword }}</span>
                </div>
            </div>

            <p style="text-align: center;">
                <a href="{{ $loginUrl }}" class="btn">Log In to Student Portal</a>
            </p>

            <div class="notice">
                <strong>Security Notice:</strong> For your security, this temporary password will expire. You will be prompted to set your own new private password upon your first login.
            </div>
        </div>
        <div class="footer">
            This is an automated security notification from {{ $schoolName }}. Please do not reply directly to this email.
        </div>
    </div>
</body>
</html>
