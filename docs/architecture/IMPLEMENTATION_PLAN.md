# IMPLEMENTATION PLAN REV.1 — SISTEM KEUANGAN LP MA'ARIF NU PCNU CILACAP

**Specification of Record:** `Spesifikasi_Teknis_Sistem_Keuangan_LP_Maarif_REV3_Production_Ready.docx`
**Golden Dataset:** Workbook Maret 2026 (`3. MARET 2026.xlsx`)
**Status:** REV.1 — Revised baseline sebelum Phase 1
**Revisi oleh:** Principal Software Architect + Accounting Systems Engineer

---

## 1. Tujuan Revisi

Implementation Plan fase discovery sudah kuat pada domain accounting, tetapi perlu dikoreksi pada:

- keputusan technology stack;
- pemisahan domain accounting dari HTTP/UI;
- urutan fase pembangunan;
- penggunaan golden dataset sebagai fixture sejak awal;
- organization/tenant sebagai security boundary;
- validasi jurnal yang tidak cukup direpresentasikan sebagai row-level SQL CHECK;
- accounting dimensions;
- source-of-truth hierarchy;
- phase gate yang tegas.

**Accounting rules REV.3 tidak diubah.**

---

## 2. Technology Decision

### Frontend
- Next.js (App Router)
- TypeScript
- Tailwind CSS
- TanStack Query
- React Hook Form + Zod

### Backend
- Laravel (PHP 8.3+)
- Laravel API
- Laravel Queue

### Data
- PostgreSQL 16 — `DECIMAL(18,2)` untuk money, bukan FLOAT
- Redis — queue, cache, session
- S3-compatible object storage — file import, attachment, export

### Testing
- Pest / PHPUnit
- Playwright (E2E)
- PHPStan / Larastan
- TypeScript / ESLint

### Auth
- Laravel Sanctum
- Argon2id
- RBAC + organization scope

### Deployment
- Docker + Docker Compose
- Nginx / reverse proxy
- VPS / managed container untuk MVP

> **Keputusan:** Laravel dipilih sebagai backend utama. Laravel lebih mengutamakan transaction integrity, accounting correctness, auditability, database consistency, dan maintainability. Node.js/Express viable tetapi tidak diperlukan untuk MVP.

---

## 3. Architecture Decision

Gunakan **Modular Monolith**, bukan microservices.

```
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

### Backend Domain Boundaries

```
app/
├── Domain/
│   ├── Accounting/       # COA, journal engine, posting, reversal
│   ├── Bank/             # Bank accounts, imports, transactions, normalization
│   ├── Classification/   # Classification rules, AI suggestion interface
│   ├── Receivable/       # AR lifecycle, payment allocation, aging
│   ├── Fund/             # Fund/allocation buckets, commitment, realization
│   ├── Reconciliation/   # Bank reconciliation matching engine
│   ├── Reporting/        # Report builders (from ledger)
│   ├── Organization/     # Tenant, fiscal periods
│   └── Audit/            # Audit log, event tracking
├── Application/          # Use cases / application services
├── Infrastructure/       # DB, cache, storage, queue adapters
├── Http/                 # Controllers, requests, resources
└── Jobs/                 # Background import, report, reconciliation jobs
```

**Rule:** Controller tidak boleh mengandung accounting logic. Alur:

```
HTTP → Request Validation → Application Service → Domain → Persistence → Response
```

---

## 4. Source-of-Truth Hierarchy

```
RAW SOURCE (Excel row / manual input)
  ↓
NORMALIZED BANK TRANSACTION
  ↓
BUSINESS TRANSACTION
  ↓
JOURNAL ENTRY + JOURNAL LINES
  ↓
POSTED LEDGER
  ↓
