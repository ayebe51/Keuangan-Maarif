# IMPLEMENTATION PLAN REV.1 — SISTEM KEUANGAN LP MA'ARIF

**Specification of Record:** Spesifikasi_Teknis_Sistem_Keuangan_LP_Maarif_REV3_Production_Ready.docx  
**Golden Dataset:** Workbook Maret 2026  
**Status:** Revised baseline sebelum Phase 1

## 1. Tujuan Revisi

Implementation Plan sebelumnya sudah kuat pada domain accounting, tetapi perlu dikoreksi pada:
- keputusan technology stack;
- pemisahan domain accounting dari HTTP/UI;
- urutan fase pembangunan;
- penggunaan golden dataset sebagai fixture sejak awal;
- organization/tenant sebagai security boundary;
- validasi jurnal yang tidak cukup direpresentasikan sebagai row-level SQL CHECK;
- accounting dimensions;
- source-of-truth hierarchy;
- phase gate yang tegas.

Accounting rules Rev.3 **tidak diubah**.

## 2. Technology Decision

### Frontend
- Next.js
- TypeScript
- Tailwind CSS
- TanStack Query
- React Hook Form
- Zod

### Backend
- Laravel
- PHP 8.3+
- Laravel API
- Laravel Queue

### Data
- PostgreSQL
- Redis
- S3-compatible object storage

### Testing
- Pest/PHPUnit
- Playwright
- PHPStan/Larastan
- TypeScript/ESLint

### Auth
- Laravel Sanctum
- Argon2id
- RBAC + organization scope

### Deployment
- Docker
- Nginx/reverse proxy
- VPS/managed container untuk MVP

**Keputusan:** Laravel dipilih sebagai backend utama. Node.js/Express tetap viable, tetapi tidak diperlukan untuk MVP karena aplikasi ini lebih mengutamakan transaction integrity, accounting correctness, auditability, database consistency, dan maintainability daripada real-time I/O.

## 3. Architecture Decision

Gunakan **Modular Monolith**, bukan microservices.

```text
Next.js
   |
 HTTPS/JSON
   |
Laravel API
   |
+--+-----------------------------+
| Domain | Application | Infra   |
+-------------------------------+
   |
PostgreSQL
   |
Redis / Queue
   |
Object Storage
```

Microservices hanya dipertimbangkan jika kebutuhan nyata muncul setelah MVP.

## 4. Backend Boundary

```text
app/
├── Domain/
│   ├── Accounting/
│   ├── Bank/
│   ├── Classification/
│   ├── Receivable/
│   ├── Fund/
│   ├── Reconciliation/
│   ├── Reporting/
│   ├── Organization/
│   └── Audit/
├── Application/
├── Infrastructure/
├── Http/
└── Jobs/
```

Controller tidak boleh mengandung accounting logic.

Alur:

```text
HTTP → Request Validation → Application Service → Domain → Persistence → Response
```

## 5. Source-of-Truth Hierarchy

```text
RAW SOURCE
  ↓
NORMALIZED BANK TRANSACTION
  ↓
BUSINESS TRANSACTION
  ↓
JOURNAL
  ↓
POSTED LEDGER
  ↓
REPORT
```

Excel cell tidak boleh menjadi sumber langsung laporan.

## 6. Critical Accounting Rules

```text
Kredit Bank = IN
Debet Bank  = OUT
```

Contoh:

**MI Darwata Sindangbarang — Kredit Rp852.000**

```text
direction = IN
counterparty_role = PAYER / DEPOSITOR
```

Kemudian classification menentukan:

```text
Dr Bank / Cr Receivable
```

atau

```text
Dr Bank / Cr Revenue
```

**Bank Credit tidak sama dengan Journal Credit.**

## 7. Core Entities

- Organization
- User / Role / Permission
- FiscalPeriod
- Account
- AccountMapping
- AccountingDimension
- Counterparty
- CounterpartyAlias
- BankAccount
- BankImport
- BankTransaction
- BusinessTransaction
- JournalEntry
- JournalLine
- Receivable
- ReceivableAllocation
- Fund
- FundAllocation
- FundRealization
- Reconciliation
- ReconciliationItem
- AuditLog
- Attachment

Accounting dimensions minimal:

```text
organization_id
account_id
counterparty_id
fund_id
receivable_id
bank_account_id
program_id
cost_center_id
```

Tidak semua dimension wajib terisi.

## 8. Journal Integrity

Posted journal immutable.

Koreksi menggunakan reversal/adjustment.

Jangan mengandalkan row-level:

```sql
CHECK(total_debit = total_credit)
```

