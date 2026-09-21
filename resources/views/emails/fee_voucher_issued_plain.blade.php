Dear {{ $recipientRole === 'guardian' ? ($recipientName ?: 'Parent / Guardian') : $studentName }},

A fee voucher for '{{ $billingLabel }}' has been issued for {{ $studentName }}.

VOUCHER DETAILS:
--------------------------------------------------
School:          {{ $schoolName }}
Student Name:    {{ $studentName }}
Admission No:    {{ $admissionNo }}
Class & Section: {{ $classSection }}
Academic Year:   {{ $academicYear }}
Challan Number:  {{ $challanNo }}
Billing Label:   {{ $billingLabel }}
Fee Head(s):     {{ $feeHeads }}
Gross Amount:    PKR {{ $grossAmount }}
Concession:      PKR {{ $concessionAmount }}
Amount Payable:  PKR {{ $amountPayable }}
Due Date:        {{ $dueDate }}
--------------------------------------------------

Please ensure payment is submitted on or before the due date ({{ $dueDate }}).

Regards,
Accounts & Fee Department
{{ $schoolName }}
