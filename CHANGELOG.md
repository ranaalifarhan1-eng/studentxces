# StudentXces Changelog

All meaningful product changes, enhancements, fixes and production releases
are recorded here.

## [Unreleased]

---

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
