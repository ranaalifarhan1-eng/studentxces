# StudentXces Changelog

All meaningful product changes, enhancements, fixes and production releases
are recorded here.

## [2026-10-10 00:15 PKT] — Student & Parent Portal Credentials Architecture & Security Hardening

**Module:** Admissions / Authentication / Student & Parent Portals / Multi-Tenancy<br>
**Status:** Local / Uncommitted<br>
**Commit:** Pending

### Added
- **Flexible Username & Email Authentication:**
  - Added nullable unique `users.username` column and converted `users.email` to nullable.
  - Authentication pipeline (`LoginController`) accepts combined "Email or Username" input.
  - Automatically discriminates between email address (via RFC filter) and system-generated username, authenticating securely against `password`.
  - Case-insensitive username lookup with tenant context preservation.
  - Added `guardians.guardian_code` column with sequential tenant-scoped code generation (`PAR-00001`).
  - Tenant-scoped composite uniqueness: `UNIQUE (school_id, guardian_code)` on `guardians` table, allowing identical sequential codes (`PAR-00001`) across distinct schools while rejecting duplicate codes within the same school.
- **Deterministic Portal Credential Service (`PortalCredentialService`):**
  - Tenant-safe short school code resolution (`resolveSchoolCode`) using explicit settings, name acronyms (e.g. `LCS` for "Lahore Cambridge School"), or sanitized slug fallback.
  - Sequential, globally unique username generation:
    - Students: `{SCHOOL_CODE}-{ADMISSION_NO}` (e.g., `LCS-ADM-2026-0001`).
    - Guardians: `{SCHOOL_CODE}-PAR-{GUARDIAN_CODE}` (e.g., `LCS-PAR-00001`).
  - Automated dual provisioning during student admission:
    - Creates student portal account with Spatie `student` role.
    - Creates or links guardian portal account with Spatie `parent` role.
    - Link Existing Guardian support: prevents duplicate parent portal accounts for siblings while linking family units seamlessly without resetting existing parent credentials.
- **Secure Temporary Credential Lifecycle & Expiry Enforcement:**
  - Generates cryptographically secure temporary passwords.
  - Dual storage: one-way bcrypt hashed in `users.password`, encrypted via `Crypt::encryptString` in `users.temporary_password_encrypted`.
  - 14-day expiry via `users.temporary_password_expires_at`.
  - Enforced `users.must_change_password` flag.
  - Option B expiry semantics: expired temporary credentials cannot authenticate (logs out, rejects with descriptive message without leaking credentials), cannot be revealed by administrators (returns 422), and cannot be resent via email.
  - Mandatory First-Login Password Change flow: intercepting middleware (`EnsurePasswordIsChanged`), dedicated controller (`FirstLoginPasswordController`), and view (`FirstChangePassword.tsx`) which clears the encrypted temporary password, unsets `must_change_password`, and redirects user to their role-specific dashboard.
- **Production-Safe Permission Provisioning & RBAC:**
  - Additive idempotent data migration `database/migrations/2026_10_09_201500_add_portal_credential_permissions.php` creating `students.portal_credentials.view` and `students.portal_credentials.reset`.
  - Assigned by default strictly to `super-admin` and `school-admin` roles.
  - Explicitly denied to `teacher`, `accountant`, `receptionist`, `librarian`, and cross-tenant administrators.
- **Queue Payload Privacy & Worker Runtime Decryption:**
  - `SendPortalCredentialsEmailJob` serializes only non-secret identifiers (`userId`, `targetType`).
  - Personal contact emails and temporary passwords eliminated from queued payloads (`jobs.payload`).
  - Target contact email dynamically resolved at runtime from domain models (`$user->student?->email` or `$user->guardian?->email`).
  - In-memory decryption occurs only during worker execution, immediately unsetting sensitive plain strings after mailable dispatch.
