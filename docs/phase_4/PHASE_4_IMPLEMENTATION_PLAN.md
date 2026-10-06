# IMPLEMENTATION PLAN: PHASE 4 — MASTER DATA
**Sistem Keuangan LP Ma'arif NU PCNU Cilacap**  
**Status Baseline:** Phase 3 (Accounting Foundation) SELESAI (63 Tests Passing)  
**Target:** Phase 4 — Master Data Foundation & Invariants  
**Specification of Record:** `Spesifikasi_Teknis_Sistem_Keuangan_LP_Maarif_REV3_Production_Ready.docx`  
**Golden Dataset:** Workbook Maret 2026 (`3. MARET 2026.xlsx`)

---

## 1. Ringkasan Eksekutif & Tujuan Phase 4

Phase 4 membangun seluruh fondasi **Master Data** yang menjadi prasyarat mutlak sebelum modul **Bank Ingestion / Import** (Phase 5) dan **Classification Engine** (Phase 6) dapat berjalan. Master data harus beroperasi dalam isolasi multi-tenant yang ketat, dilindungi oleh audit trail, dan mematuhi aturan akuntansi invariant yang telah disepakati.

### Deliverables Utama Phase 4:
1. **Bank Accounts (`bank_accounts`)**: Manajemen rekening bank (BRI Giro 308, BRI Giro 304, BRI Tabungan 538, dan Kas Tunai LP Ma'arif) dengan **larangan keras** menyimpan kolom saldo mutlak (`opening_balance`). Sinkronisasi otomatis ke Chart of Accounts (COA) melalui `account_mappings`.
2. **Counterparties & Aliases (`counterparties`, `counterparty_aliases`)**: Entitas pihak ketiga (Madrasah/Sekolah, Bank BRI, Vendor, Donatur, Pemerintah, serta entitas internal). Dilengkapi mesin resolusi nama (*exact match* $\rightarrow$ *alias match* $\rightarrow$ *normalized string match*).
3. **Transaction Categories (`transaction_categories`)**: Master kategori transaksi dengan semantik arah arus kas (`IN`, `OUT`, `TRANSFER`) dan default akun debit/kredit untuk keperluan otomasi klasifikasi pada fase berikutnya.
4. **Funds & Allocations (`funds`, `fund_allocations`)**: Buku pembantu alokasi dana program (pengganti logika hardcoded akun 21xx/22xx). Menegakkan formula saldo:
   $$\text{Available} = \text{Allocated} - \text{Committed} - \text{Disbursed} + \text{Returned}$$
   Dilengkapi proteksi saldo negatif (*negative balance guard*).
5. **Programs (`programs`)**: Master dimensi program/kegiatan pendukung alokasi dana dan klasifikasi transaksi.

---

## 2. Invariants & Aturan Bisnis Kritis

1. **INVARIANT: Bank Account Dilarang Memiliki `opening_balance` Mutlak**  
   Saldo awal bank **hanya boleh** terbentuk melalui jalur `OpeningBalanceSource` $\rightarrow$ `JournalEntry` (Draft $\rightarrow$ Validate $\rightarrow$ Approve $\rightarrow$ Post) $\rightarrow$ `JournalLine`. Model `BankAccount` memiliki guard yang melempar exception jika ada upaya menyimpan/mengubah `opening_balance`.
2. **INVARIANT: Multi-Tenant Scoping Mandatori**  
   Setiap entitas master data (`BankAccount`, `Counterparty`, `CounterpartyAlias`, `TransactionCategory`, `Fund`, `FundAllocation`, `Program`) wajib mengimplementasikan trait `BelongsToTenant` (`organization_id`). Akses atau modifikasi lintas tenant memicu `CrossTenantViolationException` (HTTP 403).
3. **INVARIANT: Segregasi System Counterparties**  
   Nama transaksi mutasi seperti *"Bunga Rekening (Bank)"*, *"Pajak Bulanan (Bank)"*, dan *"Adm Bank"* otomatis diarahkan ke System Counterparty `BANK` (Bank BRI) melalui alias, bukan membuat entitas madrasah/lembaga baru. Begitu juga transaksi pemindahan dana diarahkan ke System Counterparty `INTERNAL`.
4. **INVARIANT: Proteksi Saldo Alokasi Dana Negatif**  
   Realisasi/pencairan dana (`disburse`) maupun komitmen dana (`commit`) **dilarang melebihi** saldo yang tersedia (`available_amount`). Upaya pelanggaran memicu `NegativeFundBalanceException`.
5. **INVARIANT: Audit Trail Append-Only**  
   Setiap operasi pembuatan, modifikasi, dan penghapusan master data wajib tercatat pada tabel `audit_logs` dengan snapshot data `old_values` dan `new_values`.

---

## 3. Desain Arsitektur & Bounded Context

Mengikuti arsitektur **Modular Monolith**:

```text
backend/app/
├── Domain/
│   ├── Bank/
│   │   ├── Models/BankAccount.php
│   │   └── Services/BankAccountService.php
│   ├── Counterparty/
│   │   ├── Models/Counterparty.php
│   │   ├── Models/CounterpartyAlias.php
│   │   └── Services/CounterpartyService.php
│   ├── Classification/
│   │   ├── Models/TransactionCategory.php
│   │   └── Services/TransactionCategoryService.php
│   ├── Fund/
│   │   ├── Models/Fund.php
│   │   ├── Models/FundAllocation.php
│   │   ├── Exceptions/NegativeFundBalanceException.php
│   │   └── Services/FundService.php
│   └── Program/
│       ├── Models/Program.php
│       └── Services/ProgramService.php
├── Http/Controllers/Api/
│   ├── BankAccountController.php
│   ├── CounterpartyController.php
│   ├── TransactionCategoryController.php
│   ├── FundController.php
│   └── ProgramController.php
```

---

## 4. Rencana Langkah Kerja (Step-by-Step Execution Plan)

### Step 4.1: Database Schema & Migrations
- [x] **Migration 1:** `add_account_id_to_bank_accounts_table` (menambahkan nullable FK `account_id` ke `accounts`).
- [x] **Migration 2:** `create_transaction_categories_table` (tabel kategori transaksi bisnis, arah IN/OUT/TRANSFER, default debit/credit accounts).
- [x] **Migration 3:** `create_programs_table` (tabel master program kerja).
- [x] **Migration 4:** `add_committed_amount_to_fund_allocations_table` (menambahkan `committed_amount` untuk pelacakan komitmen dana).

### Step 4.2: Domain Models & Guard Invariants
- [x] `BankAccount`: Relasi ke `Account`, guard larangan `opening_balance`, tenant scoping.
- [x] `Counterparty`: Canonical roles (`payer`, `payee`, `school`, `bank`, `vendor`, `donor`, `government`, `internal`).
- [x] `CounterpartyAlias`: Fungsi `normalize()`, pencegahan alias duplikat per organisasi.
- [x] `TransactionCategory`: Validasi arah transaksi dan relasi akun default.
- [x] `Fund`: Tipe dana (`restricted`, `unrestricted`, `temporarily_restricted`), status aktif.
- [x] `FundAllocation`: Perhitungan dinamis `available_amount = allocated - committed - disbursed + returned`.
- [x] `NegativeFundBalanceException`: Exception spesifik saat dana tidak mencukupi.
- [x] `Program`: Entitas pendukung alokasi program kerja.

### Step 4.3: Domain Services & Business Logic
- [x] `BankAccountService`:
  - `create()`, `update()`, `delete()`
  - Sinkronisasi otomatis ke `AccountMapping` (`mapping_type = 'bank_account'`)
  - Validasi akun COA harus postable dan milik tenant yang sama
  - Logging ke `AuditService`
- [x] `CounterpartyService`:
  - `create()`, `update()`, `delete()`
  - Manajemen alias (`addAlias()`, `removeAlias()`)
  - Mesin resolusi `resolve($organizationId, $rawName)`
  - Generator System Counterparty (`getOrCreateSystemBank`, `getOrCreateSystemInternal`)
  - Logging ke `AuditService`
- [x] `TransactionCategoryService`:
  - `create()`, `update()`, `delete()`
  - Validasi default accounts
  - Logging ke `AuditService`
- [x] `FundService`:
  - `createFund()`, `updateFund()`, `deleteFund()`
  - `createAllocation()`, `commit()`, `disburse()`, `returnFunds()`
  - Guard saldo negatif
  - Aggregator saldo per Fund (`getSummary()`)
  - Logging ke `AuditService`
- [x] `ProgramService`:
  - `create()`, `update()`, `delete()`
  - Logging ke `AuditService`

### Step 4.4: HTTP API Controllers & Route Binding
- [x] Buat Form Requests & validasi input yang ketat pada masing-masing controller:
  - `BankAccountController`: index, store, show, update, destroy
  - `CounterpartyController`: index, store, show, update, destroy, addAlias, removeAlias, resolve
  - `TransactionCategoryController`: index, store, show, update, destroy
  - `FundController`: index, store, show, update, destroy, allocate, commit, disburse, returnFunds, summary
  - `ProgramController`: index, store, show, update, destroy
- [x] Daftarkan route di `backend/routes/api.php` di bawah middleware:
  - `auth:sanctum`
  - `tenant.require`
  - Spatie permission checks & tenant-level isolation
- [x] Daftarkan handler `NegativeFundBalanceException` di `bootstrap/app.php`.

### Step 4.5: Master Data Seeder (Golden Dataset Baseline)
- [x] Buat `MasterDataSeeder.php` untuk memuat data baseline Maret 2026:
  1. Rekening Bank & Kas (3 BRI + Kas Tunai terhubung ke COA)
  2. System & Core Counterparties (Bank BRI, Internal, MI Darwata, dll)
  3. Kategori Transaksi Bisnis (BOS, Infaq, Iuran, Bunga Bank, Admin Bank, dll)
  4. Master Funds (Dana Abadi, Ops Ramadhan, Beasiswa)
  5. Master Programs (BOS, Kurikulum, Harlah)
- [x] Daftarkan `MasterDataSeeder` di `DatabaseSeeder.php`.

### Step 4.6: Automated Testing Suite
- [x] `MasterDataTest.php` (9 tests, 36 assertions passing 100%):
  - `test_master_data_seeder_populates_golden_dataset`
  - `test_bank_account_opening_balance_prohibited`
  - `test_bank_account_opening_balance_prohibited_via_api`
  - `test_bank_account_tenant_isolation`
  - `test_counterparty_resolution_engine`
  - `test_counterparty_resolve_via_api`
  - `test_fund_allocation_and_negative_balance_guard`
  - `test_fund_disbursement_guard_via_api`
  - `test_program_crud_via_api`

### Step 4.7: Review & Phase Gate Verification
- [x] Jalankan seluruh suite test (`php artisan test`) memastikan 100% lulus (72 tests passing).
- [x] Susun dokumen `docs/phase_4/PHASE_4_RESULT.md`.
- [x] Berhenti di Phase Gate dan laporkan kepada pengguna.

---

## 5. Kriteria Phase Gate (Pass / Fail)

Phase 4 dinyatakan **PASS** apabila:
1. Rekening bank 3 BRI + Kas Tunai terdaftar dan terhubung ke akun COA tanpa kolom `opening_balance`.
2. Resolusi counterparty (termasuk kasus MI Darwata Sindangbarang dan Bank BRI) bekerja akurat melalui alias.
3. Alokasi dana menegakkan formula saldo matematis dan menolak pencairan yang melampaui saldo tersedia.
4. Seluruh endpoint Master Data terlindungi autentikasi Sanctum, RBAC Spatie, dan isolasi tenant.
5. Seluruh test (unit & feature) lulus tanpa error atau warning.