Gunakan:
1. application service transaction;
2. journal validation;
3. database transaction;
4. optional deferred constraint/trigger;
5. automated invariant tests.

Posting:

```text
Draft → Validate → Approve → DB Transaction → Post → Commit
```

## 9. Period

```text
OPEN → SOFT_CLOSE → CLOSED
```

CLOSED menolak posting.

REOPEN membutuhkan authorization dan audit trail.

## 10. Bank Import

```text
Upload
→ File Hash
→ Import Batch
→ Raw Rows
→ Fingerprint
→ Normalize
→ Counterparty Resolution
→ Classification
→ Review
→ Approval
→ Posting
```

Import yang sama tidak boleh menghasilkan financial transaction duplikat.

Raw source wajib menyimpan:

```text
source_file
source_sheet
source_row
raw_data
import_batch_id
```

## 11. Classification

Precedence:

1. approved explicit mapping
2. exact reference/invoice
3. approved legacy mapping
4. deterministic rule
5. counterparty + amount/date/category suggestion
6. AI suggestion
7. UNCLASSIFIED

AI hanya memberi suggestion + confidence + evidence.

AI tidak boleh direct-post.

## 12. Receivable

```text
Receivable ← Payment → Allocation
```

Satu payment dapat membayar banyak receivable dan sebaliknya.

Unmatched payment:

```text
UNAPPLIED
```

Bukan revenue otomatis.

Payment receivable:

```text
Dr Bank
Cr Receivable
```

## 13. Fund

```text
Fund
├── Allocation
├── Commitment
├── Realization
└── Return
```

Formula:

```text
available = allocated - committed - realized + returned
```

Jangan mengasumsikan seluruh 21xx/22xx sebagai expense/liability tanpa approved mapping.

## 14. Bank Reconciliation

Levels:

```text
L1 Exact
L2 Strong
L3 Composite
L4 Manual
```

Formula:

```text
Statement Ending = Opening - Bank Debits + Bank Credits
Ledger Ending    = Opening + Posted Cash In - Posted Cash Out
Difference       = Statement Ending - Ledger Ending
```

Match menyimpan method, evidence, actor, timestamp.

## 15. Reports

1. Dashboard
2. Buku Bank
3. Jurnal Umum
4. Buku Besar
5. Trial Balance
6. Piutang
7. Aging Piutang
8. Alokasi Dana
9. Bank Reconciliation
10. Neraca
11. Arus Kas
12. Rincian Kas Riil

Semua report harus drill-down:

```text
Report → Journal/Domain → Transaction → Raw Source
```

## 16. Revised Development Phases

### Phase 0 — Discovery & Architecture
Output:
- IMPLEMENTATION_PLAN.md
- ARCHITECTURE.md
- DOMAIN_MODEL.md
- ACCOUNTING_RULES.md
- DECISIONS.md

Gate: scope, stack, domain boundaries disetujui.

### Phase 1 — Foundation
- Laravel
- Next.js
- PostgreSQL
- Redis
- Docker
- CI
- environment
- logging
- health checks
- migration system

Gate: frontend/backend/database/queue/CI OK.

### Phase 2 — Organization, Auth & RBAC
- organization
- users
- roles
- permissions
- tenant scope
- authentication
- authorization
- audit middleware

Gate: tenant isolation terbukti.

### Phase 3 — Accounting Foundation
- fiscal periods
- COA
- legacy mapping
- accounting dimensions
- journal entry/line
- money service
- posting
- reversal

Gate: every posted journal balances dan immutable.

### Phase 4 — Master Data
- bank accounts
- counterparties
- aliases
- categories
- funds
- programs

### Phase 5 — Bank Import
- Excel import
- raw preservation
- fingerprint
- normalization
- validation
- exception queue

Gate: duplicate import = tidak ada duplicate financial transaction.

### Phase 6 — Classification
- deterministic rules
- mapping engine
- counterparty resolution
- classification queue
- AI suggestion interface

Gate: MI Darwata example benar.

### Phase 7 — Accounting Automation
Classification → Business Transaction → Draft Journal → Approval → Posting.

Gate: all generated journals balanced.

### Phase 8 — Receivable
- receivable
- payment
- allocation
- unapplied cash
- aging
- overpayment protection

Gate: AR reconciles.

### Phase 9 — Fund / Allocation
- fund
- allocation
- commitment
- realization
- return

Gate: fund totals reconcile.

### Phase 10 — Bank Reconciliation
- matching
- exact/strong/composite/manual
- exception queue

Gate: bank balances reconcile.

### Phase 11 — Reporting
Bangun seluruh 12 report.

Gate: golden totals reconcile.