- **Session Secret Elimination & Secure Reveal UX:**
  - Complete elimination of plaintext temporary credentials from Laravel session and flash storage (`admission_credentials`, `portal_credentials`, `guardian_credentials`).
  - Replaced with non-secret metadata flash `portal_account_created` (username, queued status, is_existing flag).
  - Profile view renders on-demand "View Student Credentials" / "View Parent Credentials" buttons calling authorized HTTPS reveal endpoints with audit logging.
- **Administrative Portal Management & Audit Controls:**
  - Student Profile view (`Show.tsx`) dual portal cards for Student and Parent:
    - Account status badges (Active/Inactive, Password Changed / Temporary Active / Expired).
    - Reveal Password modal with copy buttons.
    - Printable Credential Slip modal with school branding, student details, credentials, and parent portal info.
    - Password Reset action generating fresh temporary credentials and logging Spatie activity log audit records without plaintext passwords.
    - Shared Multi-Child Family Account warning banner and confirmation modal when resetting shared parent passwords.
    - Toggle portal status and Resend email actions.
  - Admission Form Step 2 ("Parent / Guardian") segmented selector:
    - "New Guardian" mode.
    - "Link Existing Guardian" mode with dynamic debounced search by name, phone, email, or guardian code, auto-populating contact info and showing existing enrolled siblings.
- **Test Suite (`tests/Feature/PortalAuthenticationAndCredentialsTest.php`):**
  - 21 comprehensive feature tests covering student and guardian auto-provisioning, username login, email login, case insensitivity, multi-school tenant-scoped guardian code uniqueness, global username uniqueness, sibling shared-guardian portal linking without duplicate users or credential resets, 14-day expiry check, temporary password decryption, mandatory first-login password update with temporary password wipe, mail failure rollback isolation, role/cross-tenant authorization, audit log generation, queue payload serialization privacy with sentinel checks, and session flash secret elimination.

---

## [2026-10-03 18:15 PKT] — Canonical Academic Year Matching & Super Admin Permission Fix

**Module:** Admissions / Fees / Multi-Tenancy<br>
**Status:** Production<br>
**Commit:** `a8e6ac3`

### Fixed
- **Admissions & Fee Setup:** Resolved fee structure lookup failure during student admission where active class fee structures failed to match equivalent academic sessions (e.g. `2026-2027` fee structure vs `Academic Year 2026-27` session).
  - Implemented canonical academic year alias resolution via `AcademicYear::getYearAliases()` and `AcademicYear::matchesYearString()`, supporting equivalent notation variants (`YYYY-YYYY`, `YYYY-YY`, `YYYY/YYYY`, `YYYY/YY`, and exact session name) derived deterministically from session dates or session name.
  - Strictly banned ambiguous single-year aliases (`2026`, `2027`) to eliminate unintended multi-year overlaps.
  - Exposed `year_aliases` attribute explicitly via accessor and targeted controller appending (`StudentController::create`, `FeeBulkAssignController::index`) for admission and fee bulk assign UI without global model append bloat.
  - Updated backend query resolution in `StudentController::feeStructures()` to match aliases via `whereIn('academic_year', $aliases)`.
  - Updated `StudentFeeAssignmentService` validation and assignment logic to accept equivalent canonical academic year strings.
  - Updated Inertia frontend components `Create.tsx` (Student Admission Step 4) and `BulkAssign.tsx` (Fee Bulk Assign) to resolve applicable fee structures across equivalent academic session representations.
- **Authorization & RBAC:** Resolved HTTP 403 Forbidden error encountered by platform super admins accessing permission-protected school administrative routes (including Bulk Fee Assignment `/school/fees/structures/bulk-assign`).
  - Added global `Gate::before()` authorization hook in `AppServiceProvider::boot()` granting super admins universal ability bypass across all permission checks.
  - Preserved standard `permission:fees.bulk_bill` route middleware without duplicate definitions.
  - Maintained strict tenant isolation invariants: all school-scoped resource queries, route model bindings, and operations remain strictly enforced within active school context (`active_school_id`), failing closed (HTTP 403/404) on cross-tenant attempts.