REPORT
```

Excel cell tidak boleh menjadi sumber langsung laporan. Semua angka laporan harus drill-down ke:

```
Report → Journal Line → Transaction → Raw Bank Row
```

---

## 5. Critical Accounting Rules (dari REV.3)

> **RULE R01:** Bank CREDIT ≠ Journal CREDIT. Bank CREDIT = cash-in.
> **RULE R02:** Bank DEBIT ≠ Journal DEBIT. Bank DEBIT = cash-out.

| Bank Side | Direction | Counterparty Role | Default Journal |
|-----------|-----------|-------------------|-----------------|
| Kredit (credit > 0) | IN | PAYER / DEPOSITOR (atau BANK untuk bunga) | Dr Bank / Cr AR atau Revenue |
| Debet (debit > 0) | OUT | PAYEE / RECIPIENT (atau BANK untuk fee/pajak) | Dr Expense / Cr Bank |
| Dua legs | TRANSFER | INTERNAL | Dr Dest Bank / Cr Source Bank |

**Contoh MI Darwata Sindangbarang — Kredit Rp852.000:**

```
direction         = IN
counterparty_role = PAYER / DEPOSITOR

Jika ada piutang cocok:
    Dr BRI Tabungan    852.000
    Cr Receivable              852.000

Jika direct income:
    Dr BRI Tabungan    852.000
    Cr Revenue                 852.000
```

**UI labels wajib eksplisit:** "Debet Bank", "Kredit Bank", "Debit Jurnal", "Kredit Jurnal"

---

## 6. Core Entities

| Entitas | Fungsi |
|---------|--------|
| `Organization` | Tenant / scope isolasi |
| `User / Role / Permission` | Auth dan RBAC |
| `FiscalPeriod` | Periode kontrol OPEN/SOFT_CLOSE/CLOSED |
| `Account` | Chart of Accounts dengan legacy_code |
| `AccountMapping` | legacy_code → canonical account |
| `AccountingDimension` | Dimensi cross-cutting |
| `Counterparty` | Pihak transaksi |
| `CounterpartyAlias` | Variant nama counterparty |
| `BankAccount` | 3 rekening BRI |
| `BankImport` | Batch import + file hash |
| `BankTransaction` | Raw + normalized bank rows |
| `BusinessTransaction` | Business aggregate |
| `JournalEntry` | Header jurnal |
| `JournalLine` | Detail jurnal (debit/credit) |
| `Receivable` | Piutang master |
| `ReceivableAllocation` | Alokasi pembayaran ke piutang |
| `Fund` | Fund/allocation bucket |
| `FundAllocation` | Komitmen dan alokasi dana |
| `FundRealization` | Realisasi dana |
| `Reconciliation` | Rekonsiliasi bank header |
| `ReconciliationItem` | Item match rekonsiliasi |
| `AuditLog` | Append-only audit trail |
| `Attachment` | File evidence per transaksi |

### Accounting Dimensions

```
organization_id   (wajib)
account_id        (wajib)
counterparty_id   (opsional)
fund_id           (opsional)
receivable_id     (opsional)
bank_account_id   (opsional)
program_id        (opsional)
cost_center_id    (opsional)
```

Tidak semua dimension wajib terisi.

---

## 7. Journal Integrity

**Posted journal immutable.** Koreksi menggunakan reversal/adjustment.

**Jangan mengandalkan hanya row-level:**

```sql
-- TIDAK CUKUP sendirian:
CHECK(total_debit = total_credit)
```

Gunakan defense-in-depth:
1. Application service transaction (cek balance sebelum commit)
2. Journal validation (domain rule)
3. Database transaction (atomic)
4. Optional deferred trigger (last resort guard)
5. Automated invariant tests (golden test)

**Posting pipeline:**

```
Draft → Validate → Approve → DB Transaction → Post → Commit
```

---

## 8. Transaction State Machine

```
IMPORTED → NORMALIZED → CLASSIFIED → REVIEWED → APPROVED → POSTED
                          └→ EXCEPTION / REJECTED
