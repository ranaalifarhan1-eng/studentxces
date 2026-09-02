# Landing Page Feature List — xgenious School Management System

> Purpose: Design brief for the product landing page on the xgenious company website.  
> Goal: Communicate product value clearly → drive free downloads → generate inbound leads.

---

## Page Goals

1. Immediately communicate what the product is and who it is for
2. List every meaningful feature so schools can evaluate fit before downloading
3. Make the free download frictionless (GitHub or direct ZIP)
4. Build trust (open-source, modern stack, active maintenance)
5. Convert interested visitors into xgenious custom-dev leads

---

## Target Audience

| Audience | Primary Need |
|---|---|
| School administrators | Replace spreadsheets / legacy software |
| IT officers / developers | Self-host on own server, low cost |
| Agency clients | White-label for their school customers |
| Developers | Extend or fork for custom use |

---

## Landing Page Sections

### 1. Hero Section

- **Headline:** "Free, Open-Source School Management System — Built for Modern Schools"
- **Sub-headline:** One sentence covering Laravel + React, multi-school, production-ready
- **Primary CTA:** `Download Free` (GitHub release / ZIP)
- **Secondary CTA:** `View Live Demo`
- **Hero visual:** Dashboard screenshot or animated UI preview
- **Trust badges:** Open Source · Free Forever · Laravel 11 · React 18

---

### 2. What Is It? (Brief Overview)

Short paragraph (3–4 sentences):
- Laravel 11 + React 18 + Inertia.js monolithic SPA
- Multi-school / multi-tenant out of the box
- 19 fully built modules covering every aspect of school operations
- Free, self-hosted, MIT licensed

---

### 3. Core Stats Bar

| Stat | Value |
|---|---|
| Modules | 19 |
| Roles | 8+ (Super Admin, Admin, Teacher, Accountant, Librarian, Driver, Parent, Student) |
| License | MIT (Free Forever) |
| Stack | Laravel 11 + React 18 |
| Database | MySQL 8 / PostgreSQL 16 |
| Languages | English, Bengali (RTL-ready) |

---

### 4. Feature Modules (Main Feature Section)

Each module gets a card or accordion entry with icon, name, and bullet list of sub-features.

---

#### Module 1 — Authentication & Access Control

- Role-based access control (RBAC) via Spatie Permissions
- 8 built-in roles: Super Admin, School Admin, Teacher, Accountant, Librarian, Driver, Parent, Student
- Custom permission assignment per role
- Two-factor authentication (TOTP) for Admin and Accountant roles
- Activity log — every login, role change, and financial action recorded
- Session management — active session list, remote logout
- Password reset via email

---

#### Module 2 — School Setup & Configuration

- Multi-school support — single installation, unlimited schools
- School profile: name, logo, address, contact, academic year
- Class management — add unlimited classes (Grade 1 → Grade 12 / O-Level / A-Level)
- Section management — multiple sections per class
- Subject management — assign subjects per class/section
- Shift management — morning, afternoon, evening shifts
- Academic year and semester configuration
- Holiday calendar management

---

#### Module 3 — Student Management

- Multi-step admission wizard
- Student profile with photo, documents, guardian info
- Document upload — birth certificate, previous transcripts, etc.
- Bulk student import via Excel/CSV template
- Student ID card generation (PDF)
- Class/section assignment and transfer
- Student promotion (year-end batch promotion)
- Alumni record retention after graduation
- Student search, filter, and export

---

#### Module 4 — Staff & HR Management

- Staff registration with profile photo and documents
- Department and designation management
- Staff attendance tracking
- Leave management — application → approval workflow
- Payroll structure builder (basic + allowances + deductions)
- Monthly payroll generation and payslip PDF
- Contract / appointment letter generation
- Staff ID card (PDF)
- Bulk staff import
- Separation and offboarding records

---

#### Module 5 — Attendance Management

- Daily attendance marking (class-wise)
- QR code scan attendance
- Calendar view — monthly attendance summary per student
- Late arrival tracking
- Parent SMS notification on absence
- Staff attendance (separate from student)
- Attendance report — by class, by date range, by student
- Bulk attendance entry
- Attendance percentage calculation

---

#### Module 6 — Timetable & Scheduling

- Drag-and-drop timetable builder
- Conflict detection (teacher/room double-booking)
- Period and subject assignment per class/section
- Room / lab management
- Exam schedule builder
- iCal export for timetable
- Timetable PDF export
- Substitution teacher assignment

---

#### Module 7 — Examination & Results

- Exam type setup (unit test, mid-term, final, etc.)
- Marks entry per subject per student
- Grade / GPA system configuration (custom grading scales)
- Report card PDF generation (async, queued)
- Merit list / ranking generation
- Tabulation sheet export (Excel / PDF)
- Subject-wise result analysis charts
- Cumulative result history per student
- Bulk marks import