### Added
- Comprehensive test coverage for academic year alias matching, admission fee lookup, and super admin authorization:
  - `tests/Unit/AcademicYearTest.php`: Unit tests for date-based alias derivations, string matching, case/whitespace normalization, and single-year ambiguity prevention.
  - `tests/Feature/StudentAdmissionFeeStructureLookupTest.php`: Feature tests for admission page alias exposure, fee structures API matching, backward compatibility, and end-to-end student admission with fee assignment.
  - `tests/Feature/FeeBulkAssignAuthorizationTest.php`: Feature tests verifying super admin access via `Gate::before`, school admin permission checks, 403 enforcement for unauthorized roles, inactive school context redirection, and cross-tenant access fail-closed guards.
  - `tests/Feature/FeeChallanPrintDesignTest.php`: Made collection adjustment settlement-date test assertion deterministic with isolated `Carbon::setTestNow` scoping.


---

## [2026-10-03 15:15 PKT] — Lahore Cambridge Full Student & Financial Clean Reset

**Module:** Multi-Tenancy Data Maintenance / Admissions / Fees  
**Status:** Production (Executed on live production environment)  
**Commit:** `9827cd8`  

### Removed
- Executed full student and financial clean reset for Lahore Cambridge School (`school_id = 1`) inside a single atomic database transaction following verified leaf-to-root dependency hierarchy:
  - 80 student records (12 active, 68 soft-deleted) hard deleted from `students`
  - 80 guardian records deleted from `guardians`
  - 112 student attendance records deleted from `attendances` (staff attendance preserved)
  - 2 fee payments deleted from `fee_payments`
  - 1 fee challan header deleted from `fee_challans`
  - 1 fee challan line item deleted from `fee_challan_items`
  - 1 fee challan adjustment deleted from `fee_challan_adjustments`
  - 1 student fee structure assignment deleted from `student_fee_assignments`
  - 8 student fee discount records deleted from `student_fee_discounts`

### Changed
- Reset financial document sequences for Lahore Cambridge School (`school_id = 1`) in `school_document_sequences`:
  - `challan`: sequence reset from `1` to `0`
  - `fee_receipt`: sequence reset from `2` to `0`
- Admission sequence naturally resets to `ADM-2026-0001` based on clean student table count.

### Data / Migration Notes
- **Financial Balance & Entity Reconciliation:**
  - Active & soft-deleted students: 80 → 0
  - Guardians: 80 → 0
  - Student attendance: 112 → 0
  - Revenue (current month and all-time): PKR 0.00
  - Outstanding fees: PKR 0.00
  - Vouchers / challans: 0
  - Fee payments: 0
- **Preserved Master Setup:**
  - 100% of master setup preserved: classes (1 active, 20 total database rows), sections (0), subjects (0), academic years (1), fee categories (2 active, 5 total), fee structures (2 active, 35 total), staff (3), school admin users (1), school settings (3), departments (5), designations (4).
- **Tenant Isolation:**
  - Multi-tenancy integrity verified: other tenants' students (10), guardians (2), attendances (30), payments (3), and configurations remained completely untouched.
- **Backup Verification:**
  - Verified pre-execution MariaDB dump archived at `/opt/studentxces-backup/db/studentxces_prod_20261003_121124.sql.gz` (`gzip -t` verified).

---

## [2026-09-30 14:40 PKT] — Post-Reset Security Remediation & Tenant Delete Safety

**Module:** Security / Database Operations  
**Status:** Production (Executed on live production environment)  
**Commit:** `01ab707`  

