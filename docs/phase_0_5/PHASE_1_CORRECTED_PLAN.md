# ACTUAL PHASE 1 IMPLEMENTATION PLAN (FINAL CORRECTION)

Dokumen ini adalah cetak biru eksekusi teknis **Phase 1: Database & Domain Model**, yang telah dikalibrasi penuh berdasarkan *Final Consistency Review*. Dilarang keras mengeksekusi migrasi sebelum dokumen ini disetujui penuh.

---

## A. SYSTEM FOUNDATION
1. **Project Initialization:** `composer create-project laravel/laravel backend`
2. **Laravel Version:** Laravel 11.x
3. **PHP Version:** PHP 8.3+
4. **PostgreSQL Version:** PostgreSQL 16 (via Docker Compose)
5. **Required Packages:** `spatie/laravel-permission` (untuk RBAC)
6. **Directory/Module Architecture:** Modular Monolith (`app/Domain/`, `app/Application/`, `app/Infrastructure/`, `app/Http/`)

---

## B. DATABASE SCHEMA & CONSTRAINTS
7. **Complete Migration Order:**
   1. `organizations`
   2. `users`
   3. `spatie_permission_tables` (dengan ekstensi `organization_id` pada `roles`)
   4. `fiscal_periods`
   5. `accounts` & `account_mappings`
   6. `counterparties` & `counterparty_aliases`
   7. `bank_accounts` (TANPA `opening_balance`)
   8. `opening_balance_sources` (TANPA FK langsung ke jurnal)
   9. `bank_imports` & `bank_transactions`
   10. `business_transactions`
   11. `journal_entries` & `journal_lines`
   12. `receivables` & `receivable_allocations`
   13. `funds`, `fund_allocations`, `fund_commitments`, `fund_realizations`, `fund_returns`
   14. `reconciliations` & `reconciliation_items`
   15. `audit_logs` & `attachments`
   
8. **Columns and Data Types:**
   - Semua PK/FK: `BIGINT`
   - Semua uang: `DECIMAL(18,2)` (Tidak ada `FLOAT`/`DOUBLE`)
   - `Account.code`: `VARCHAR` / `STRING` (Bukan Integer)
   
9. **Unique Constraints (Revisi Organisasi):**
   - `UNIQUE(code)` pada tabel `organizations`.
   - `UNIQUE(organization_id, code)` pada `accounts` dan `funds`.
   - `UNIQUE(organization_id, fingerprint)` pada `bank_transactions`.
   - `UNIQUE(organization_id, name)` pada `roles` (Spatie Tenant Scoping).

10. **Check Constraints:**
   - Jurnal: `CHECK (debit >= 0 AND credit >= 0)`
   - Jurnal: `CHECK ((debit > 0 AND credit = 0) OR (debit = 0 AND credit > 0))`
   - Piutang: `CHECK (outstanding_amount >= 0)`

---

## C. DOMAIN IMPLEMENTATION & POLICIES

### 1. Tenant Scoping Strategy (Tidak Membabi-buta)
Entitas diklasifikasikan ke dalam 3 jenis scope:
- **GLOBAL:** `organizations` (berlaku untuk semua), `permissions` (aturan statis Spatie).
- **TENANT-SCOPED:** Memiliki FK `organization_id` (Misal: `users`, `accounts`, `bank_accounts`, `journal_entries`, `funds`). Melindungi isolasi data murni antar instansi.
- **PIVOT / JUNCTION:** (Misal: `model_has_roles`, `role_has_permissions`, `account_mappings`). Mengandalkan isolasi pada relasi parent-nya, namun dapat diberikan `organization_id` opsional jika skenario query membutuhkan optimasi partisi tenant (diimplementasikan dengan pertimbangan *domain logic*).