---

#### Module 8 — Fee Management

- Fee category setup (tuition, transport, hostel, library, etc.)
- Fee structure builder per class/shift
- Fee collection UI with receipt generation (PDF)
- Fine / late payment penalty (automated cron job)
- Outstanding dues report
- Stripe online payment integration
- Payment webhook handler
- Dashboard widget — daily/monthly collection summary
- Fee concession / scholarship assignment
- Bulk fee posting

---

#### Module 9 — Library Management

- Book catalog with ISBN lookup (auto-fill metadata)
- Multiple copies tracking per title
- Book issue and return workflow
- Fine for late return (automated)
- E-library — digital book / PDF upload
- Member management (students + staff)
- Book availability search
- Library report — issued, overdue, fine collected

---

#### Module 10 — Transport Management

- Route management (start point → stops → school)
- Vehicle / bus fleet registry
- Driver and attendant assignment per vehicle
- Student transport assignment
- Driver portal (route and passenger list view)
- Live tracking stub (GPS integration point)
- Transport fee linked to Fee Management module
- Transport report — route-wise student list

---

#### Module 11 — Homework & Lesson Planning

- Homework assignment per class/section/subject
- File attachment support (PDF, images, docs)
- Student homework submission (with file upload)
- Teacher grading of submissions
- Lesson plan builder (topic, objectives, resources)
- Syllabus upload per class/subject
- Online class link management (Zoom / Google Meet)
- Academic calendar view

---

#### Module 12 — Communication

- School-wide announcements (role-targeted)
- Internal messaging (teacher ↔ admin, parent ↔ teacher)
- Noticeboard (public and internal)
- SMS blast — send to class / section / entire school
- Email blast — bulk email with template support
- Push notification support
- Parent portal — dedicated parent view for child progress
- Event calendar with notifications

---

#### Module 13 — Reports & Analytics

- Per-role dashboards (Admin, Teacher, Accountant, etc.)
- Custom report builder — filter by class, date range, module
- Student performance analytics (chart view)
- Fee collection analytics
- Attendance trend analysis
- Staff leave and payroll summary
- Audit log UI — full system activity trail
- Export all reports as PDF or CSV

---

#### Module 14 — System Administration

- Super Admin panel — manage all schools from one login
- School activation / deactivation
- Global system settings — SMTP, SMS gateway, storage driver
- Backup configuration (database + file)
- Queue monitor (Laravel Horizon dashboard)
- API key management
- Webhook configuration for external integrations
- Swagger API documentation (`/api/docs`)
- Role and permission editor UI
- Maintenance mode toggle

---

#### Module 16 — Admission Inquiry & CRM

- Inquiry form (public-facing or internal)
- Lead status pipeline — New → Contacted → Visit Scheduled → Enrolled → Rejected
- Visitor log with purpose and contact recording
- Follow-up reminders
- Inquiry-to-admission conversion
- Inquiry source tracking (walk-in, website, referral)
- Inquiry report and conversion rate analytics

---

#### Module 17 — Hostel Management

- Hostel block and room configuration
- Room type management (single, double, dormitory)
- Student room assignment
- Hostel fee linked to Fee Management module
- Warden assignment per block
- Hostel attendance (optional, separate from class attendance)
- Visitor log for hostel
- Hostel report — occupancy, vacancy

---

#### Module 18 — Inventory & Asset Management

- Asset category setup
- Asset registration (name, serial, purchase date, value)
- Asset assignment to staff or department
- Condition tracking (good, needs repair, disposed)
- Maintenance request log
- Inventory stock register (stationery, lab consumables, etc.)
- Stock purchase and issue records
- Low-stock alert
- Asset and inventory reports (export PDF / Excel)

---

#### Module 19 — Subscription & Package Management

- Subscription plan configuration (Basic, Standard, Premium)
- Per-plan feature limits (students, staff, storage, modules)
- Plan assignment per school
- Renewal date tracking and expiry alerts
- Usage stats per school (student count, storage used)
- Stripe billing integration for SaaS mode (optional)
- Grace period configuration after expiry
- Subscription history log

---

### 5. Technical Highlights Section

Appeal to developers and IT officers.