POSTED → REVERSED
```

```
FISCAL PERIOD:
OPEN → SOFT_CLOSE → CLOSED
  ↑          |
  └── REOPEN (authorized + audited)

RECONCILIATION:
UNMATCHED → SUGGESTED → MATCHED
              └→ PARTIAL / EXCEPTION
```

Status transition **hanya boleh dilakukan service/domain layer.** Frontend tidak boleh langsung mengubah status posted/closed via field update.

---

## 9. Bank Import Pipeline

```
Upload
→ SHA Hash → cek duplikat file
→ Import Batch (bank_imports)
→ Raw Rows (bank_transactions raw)
→ Fingerprint per row
→ Normalize (direction, amount, counterparty hint)
→ Counterparty Resolution
→ Classification Queue
→ Review
→ Approval
→ Posting
```

Import yang sama tidak boleh menghasilkan financial transaction duplikat.

Raw source wajib menyimpan:

```
source_file     (file name + hash)
source_sheet    (nama sheet Excel)
source_row      (nomor baris)
raw_data        (JSON row asli)
import_batch_id
row_fingerprint (org + bank_account + date + seq + debit + credit + raw_desc)
```

Saldo berjalan divalidasi saat import. Mismatch → exception, bukan silent correction.

---

## 10. Classification Precedence

| Prioritas | Metode | Auto-Post? |
|-----------|--------|-----------|
| 1 | Explicit approved user mapping | ✅ YES |
| 2 | Exact invoice/reference | ✅ YES |
| 3 | Approved legacy-code mapping | ✅ YES |
| 4 | Deterministic category rule | ✅ YES |
| 5 | Counterparty + amount/date/category suggestion | ⚠️ SUGGESTION |
| 6 | AI/fuzzy suggestion | ⚠️ SUGGESTION ONLY |
| 7 | UNCLASSIFIED queue | ❌ NO |

AI hanya memberikan: `{ confidence, evidence, suggested_type, suggested_account, suggested_counterparty, suggested_receivable, explanation }`

**AI tidak boleh direct-post.**

---

## 11. Receivable (Piutang)

**Lifecycle:**

```
ISSUED → PARTIALLY_PAID → PAID
           └→ OVERDUE
