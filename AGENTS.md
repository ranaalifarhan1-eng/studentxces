# Agent Operating Guidelines & Rules — StudentXces

This document defines mandatory rules, operational boundaries, and maintenance protocols for all AI coding agents working on StudentXces.

---

## 1. Workspace Boundaries & Operational Scope

### LOCAL FILESYSTEM RULE
All local source-code inspection, editing, searching, and command execution must remain strictly inside:
`D:\pakalfa\Lahore Cambridge School\app.lahorecambridgeschool`

Never inspect unrelated local projects, credentials, SSH folders, Gemini/Antigravity transcripts, WAManager, Chatwoot, or other local directories.

### PRODUCTION EXCEPTION
Production SSH/server operations are permitted **ONLY** when explicitly requested or approved by the user for a production task.
Such operations must remain strictly limited to StudentXces infrastructure (`/opt/studentxces`) and must never touch WAManager, Chatwoot, or unrelated host services/projects.

---

## 2. Mandatory Changelog Rule

> [!IMPORTANT]
> **CHANGELOG RULE**
> Before completing any meaningful StudentXces feature, enhancement, bug fix, security change, migration, integration or production release, updating `CHANGELOG.md` is **mandatory**.

### Requirements:
1. **Timestamping:** Always use `Asia/Karachi` timezone (`PKT`) with timestamps formatted as:  
   `YYYY-MM-DD HH:MM PKT` (e.g., `2026-09-26 20:00 PKT`).
2. **Metadata to Record:**
   - **Date & Time:** In `PKT`.
   - **Feature / Module:** (e.g. `Fees`, `Multi-Tenancy`, `Admissions`, `Academics`).
   - **Status:** Clearly distinguish between `Committed` (local/merged to branch) and `Production` (deployed to live environment).
   - **Commit Hash:** Record the full or short git commit SHA once committed.
   - **Standard Category Headings:** Use only relevant categories:
     - `### Added`
     - `### Changed`
     - `### Enhanced`
     - `### Fixed`
     - `### Security`
     - `### Deprecated`
     - `### Removed`
     - `### Deployment & Data Notes` / `### Data / Migration Notes`
3. **What MUST Be Logged:**
   - New modules or features.
   - Significant enhancements to existing workflows.
   - Changes in business, accounting, or billing logic.
   - Meaningful bug fixes and stability repairs.
   - Security hardening, role/permission updates.
   - Database migrations and schema modifications.
   - Production deployments with verification notes.
4. **What MUST NOT Be Logged:**
   - Trivial typos or cosmetic whitespace/formatting-only edits.
   - Temporary debugging statements or failed exploratory attempts.
   - Test-only scratch fixtures or local QA fixtures unless they materially affect product design.
5. **Absolute Security & Privacy Ban:**
   - `CHANGELOG.md` MUST **NEVER** contain passwords, API secrets, SSH keys, database credentials, encryption tokens, or customer personally identifiable information (PII). Generic record counts and migration names are permitted.

---

## 3. Production Safety Standards

- **Controlled Deployments:** Never push to production remotes or execute server deployments without explicit instruction, preflight verification, and a timestamped verified database backup (`gzip -t` verified).
- **Customer Data Integrity:** Every release must guarantee existing tenant data is preserved. Pre- and post-deployment record reconciliations are required for production operations.
- **Idempotency & Accounting:** Always enforce integer-cents precision (`App\Support\Money`), unique sequence numbering, and idempotency keys on payment transactions.