| Highlight | Detail |
|---|---|
| Stack | Laravel 11 · PHP 8.3 · React 18 · TypeScript · Inertia.js |
| Database | MySQL 8 or PostgreSQL 16 |
| Auth | Laravel Sanctum + Spatie RBAC |
| Queue | Laravel Horizon (Redis) |
| Storage | Local · MinIO · AWS S3-compatible |
| PDF Engine | DomPDF / Snappy (async queued) |
| Dark Mode | Full dark mode via Tailwind |
| i18n | English, Bengali — RTL-ready (Arabic, Urdu) |
| API | REST API with Swagger docs |
| Testing | PestPHP · Vitest · Playwright E2E |
| Multi-tenancy | school_id global scope — zero cross-school data leakage |
| Accessibility | WCAG AA · ARIA-compliant · keyboard navigable |
| Performance | < 500ms page load · async PDF · queue-backed imports |
| Security | CSRF · parameterized SQL · MIME-validated uploads · 2FA · rate limiting |

---

### 6. Who Is This For? (Use Case Cards)

**Small Schools (under 500 students)**  
Replace paper registers and spreadsheets. Free self-hosted deployment on any shared hosting or VPS.

**Large Schools & Colleges**  
Multi-section, multi-shift, multi-building support. Handles thousands of students, staff, and transactions.

**Government / NGO Schools**  
Zero license cost. Full data ownership. Works offline-friendly with PWA.

**Software Agencies**  
White-label for clients. MIT license — fork, customize, resell services on top.

**Developers**  
Clean modular codebase. Laravel 11 + React 18 + TypeScript. Extend it, contribute to it.

---

### 7. Screenshots / Demo Section

- Dashboard overview (Admin view)
- Student admission wizard
- Timetable drag-and-drop builder
- Fee collection + receipt
- Report card PDF preview
- Mobile / dark mode view
- Live demo link with demo credentials (`admin@demo.com / password`)

---

### 8. Installation Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.3+ |
| Database | MySQL 8+ or PostgreSQL 16+ |
| Node.js | 20+ (for building frontend assets) |
| Redis | 6+ (for queue and cache) |
| Composer | 2.x |
| Web Server | Apache or Nginx |
| Storage | 1 GB minimum |

Quick install steps (3-liner teaser on landing page):
```bash
git clone https://github.com/XgeniousLLC/genius-school-management-system.git
cp .env.example .env && composer install && npm install && npm run build
php artisan migrate --seed
```

Full guides available for:
- VPS / Ubuntu server
- cPanel / shared hosting
- Docker (coming soon)

---

### 9. Download & Get Started Section

Primary actions — make these big and obvious:

| Action | Button Label | Destination |
|---|---|---|
| Download ZIP | `Download v1.0.0 (Free)` | GitHub releases page |
| View source | `GitHub Repository` | GitHub repo |
| Read docs | `Documentation` | `/docs` or GitHub wiki |
| Live demo | `Try Live Demo` | Demo server |

No account required to download.

---

### 10. Custom Development CTA (Lead Generation)

This section converts downloaders into xgenious clients.

**Headline:** "Need something beyond the free version?"

**Sub-points:**
- Custom module development
- White-label branding and design
- Dedicated hosting setup and maintenance
- Migration from legacy school software
- Integration with payment gateways, SMS providers, ERP systems
- Priority support and SLA

**CTA:** `Contact xgenious for Custom Development`  
→ Link to contact form or WhatsApp

---

### 11. FAQ Section

| Question | Answer (summary) |
|---|---|
| Is it really free? | Yes. MIT license. No hidden fees, no feature locks in the free version. |
| Can I use it commercially? | Yes. MIT license allows commercial use. |
| Does it support multiple schools? | Yes. Single installation, unlimited schools, fully isolated data. |
| Can I modify the code? | Yes. Fork it, customize it, redistribute it. |
| Is there a cloud-hosted version? | Not officially. Self-host on your own server. xgenious can help with setup. |
| What languages does it support? | English and Bengali out of the box. RTL-ready for Arabic and Urdu. |
| Where do I report bugs? | GitHub Issues page. |
| Can I get paid support? | Yes. Contact xgenious for support plans. |

---

### 12. Footer

- Product name + version
- License: MIT
- Links: GitHub · Documentation · Demo · Contact
- xgenious company name + link
- "Built with Laravel + React"

---

## SEO Metadata Targets

| Field | Value |
|---|---|
| Page title | Free School Management System — Laravel + React | xgenious |
| Meta description | Download a free, open-source School Management System built with Laravel 11 and React 18. 19 modules including attendance, fees, exams, payroll, and more. |
| Primary keywords | free school management system, open source school ERP, Laravel school software, school management Laravel React |
| OG image | Dashboard screenshot at 1200×630 |

---

## Design Notes for Developer / Designer

- Hero CTA must be above the fold on mobile
- Feature module cards: icon + title + 4–6 bullet points each
- Stats bar: high contrast, large numbers
- Screenshots: real UI, not stock photos
- Download button: sticky on scroll (mobile)
- Dark / light mode support on landing page itself
- Color palette: align with shadcn/ui theme used in the app
- No registration wall before download — friction kills conversion