### Phase 12 — Frontend UX
Baru setelah domain stabil:
- dashboard
- transaction workspace
- classification queue
- journal
- AR
- fund
- reconciliation
- reports

UI tidak boleh memiliki business logic sendiri.

### Phase 13 — Golden Migration
Full Maret 2026 migration.

Gate:

```text
unexplained discrepancy = 0
```

### Phase 14 — E2E & Security
Auth, RBAC, tenant isolation, import, journal, AR, fund, reconciliation, reports, export, audit.

### Phase 15 — Production Hardening
Backup, restore drill, indexes, performance, monitoring, security, file upload, rate limiting.

### Phase 16 — UAT & Release
Output:
- UAT_REPORT.md
- RELEASE_AUDIT.md
- RELEASE_NOTES.md
- RUNBOOK.md

Status akhir sebelum approval:

```text
READY FOR UAT
```

## 17. Golden Dataset

Baseline yang sudah tersedia dari source:

```text
BRI Giro 308      Rp81.197.552,20
BRI Giro 304      Rp447.363.340,00
BRI Tabungan 538  Rp681.256.927,80
Kas tunai         Rp24.425.217
```

Ringkasan source juga menetapkan:

```text
Neraca: Rp2.433.884.487
Piutang: Rp1.199.640.450
Kas masuk: Rp231.257.113
Kas keluar: Rp190.236.281
```

Angka harus direkonsiliasi terhadap workbook sebelum dianggap final.

## 18. MVP

### MUST HAVE
- Auth/RBAC/organization
- Fiscal period
- COA + legacy mapping
- Counterparty + aliases
- 3 bank accounts
- Excel import
- Raw + normalized transactions
- Classification
- Journal engine + reversal
- Receivable + payment allocation
- Unapplied cash
- Fund/allocation
- Bank reconciliation
- Core reports
- Audit log
- Period closing
- Backup/restore
- March 2026 golden test

### PHASE 2
- Approval matrix
- Auto reconciliation
- Budget vs realization
- Payables
- Loans
- Employee receivables
- Attachments
- Recurring transactions

### ADVANCED
- Bank API/open banking
- OCR
- AI entity resolution
- Advanced analytics
- Multi-entity consolidation
- WhatsApp notification

RINCIAN MAKMUR tetap OUT OF SCOPE.

## 19. Security

Roles:

```text
SUPER_ADMIN
ACCOUNTING_ADMIN
ACCOUNTING_OPERATOR
RECONCILER
APPROVER
REPORT_VIEWER
AUDITOR
```

Segregation of duties:

```text
Input ≠ Approval
Classification ≠ Final Approval
```

Audit log:

```text
actor
timestamp
organization
entity
entity_id
action
before
after
reason
ip
correlation_id
```

## 20. Agentic AI Rules

Agent wajib:
1. membaca specification;
2. membaca implementation plan;
3. inspect repository;
4. membuat mini-plan per phase;
5. implement vertical slice;
6. menjalankan test;
7. memperbaiki error;
8. update docs;
9. berhenti pada phase gate.

Agent dilarang:
- mock financial flow;
- hardcode report totals;
- mengarang accounting rules;
- auto-post AI suggestion;
- edit posted journal;
- bypass period lock;
- fake API;
- FLOAT untuk money;
- microservices tanpa kebutuhan;
- menghilangkan source traceability;
- memasukkan RINCIAN MAKMUR.

## 21. Phase Gate

Setiap fase menghasilkan:

```text
PHASE_RESULT.md
```

Isi:

```text
Phase
Status
Implemented
Tests
Known Issues
Accounting Risks
Migration Impact
Next Phase
Gate: PASS / FAIL
```

Jika FAIL:

**STOP. Jangan lanjut.**

## 22. Final Architecture

```text
USER
 ↓
Next.js
 ↓ HTTPS/JSON
Laravel API
 ↓
Domain/Application/Infrastructure
 ↓
PostgreSQL
 ↓
Redis/Queue
 ↓
Object Storage
```

MVP deployment:

```text
VPS
├── Nginx
├── Next.js
├── Laravel PHP-FPM
├── Queue Worker
├── PostgreSQL
└── Redis
```

Kubernetes tidak diperlukan untuk MVP.

## 23. Immediate Next Action

Sebelum Phase 1:

1. setujui stack;
2. pastikan workbook Maret 2026 tersedia untuk agent;
3. kunci canonical COA mapping;
4. putuskan atau dokumentasikan temporary policy untuk keputusan accounting yang masih terbuka;
5. setujui Implementation Plan REV.1.

Setelah itu:

**BEGIN PHASE 1 — FOUNDATION**

Jangan mulai dari dashboard.