```

- Satu payment dapat membayar banyak receivable
- Satu receivable dapat menerima banyak payment
- Unmatched payment = `UNAPPLIED` — bukan revenue otomatis
- Opening AR harus dipisahkan dari revenue periode berjalan
- Overpayment: BLOCK sebagai safe default (policy eksplisit diperlukan)

```
Payment receivable:
Dr Bank / Cash
Cr Receivable
```

---

## 12. Fund / Allocation

```
Fund
├── Allocation   → jumlah yang dialokasikan
├── Commitment   → jumlah yang dikunci tapi belum direalisasi
├── Realization  → jumlah yang sudah dibayarkan
└── Return       → pengembalian dana
```

**Formula:**

```
available = allocated - committed - realized + returned
```

Guard: `realized <= allocated` (kecuali policy advance/deficit disetujui).

**Jangan mengasumsikan seluruh 21xx/22xx sebagai expense/liability** tanpa approved mapping.

---

## 13. Bank Reconciliation

| Level | Kondisi | Aksi |
|-------|---------|------|
| L1 Exact | reference + amount | Auto-match |
| L2 Strong | date window + amount + counterparty | Suggestion |
| L3 Composite | amount + category + alias | Suggestion + review |
| L4 Manual | Tidak ada deterministic match | Manual match / create journal |

**Formula:**

```
Statement Ending = Opening - Bank Debits + Bank Credits
Ledger Ending    = Opening + Posted Cash In - Posted Cash Out
Difference       = Statement Ending - Ledger Ending
```

Match menyimpan: method, evidence, actor, timestamp.

Close period membutuhkan: difference = 0 ATAU approved reconciling items.

---

## 14. Reporting

Semua 12 report wajib dari database/ledger. **Tidak ada hardcoded Excel cell positions.**

| No | Report | Source of Truth |
|----|--------|----------------|
| 1 | Dashboard | Aggregated dari semua domain |
| 2 | Buku Bank | bank_transactions + posted ledger |
| 3 | Jurnal Umum | journal_entries + journal_lines |
| 4 | Buku Besar | journal_lines per account |
| 5 | Trial Balance | Ledger totals per account |
| 6 | Piutang | receivables + allocations |
| 7 | Aging Piutang | receivables by age bucket |
| 8 | Alokasi Dana | funds + allocations + realizations |
| 9 | Bank Reconciliation | reconciliation + items |
| 10 | Neraca (Balance Sheet) | Ledger + report mapping |
| 11 | Laporan Arus Kas | Cash ledger + classification |
| 12 | Rincian Kas Riil | Fund/allocation × bank/cash dimensions |

---

## 15. Security & RBAC

| Role | Akses Minimum |
|------|--------------|
| `SUPER_ADMIN` | System/tenant config; TIDAK routine posting |
| `ACCOUNTING_ADMIN` | COA, mappings, periods, approvals |
| `ACCOUNTING_OPERATOR` | Import, classification, AR, draft journal |
| `RECONCILER` | Bank reconciliation |
| `APPROVER` | Journal/adjustment/close approval |
| `REPORT_VIEWER` | Read/export |
| `AUDITOR` | Read-only + audit trail |

**Segregation of duties:**

```
Input ≠ Approval
Classification ≠ Final Approval
```

**Audit log fields:**

```
actor           user_id + name
timestamp       created_at (UTC)
organization    organization_id
entity          table name
entity_id       record id
action          CREATE/UPDATE/DELETE/POST/REVERSE/CLOSE/REOPEN
before          JSON snapshot sebelum
after           JSON snapshot sesudah
reason          alasan perubahan
ip              IP address
correlation_id  request trace
```

Audit log append-only. Tidak ada DELETE.

---

## 16. Golden Dataset & Baseline

Workbook Maret 2026 adalah **golden regression fixture.** Setiap angka harus direkonsiliasi terhadap workbook sebelum dianggap final.

### Baseline Bank Accounts

| Rekening | Opening | Bank Debet | Bank Kredit | Ending |
|----------|---------|-----------|-------------|--------|
| BRI Giro 308 | Rp72.125.396,20 | Rp5.539,00 | Rp9.077.695,00 | **Rp81.197.552,20** |
| BRI Giro 304 | Rp469.776.285,00 | Rp124.258.949,00 | Rp101.846.004,00 | **Rp447.363.340,00** |
| BRI Tabungan 538 | Rp571.955.656,80 | Rp61.193,00 | Rp109.362.464,00 | **Rp681.256.927,80** |

### Baseline Golden Totals (dari spec ringkasan)

```
BRI Giro 308      Rp81.197.552,20
BRI Giro 304      Rp447.363.340,00
BRI Tabungan 538  Rp681.256.927,80
Kas tunai         Rp24.425.217,00