### 2. Multi-Tenant RBAC (Spatie)
Menggunakan native feature dari Spatie (atau custom ekstensi) untuk membatasi *Roles* per tenant:
- Tabel `roles` ditambahkan kolom `organization_id` sebagai FK ke `organizations`.
- Constraint: `UNIQUE(organization_id, name)`.
- *Organization A Admin* mustahil men-*query* role dari Organization B.
- Dilarang membuat sistem buatan `RolePermission`.

### 3. Chart of Accounts (COA)
- `Account.code` menggunakan tipe **STRING**.
- Klasifikasi mengikuti pedoman 1100-5000 (tidak dibatasi kaku dengan regex di DB).
- Atribut wajib: `parent_id`, `is_postable`, `account_type`, `normal_balance` (DEBIT/CREDIT), dan `is_active`.

### 4. Opening Balance Architecture (No Circular Dependency)
- Tabel `bank_accounts` bersih dari `opening_balance`.
- Tabel `opening_balance_sources` **TIDAK** menyimpan FK langsung `journal_entry_id` di schema migrasi (menghindari deadlock relasi/circular dependency).
- *Workflow:* `OpeningBalanceSource` -> (Dibaca oleh `OpeningBalanceService`) -> `JournalEntry` -> `JournalLine` -> `Ledger`.

### 5. Journal Dimensions (Aturan Pakai pada `journal_lines`)
- **Wajib (Mandatory):** `account_id`, `debit`, `credit`.
- **Opsional (Conditional):**
  - `bank_account_id`: Hanya diisi jika `account_id` berjenis Kas/Bank.
  - `counterparty_id`: Wajib diisi pada akun Piutang/Hutang/Pendapatan spesifik.
  - `fund_id`: Wajib jika dana memiliki alokasi khusus (Restricted).
  - `receivable_id`: Hanya diisi jika jurnal berkaitan dengan pengakuan/pelunasan Piutang spesifik.
- *Dimensi tidak boleh dibuang sembarangan (dumping ground); divalidasi oleh Application Service.*

### 6. Reconciliation (L1-L4)
Mengikuti Spesifikasi Teknis/Transaksi Rulebook:
- **L1:** Exact Match (Tanggal sama, nilai sama, kode/fingerprint sama).
- **L2:** Date Window Match (Nilai sama, beda rentang waktu N hari).
- **L3:** Aggregate Match (Banyak mutasi bank = 1 jurnal atau sebaliknya).
- **L4:** Manual Force Match (Kecocokan dipaksakan manusia via `evidence`).

### 7. Bank Semantics & Golden Test Invariants
- **INVARIANT MUTLAK:**
  - `BANK CREDIT = CASH IN`
  - `BANK DEBIT = CASH OUT`
  - `BANK CREDIT ≠ ACCOUNTING CREDIT` (Kredit Bank TIDAK OTOMATIS menjadi Kredit Jurnal).
- **Golden Test Case (MI Darwata Sindangbarang):**
  - Raw: Bank Credit Rp852.000
  - Diterjemahkan: `direction = IN`, Role = `PAYER / DEPOSITOR`.
  - Sistem menolak berasumsi ini adalah akun Pendapatan (Kredit) sebelum klasifikasi dieksekusi.

---

## D. SEEDING, TESTING, & SCOPE LIMITS
- **DEVELOPMENT / TEST FIXTURE ONLY:** Seeder seperti `OpeningBalanceSourceSeeder` hanya untuk simulasi testing dan *Golden Dataset Regression*. **DILARANG** dipakai sebagai injeksi Production.
- **Rincian Makmur:** OUT OF SCOPE TOTAL (Tidak ada kode).
- **Automated Tests:** Menjalankan Pest test untuk Invariant Akuntansi & Security (Cross-Tenant check).

## E. DEFINITION OF DONE FOR PHASE 1
- Backend terbentuk, database termigrasi.
- Struktur patuh pada arsitektur ganda akuntansi & tenant isolation.
- Tes regresi (Maret 2026 Golden Semantics & DB Constraints) berstatus hijau (PASS).