### Security
- Rotated StudentXces production database credentials (`studentxces_user`) with a strong 48-character secret across MariaDB, container environment files, and application caches.
- Verified backup safety: confirmed established production backup script (`/opt/studentxces/scripts/backup.sh`) sources credentials safely from environment files, preventing plaintext credential exposure in command lines, history, or logs.
- Added mandatory `Tenant Delete Safety` rule to `AGENTS.md` requiring parent-derived ID captures for child tables lacking reliable tenant keys and banning ungrouped `OR` predicates in tenant deletions.

---

## [2026-09-30 01:25 PKT] — Lahore Cambridge Financial Clean Reset & Challan Sequence Reset

**Module:** Fees / Multi-Tenancy Data Maintenance  
**Status:** Production (Executed on live production environment)  
**Commit:** `651ba26` (Local docs update; operational execution)  

### Removed
- Removed test fee transaction records for Lahore Cambridge School (`school_id = 1`) in a single isolated atomic database transaction:
  - 3 fee payments (`fee_payments`)
  - 19 challan adjustments (`fee_challan_adjustments`)
  - 53 challan line items (`fee_challan_items`)
  - 53 fee challan headers (`fee_challans`)
  - 41 student fee structure assignments (`student_fee_assignments`)
  - 0 student fee discounts (`student_fee_discounts`)

### Changed
- Reset Lahore Cambridge School (`school_id = 1`) challan document sequence in `school_document_sequences` from `53` to `0`. Next newly generated challan will atomically increment to `1` (`CHL-2026-00001`). Other document types and school sequences were left untouched.

### Data / Migration Notes
- **Financial Balance Reconciliation:**
  - Total Revenue ("This Month's Fees" / All-Time Revenue) reset from PKR 7,000 / PKR 11,000 to **PKR 0.00**.
  - Outstanding Fees (Modern Challan + Legacy Pending) reset from PKR 160,999 to **PKR 0.00**.
  - Remaining fee challans, payments, adjustments, and assignments for `school_id = 1`: **0**.
- **Preserved Core Entities:**
  - All 63 students, 67 guardians, 3 staff members, 1 user, 15 classes, 1 academic year, 1 fee category, 1 fee structure, and 3 school settings completely preserved.
- **Tenant Isolation:**
  - Multi-tenancy integrity verified; other schools' payments (3 records in `fee_payments`) and data remained completely isolated and untouched.
- **Backup Verification:**
  - Verified pre-execution MariaDB dump archived at `/opt/studentxces/backups/studentxces_prod_pre_financial_reset_20260929_222126.sql.gz` (`gzip -t` verified).

## [2026-09-26 20:07 PKT] — Three-Copy Fee Challan Print Release

**Module:** Fees (Presentation & Printing)  
**Status:** Production (Deployed 2026-09-26 20:34 PKT)  
**Commit:** `3694d4d211f205f378033b42fb9071eff0b0b82b`  

### Added
- Standard Pakistani A4 landscape three-copy physical fee challan print layout:
  - **School / Office Copy**
  - **Bank Copy**
  - **Student / Parent Copy**
- Dedicated reusable slip component `AlliedFeeChallanSlip.tsx` designed according to Allied Schools physical reference standards.
- Print cutting guides between challan copies with dashed division lines and scissor glyphs (`✂`).
- Dynamic bank account presentation row (`bank_name`, `account_no`, `branch`, `iban`) rendered automatically when configured in school settings.
- Bulk challan class/month printing view (`BulkChallans.tsx`) updated to identical 3-copy landscape layout.
- Comprehensive automated test suite in `tests/Feature/FeeChallanPrintDesignTest.php` (15 tests passing, 222 assertions).

### Changed
- Converted fee challan print experience from single/two-copy portrait format to standardized A4 landscape (`@page { size: A4 landscape; margin: 5mm; }`).
- Optimized vertical slip compactness to ensure full fee details, totals, and signatures fit within printable landscape dimensions without page overflows.