Neraca            Rp2.433.884.487
Piutang           Rp1.199.640.450
Kas masuk         Rp231.257.113
Kas keluar        Rp190.236.281
```

> Angka harus diverifikasi ulang terhadap workbook asli. Semua discrepancy harus resolved atau approved.

---

## 17. MVP Scope

### MUST HAVE (MVP)

- Auth / RBAC / organization
- Fiscal period + closing
- COA + legacy mapping
- Counterparty + aliases
- 3 bank accounts + Excel import
- Raw + normalized transactions
- Deterministic classification
- Journal engine + reversal
- Receivable + payment allocation
- Unapplied cash
- Fund / allocation
- Bank reconciliation
- Core reports (12 reports, drill-down)
- Audit log
- Period closing/reopening
- Backup / restore
- March 2026 golden test

### PHASE 2

- Approval matrix lanjutan
- Auto-reconciliation
- Budget vs realization
- Payables / hutang
- Loans
- Employee receivables
- Attachment per transaksi
- Recurring transactions

### ADVANCED

- Bank API / open banking
- OCR statement
- AI entity resolution
- Auto-learning classification
- Advanced analytics
- Multi-entity consolidation
- WhatsApp notification

**RINCIAN MAKMUR tetap OUT OF SCOPE selamanya.**

---

## 18. Development Phases

### Phase 0 — Discovery & Architecture ✅
Output:
- `IMPLEMENTATION_PLAN.md`
- `ARCHITECTURE.md`
- `DOMAIN_MODEL.md`
- `ACCOUNTING_RULES.md`
- `DECISIONS.md`

**Gate:** Scope, stack, domain boundaries disetujui.

---

### Phase 1 — Foundation
Deliverables:
- Laravel project scaffolding (PHP 8.3, API)
- Next.js project scaffolding (App Router, TypeScript)
- Docker Compose (PostgreSQL, Redis, Laravel, Next.js, Nginx)
- CI pipeline (lint, typecheck, test)
- Database migration system (Laravel Migrations)
- Logging + structured error handling
- Health check endpoints
- Environment configuration

**Gate:** Frontend / backend / database / queue / CI semua OK.

---

### Phase 2 — Organization, Auth & RBAC
Deliverables:
- Organization / tenant model
- User / role / permission model
- Laravel Sanctum authentication
- RBAC middleware (org-scoped)
- Audit middleware
- Tenant isolation enforcement

**Gate:** Tenant isolation terbukti. User di org A tidak bisa akses data org B.

---

### Phase 3 — Accounting Foundation
Deliverables:
- Fiscal periods (OPEN/SOFT_CLOSE/CLOSED)
- Chart of Accounts + legacy_code
- AccountMapping (legacy → canonical)
- AccountingDimension
- Money service (DECIMAL, tidak ada float arithmetic)
- JournalEntry + JournalLine
- Posting engine (Draft → Validate → Approve → Post)
- Reversal engine
- Period lock enforcement

**Gate:** Setiap posted journal balance (total debit = total credit). Posted record immutable.

---

### Phase 4 — Master Data
Deliverables:
- BankAccount (Giro 308, Giro 304, Tabungan 538)
- Counterparty + CounterpartyAlias
- TransactionCategory
- Fund + FundAllocation master
- Programs / cost centers (jika digunakan)

---

### Phase 5 — Bank Import
Deliverables:
- Excel import (openpyxl/PhpSpreadsheet)
- File hash deduplication
- BankImport batch tracking
- Raw row preservation (JSON)
- Row fingerprint generation
- Normalization (direction, amount)
- Running balance validation
- Exception queue (mismatch, ambiguous)

**Gate:** Import file yang sama dua kali → tidak ada duplikat financial transaction.

---

### Phase 6 — Classification
Deliverables:
- Deterministic classification rules engine
- Legacy code mapping engine
- Counterparty resolution (exact → alias → fuzzy)
- Classification queue (UNCLASSIFIED)
- AI suggestion interface (confidence + evidence, tidak direct-post)

**Gate:** MI Darwata Sindangbarang Kredit Rp852.000 → direction=IN, counterparty_role=PAYER, classified correctly.

---

### Phase 7 — Accounting Automation
Deliverables:
- Classification → BusinessTransaction → Draft Journal → Approval → Posting pipeline
- Journal posting matrix (semua 10 transaction types)
- Automatic journal draft generation

**Gate:** Semua generated journals balanced. Tidak ada manual balance fix.

---

### Phase 8 — Receivable
Deliverables:
- Receivable master + lifecycle (ISSUED → PARTIALLY_PAID → PAID / OVERDUE)
- Payment allocation (multi-payment, multi-receivable)
- Unapplied cash handling
- Aging calculation
- Overpayment protection

**Gate:** AR movement reconciles. Unapplied cash tidak otomatis menjadi revenue.

---

### Phase 9 — Fund / Allocation
Deliverables:
- Fund model + balance formula
- FundAllocation (commitment tracking)
- FundRealization (disbursement)
- Return tracking
- Negative balance guard

**Gate:** Fund totals reconcile. Allocated/realized/returned/available konsisten.

---

### Phase 10 — Bank Reconciliation
Deliverables:
- Reconciliation engine (L1 Exact / L2 Strong / L3 Composite / L4 Manual)
- Evidence storage (method, actor, timestamp)
- Exception queue
- Reconciliation report

**Gate:** 3 bank account balances reconcile against workbook baseline.

---

### Phase 11 — Reporting
Deliverables:
- Semua 12 report dari ledger/domain
- Drill-down per amount (Report → Journal → Transaction → Raw)
- Export Excel + PDF
- Report filter (period, account, counterparty, fund)

**Gate:** Golden totals match workbook. Semua drill-down berfungsi.

---

### Phase 12 — Frontend UX
**Dimulai setelah domain stabil (bukan sebelum).**

Deliverables:
- Dashboard
- Transaction workspace + classification queue
- Journal management
- AR management
- Fund management
- Bank reconciliation workspace
- Reports viewer
- Admin: COA, counterparty, user, period

**Rule:** UI tidak boleh memiliki business logic sendiri. Semua logic ada di backend domain.

---

### Phase 13 — Golden Migration (Maret 2026)
Deliverables:
- Full import workbook Maret 2026
- Raw row trace ke source sheet/row
- Opening balance dari NERACA AWAL BULAN
- Opening AR
- Mapping approval
- Draft journals → validate → post
- Reconciliation 3 rekening
- Report generation

**Gate:**

```
unexplained discrepancy = 0
```

Semua discrepancy harus resolved atau explicitly approved dengan dokumentasi.

---

### Phase 14 — E2E & Security Testing
Deliverables:
- E2E: auth, RBAC, import, journal, AR, fund, reconciliation, reports, export, audit
- Tenant isolation test
- Period lock enforcement test
- Immutable journal test
- Idempotent import test

---

### Phase 15 — Production Hardening
Deliverables:
- Automated backup + retention policy
- Restore drill (mandatory sebelum go-live)
- Database indexes (performance)
- Rate limiting + file upload security
- Monitoring + structured logs
- Security headers
- Background job error handling

---

### Phase 16 — UAT & Release
Outputs:
- `UAT_REPORT.md`
- `RELEASE_AUDIT.md`
- `RELEASE_NOTES.md`
- `RUNBOOK.md`

Status akhir sebelum approval:

```
READY FOR UAT
```

---

## 19. Phase Gate Template

Setiap fase menghasilkan `PHASE_N_RESULT.md`:

```
Phase    : N — [Nama]
Status   : PASS / FAIL
Date     : YYYY-MM-DD

