<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Voucher Issued — {{ $schoolName }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 24px; }
        .card { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .header { background: #4f46e5; color: #ffffff; padding: 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 700; }
        .header p { margin: 6px 0 0; font-size: 13px; opacity: 0.9; }
        .body { padding: 24px; }
        .greeting { font-size: 15px; font-weight: 600; margin-bottom: 12px; }
        .info-table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px; }
        .info-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
        .info-table td.label { color: #64748b; font-weight: 500; width: 40%; }
        .info-table td.val { font-weight: 600; color: #0f172a; text-align: right; }
        .highlight-row td { background-color: #f8fafc; font-size: 15px; font-weight: 700; color: #4f46e5; border-top: 2px solid #e2e8f0; border-bottom: 2px solid #e2e8f0; }
        .footer { background: #f8fafc; padding: 16px 24px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1>{{ $schoolName }}</h1>
            <p>Fee Voucher Notification</p>
        </div>
        <div class="body">
            <p class="greeting">Dear {{ $recipientRole === 'guardian' ? ($recipientName ?: 'Parent / Guardian') : $studentName }},</p>
            <p style="font-size: 14px; line-height: 1.5; color: #475569;">
                A fee voucher for <strong>{{ $billingLabel }}</strong> has been issued for <strong>{{ $studentName }}</strong>.
            </p>

            <table class="info-table">
                <tr>
                    <td class="label">Student Name</td>
                    <td class="val">{{ $studentName }}</td>
                </tr>
                <tr>
                    <td class="label">Admission No</td>
                    <td class="val">{{ $admissionNo }}</td>
                </tr>
                <tr>
                    <td class="label">Class & Section</td>
                    <td class="val">{{ $classSection }}</td>
                </tr>
                <tr>
                    <td class="label">Academic Year</td>
                    <td class="val">{{ $academicYear }}</td>
                </tr>
                <tr>
                    <td class="label">Challan Number</td>
                    <td class="val">{{ $challanNo }}</td>
                </tr>
                <tr>
                    <td class="label">Billing Label</td>
                    <td class="val">{{ $billingLabel }}</td>
                </tr>
                <tr>
                    <td class="label">Fee Head(s)</td>
                    <td class="val">{{ $feeHeads }}</td>
                </tr>
                <tr>
                    <td class="label">Gross Amount</td>
                    <td class="val">PKR {{ $grossAmount }}</td>
                </tr>
                <tr>
                    <td class="label">Concession</td>
                    <td class="val">PKR {{ $concessionAmount }}</td>
                </tr>
                <tr class="highlight-row">
                    <td class="label" style="color: #4f46e5;">Amount Payable</td>
                    <td class="val" style="color: #4f46e5;">PKR {{ $amountPayable }}</td>
                </tr>
                <tr>
                    <td class="label">Due Date</td>
                    <td class="val" style="color: #dc2626;">{{ $dueDate }}</td>
                </tr>
            </table>

            <p style="font-size: 13px; color: #64748b; line-height: 1.5;">
                Please ensure payment is submitted on or before the due date ({{ $dueDate }}). For inquiries, please contact the school fee counter.
            </p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ $schoolName }}. Accounts & Fee Department. All rights reserved.
        </div>
    </div>
</body>
</html>