### Fixed
- Fixed runtime error `ReferenceError: React is not defined` on challan view by eliminating bare `React.Fragment` runtime references in favor of explicit named imports.
- Hardened settlement stamp date logic: dynamically evaluates the latest event between cash payments (`FeePayment.payment_date`) and collection adjustments (`FeeChallanAdjustment.created_at`), ensuring accurate stamp date presentation for pure payments, partial payments followed by adjustments, adjustments followed by payments, and 100% waivers.
- Prevented challan stamps from erroneously falling back to `due_date`, `issue_date`, or browser system date.

### Deployment & Data Notes
- **Zero database migrations** introduced.
- **Zero schema changes**.
- Customer data 100% preserved (verified pre- and post-deployment record counts for Lahore Cambridge School `school_id=1` and system-wide totals).
- Rebuilt optimized production assets via `npm run build` and regenerated Laravel cache via `php artisan optimize`.

---

## [2026-09-22 03:35 PKT] — Student Financial Lifecycle, Bulk Billing & Collection Adjustments (P1Q.3)

**Module:** Fees / Accounting / Student Admission Lifecycle  
**Status:** Production (Deployed 2026-09-22; baseline commit prior to 3-copy release)  
**Commit:** `2d4af7200c6e66ed62fe8abda43cd839961cd31d`  

### Added
- Authoritative collection-time fee adjustment/concession system (`FeeChallanAdjustment` model and `FeeAdjustmentService`).
- Bulk fee assignment subsystem allowing class- and section-wide assignment of fee structures (`FeeBulkAssignController`, `FeeBulkAssignService`, `BulkAssign.tsx`).
- Integrated fee assignment and first challan voucher issuance directly into Student Admission workflow (`Students/Create.tsx`).
- Added `admission_voucher_policy` on fee structures (`mandatory`, `optional`, `excluded`).
- Multi-recipient email notification system (`FeeVoucherIssuedMail`) notifying parents/guardians upon challan generation.
- Lifecycle permissions: `fees.bulk_bill` and `fees.adjustment`.
- Comprehensive feature tests: `FeeBulkWorkflowTest`, `FeeCollectionAdjustmentTest`, `StudentAdmissionWorkflowTest`, and `StudentFinancialProfileAndPortalTest`.

### Changed
- Overhauled Fee Collection UI (`Collect.tsx`, `Payments.tsx`, `Outstanding.tsx`, `Structures.tsx`, `Students/Show.tsx`) to support real-time adjustments, ledger audit histories, and settlement badges (`Paid`, `Paid + Adjusted`, `Settled — Adjusted`).
- Standardized student financial profile to display unified ledger metrics with integer-cents precision.

### Fixed
- Concurrency hardening: enforced database row locking on challans during adjustment and payment recording to prevent double-collection race conditions.
- Prevented adjustments against void or fully settled challans.

### Data / Migration Notes
- Migration: `2026_09_18_220001_add_admission_voucher_policy_to_fee_structures_table.php`
- Migration: `2026_09_21_220000_add_fees_bulk_bill_permission.php`
- Migration: `2026_09_22_030000_create_fee_challan_adjustments_table.php`

---

## [2026-09-18 21:36 PKT] — Student Fee Assignments & Authoritative Financial Ledger

**Module:** Fees / Student Financial Ledger  
**Status:** Committed  
**Commit:** `eca120ea2dd9d9a420a4d19cfe718f1bcb6797aa`  

### Added
- Individualized student fee assignment engine (`StudentFeeAssignment` model and `StudentFeeAssignmentService`).
- Added `is_optional` flag on `fee_structures` enabling student-level elective fees (e.g. transport, lab, fine arts).
- Authoritative integer-cents financial account ledger via `StudentFinancialLedgerService` with school timezone awareness.
- Unified financial reconciliation engine reconciling modern structured challans and legacy direct fee payments without double-counting.
- Granular RBAC permissions for fee operations: `fees.assign`, `fees.discount`, `fees.structure`, `fees.collect`.