Implemented:
  - [item]

Tests:
  - [test name] PASS/FAIL

Known Issues:
  - [deskripsi]

Accounting Risks:
  - [risiko]

Migration Impact:
  - [dampak]

Next Phase:
  - [phase N+1]

Gate: PASS / FAIL
```

**Jika FAIL: STOP. Jangan lanjut ke phase berikutnya.**

---

## 20. Agentic AI Rules

**Agent wajib:**
1. Membaca specification (REV.3)
2. Membaca Implementation Plan REV.1
3. Inspect repository sebelum coding
4. Membuat mini-plan per phase
5. Implement vertical slice (domain → service → API → test)
6. Menjalankan test (typecheck, lint, unit, integration)
7. Memperbaiki semua error sebelum lanjut
8. Update docs setelah setiap phase
9. Berhenti pada phase gate jika FAIL

**Agent dilarang:**
- Mock financial flow sebagai pengganti implementasi nyata
- Hardcode report totals
- Mengarang accounting rules di luar spec
- Auto-post AI suggestion
- Edit posted journal
- Bypass period lock
- Membuat fake API / fake loading state
- Menggunakan FLOAT untuk money
- Menggunakan microservices tanpa kebutuhan nyata
- Menghilangkan source traceability
- Memasukkan RINCIAN MAKMUR ke dalam scope manapun

---

## 21. Open Decisions (Wajib Dikunci Sebelum Production)

| # | Keputusan | Safe Default | Status |
|---|-----------|-------------|--------|
| D1 | Basis pelaporan: cash, accrual, atau hybrid? | Cash basis | ❓ OPEN |
| D2 | Definisi 21xx/22xx: fund liability atau lainnya? | Fund/allocation liability bucket | ❓ OPEN |
| D3 | Canonical mapping seluruh legacy code ambiguous/mixed | Flag LEGACY_MIXED, butuh approval | ❓ OPEN |
| D4 | Revenue vs AR settlement: kapan income diakui? | AR jika ada matching receivable | ❓ OPEN |
| D5 | Kebijakan overpayment piutang | BLOCK (safest) | ❓ OPEN |
| D6 | Materiality threshold adjustment approval | Semua butuh approval | ❓ OPEN |
| D7 | Role approval matrix (journal/recon/close) | APPROVER untuk semua | ❓ OPEN |
| D8 | RPO/RTO dan retention backup | Daily backup, 30 hari | ❓ OPEN |
| D9 | Format laporan final (signed-off golden output) | Match workbook layout | ❓ OPEN |
| D10 | Cash-flow classification resmi | Per workbook LAP. ARUS KAS | ❓ OPEN |
| D11 | Kas 1101 dan BMT 1102: aktif atau history-only? | History-only untuk MVP | ❓ OPEN |
| D12 | RINCIAN KAS RIIL: dana terikat atau managerial report? | Managerial report | ❓ OPEN |

---

## 22. Locked Decisions (Sudah Final dari REV.3)

| # | Keputusan |
|---|-----------|
| L1 | MI Darwata Sindangbarang pada Kredit bank = PAYER/DEPOSITOR |
| L2 | Kredit bank = IN; Debet bank = OUT |
| L3 | RINCIAN MAKMUR adalah OUT OF SCOPE selamanya |
| L4 | Maret 2026 adalah golden regression dataset |
| L5 | Bank statement harus melalui normalization/classification sebelum posting |

---

## 23. Final Architecture Diagram

```
USER (browser)
  ↓
