<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Canonical Commercial Modules
    |--------------------------------------------------------------------------
    |
    | These are the 14 standard commercial module keys available for package
    | assignment and tenant module entitlement enforcement.
    |
    */
    'canonical' => [
        'students',
        'staff',
        'attendance',
        'timetable',
        'exams',
        'fees',
        'library',
        'transport',
        'hostel',
        'inventory',
        'homework',
        'communication',
        'reports',
        'hr',
    ],

    /*
    |--------------------------------------------------------------------------
    | Human-readable Module Labels
    |--------------------------------------------------------------------------
    */
    'labels' => [
        'students'      => 'Student Management',
        'staff'         => 'Staff Directory',
        'attendance'    => 'Attendance Tracking',
        'timetable'     => 'Timetable & Schedules',
        'exams'         => 'Examinations & Marks',
        'fees'          => 'Fee Management',
        'library'       => 'Library Management',
        'transport'     => 'Transport & Vehicles',
        'hostel'        => 'Hostel Management',
        'inventory'     => 'Inventory & Assets',
        'homework'      => 'Homework & Lesson Plans',
        'communication' => 'Communication & Notices',
        'reports'       => 'Advanced Reports & Analytics',
        'hr'            => 'HR & Payroll Management',
    ],

    /*
    |--------------------------------------------------------------------------
    | Core / Unconditional Features
    |--------------------------------------------------------------------------
    |
    | These features are fundamental to the application and are never gated
    | behind package subscriptions or module checks.
    |
    */
    'core' => [
        'dashboard',
        'settings',
        'integrations',
        'admins',
    ],
];