### Changed
- Updated `FeeBillingService` to bill students based on active individual fee assignments with fallback to legacy class-level structures.

### Fixed
- Enforced strict tenant-boundary isolation on fee assignment endpoints.
- Validated discount bounds and prevented active discount collisions.

### Data / Migration Notes
- Migration: `2026_09_18_210001_create_student_fee_assignments_table.php`
- Migration: `2026_09_18_210002_add_is_optional_to_fee_structures_table.php`
- Migration: `2026_09_18_210003_add_fee_lifecycle_permissions.php`

---

## [2026-09-18 18:13 PKT] — Fee Challans Architecture, Discounts & Idempotent Collection

**Module:** Fees / Core Billing Architecture  
**Status:** Committed  
**Commit:** `f4eab1d809c64867acd7a79cd64c7e27bf05a7a8`  

### Added
- Core challan-based billing engine introducing `FeeChallan` and `FeeChallanItem` models.
- Dedicated integer-cents arithmetic support utility `App\Support\Money` to eliminate floating-point rounding discrepancies.
- Multi-tenant document sequence numbering generator via `DocumentSequenceService` (`school_document_sequences`).
- Concession / discount architecture with `StudentFeeDiscount` model.
- Idempotency key tracking on fee payments (`idempotency_key` column on `fee_payments`) to reject duplicate payment submissions.
- Challan management web interfaces: `Challans.tsx`, `Challan.tsx`, `BulkChallans.tsx`, and `Discounts.tsx`.
- Student and Parent portal controllers updated to expose issued challans and payment receipts.

### Changed
- Migrated fee collection workflow from unstructured payments to challan-backed payments.
- Redesigned payment collection interface (`Collect.tsx`) and payment receipt layout (`Receipt.tsx`).

### Data / Migration Notes
- Migration: `2026_09_15_140001_create_school_document_sequences_table.php`
- Migration: `2026_09_15_140002_create_student_fee_discounts_table.php`
- Migration: `2026_09_15_140003_create_fee_challans_table.php`
- Migration: `2026_09_15_140004_create_fee_challan_items_table.php`
- Migration: `2026_09_15_140005_extend_fee_payments_for_challans.php`
- Migration: `2026_09_15_150001_add_idempotency_key_to_fee_payments_table.php`

---

## [2026-09-03 19:06 PKT] — Automated Domain Provisioning & Multi-Tenant Infrastructure

**Module:** Multi-Tenancy / Custom Domains & Subscriptions  
**Status:** Production (Deployed 2026-09-03 / 2026-09-04; previous production commit `11997c263b0a56e6cf6a9e01cad69e18e2a33ce3`)  
**Commit:** `11997c263b0a56e6cf6a9e01cad69e18e2a33ce3` (ops hardening follow-up: `0f9026fd65f298f4c1a32d5c430804c4e113e29d`)  

### Added
- Automated tenant custom domain provisioning background runner and Nginx virtual host management.
- `domain_provisioning_requests` table and domain status verification workflow.
- Commercial multi-term package and subscription snapshot architecture (`PackagePrice` model).
- Guided commercial onboarding wizard for self-service school creation.
- Production super-admin bootstrap utility with secure credentials handling.

### Changed
- Decoupled platform-level super admin operations from school-level administrative context.
- Optimized tenant domain resolution and dashboard query aggregation (`PerformanceOptimizationTest`).

### Security
- Enforced reverse-proxy trust and HTTPS asset URL generation across multi-tenant domains.
- Isolated DNS verification from domain activation to prevent subdomain hijacking.

### Data / Migration Notes
- Migration: `2026_09_02_224000_create_package_prices_table.php`
- Migration: `2026_09_02_224001_add_commercial_multi_term_fields_to_tables.php`
- Migration: `2026_09_03_190000_create_domain_provisioning_requests_table.php`