Next.js (App Router)
  ↓ HTTPS/JSON
Laravel API (PHP 8.3)
  ↓
Domain / Application / Infrastructure
  ↓
PostgreSQL (DECIMAL, FK, constraints, audit)
  ↓
Redis (queue, cache, session)
  ↓
Object Storage (import files, exports, attachments)
```

**MVP Deployment:**

```
VPS / Managed Container
├── Nginx (reverse proxy, SSL)
├── Next.js (frontend)
├── Laravel PHP-FPM (API)
├── Queue Worker (Laravel Queue)
├── PostgreSQL
└── Redis
```

Kubernetes tidak diperlukan untuk MVP.

---

## 24. Immediate Next Actions

Sebelum Phase 1 dimulai:

1. ✅ **Setujui stack** (Next.js + Laravel + PostgreSQL + Redis)
2. ⏳ **Sediakan workbook Maret 2026** (`3. MARET 2026.xlsx`) untuk agent
3. ⏳ **Kunci canonical COA mapping** untuk akun ambiguous (5000, 2500, 21xx/22xx)
4. ⏳ **Dokumentasikan temporary policy** untuk 12 open decisions
5. ✅ **Setujui Implementation Plan REV.1**

Setelah semua di atas terpenuhi:

**→ BEGIN PHASE 1 — FOUNDATION**

Jangan mulai dari dashboard. Jangan mulai dari UI.
