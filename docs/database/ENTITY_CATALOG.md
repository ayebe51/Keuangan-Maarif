# ENTITY CATALOG

Dokumen ini berisi katalog lengkap entitas database berdasarkan arsitektur Modular Monolith (Laravel) untuk Sistem Keuangan LP Ma'arif. Tabel-tabel ini mendukung isolasi multi-tenant, integritas jurnal, pelacakan alokasi, serta prinsip auditabilitas yang kuat (append-only log).

## 1. Domain Organization (Tenant & Auth)

### `organizations`
Mewakili entitas LP Ma'arif atau cabang/unit lainnya (untuk kesiapan multi-tenant).
- **Kolom Utama:** `id`, `code`, `name`, `timezone`, `is_active`

### `users`
Pengguna sistem dengan akses ke tenant tertentu.
- **Kolom Utama:** `id`, `organization_id`, `name`, `email`, `password_hash`, `role`, `is_active`

### `fiscal_periods`
Mengontrol status buku per bulan/tahun (misalnya: Maret 2026).
- **Kolom Utama:** `id`, `organization_id`, `year`, `month`, `status` (OPEN, SOFT_CLOSE, CLOSED), `opened_at`, `closed_at`, `closed_by`
- **Constraint:** Transaksi/Jurnal hanya boleh diposting ke periode dengan status `OPEN` atau `SOFT_CLOSE` (khusus role tertentu).

## 2. Domain Accounting (Core Ledger)

### `accounts`
Chart of Accounts (COA) / Buku Besar.
- **Kolom Utama:** `id`, `organization_id`, `code`, `name`, `account_type`, `normal_balance`, `legacy_code`, `report_group`, `is_legacy_mixed`, `is_active`

### `account_mappings`
Peta aturan resolusi dari kode Excel lama ke `accounts` baru.
- **Kolom Utama:** `id`, `organization_id`, `legacy_code`, `canonical_account_id`, `effective_date`, `approved_by`

### `journal_entries`
Header dari Jurnal (Double-Entry).
- **Kolom Utama:** `id`, `organization_id`, `fiscal_period_id`, `business_transaction_id`, `journal_no`, `date`, `memo`, `status` (DRAFT, POSTED, REVERSED), `posted_at`, `posted_by_id`, `reversal_of_id`

### `journal_lines`
Detail baris jurnal (Debit/Kredit).
- **Kolom Utama:** `id`, `journal_entry_id`, `account_id`, `debit`, `credit`, `counterparty_id`, `receivable_id`, `fund_id`, `line_no`
- **Constraint:** Kolom `debit` dan `credit` tidak boleh keduanya $> 0$ dalam satu baris.

## 3. Domain Bank & Ingestion

### `bank_accounts`
Rekening sumber daya tunai (misal: Giro 308, Giro 304, Tabungan 538).
- **Kolom Utama:** `id`, `organization_id`, `bank_name`, `account_no_masked`, `account_name`, `coa_account_id`, `opening_balance`

### `bank_imports`
Header file/batch mutasi bank yang diunggah.
- **Kolom Utama:** `id`, `bank_account_id`, `file_name`, `file_hash` (mencegah upload ulang file yang sama), `mapping_version`, `source_name`, `imported_by`, `status`

### `bank_transactions`
Transaksi bank (gabungan raw data JSON dan field yang dinormalisasi).
- **Kolom Utama:** `id`, `bank_import_id`, `bank_account_id`, `raw_row_json`, `row_fingerprint` (kunci unik idempotensi), `raw_date`, `raw_description`, `legacy_account_code`, `bank_debit_amount`, `bank_credit_amount`, `direction` (IN/OUT/TRANSFER), `normalized_amount`, `transaction_type_id`, `classification_status`, `reconciliation_status`

## 4. Domain Classification & Counterparty

### `transaction_categories`
Master kategori tipe bisnis untuk rule engine.
- **Kolom Utama:** `id`, `code`, `name`, `direction`, `default_debit_account_id`, `default_credit_account_id`

### `counterparties`
Pihak ke-3 (Sekolah, Bank, dll).
- **Kolom Utama:** `id`, `organization_id`, `name`, `type` (INTERNAL, EXTERNAL, BANK), `external_code`, `is_active`

### `counterparty_aliases`
Variasi nama di rekening koran yang merujuk ke entitas yang sama.
- **Kolom Utama:** `id`, `counterparty_id`, `normalized_alias`

## 5. Domain Business Entities

### `business_transactions`
Entitas agregat dari kejadian bisnis yang memicu penjurnalan.
- **Kolom Utama:** `id`, `organization_id`, `transaction_no`, `date`, `transaction_type`, `direction`, `counterparty_id`, `amount`, `description`, `source_type` (dari BANK_TX, MANUAL, dll), `source_id`, `approval_status`, `fiscal_period_id`

### `receivables`
Buku Pembantu Piutang.
- **Kolom Utama:** `id`, `organization_id`, `counterparty_id`, `account_id`, `invoice_no`, `category`, `academic_year`, `due_date`, `original_amount`, `outstanding_amount`, `status` (ISSUED, PARTIALLY_PAID, PAID, OVERDUE)

### `receivable_allocations`
Catatan cicilan/pembayaran yang memotong `outstanding_amount`.
- **Kolom Utama:** `id`, `receivable_id`, `business_transaction_id`, `journal_entry_id`, `allocated_amount`, `allocated_at`

### `funds`
Buku Pembantu Dana/Alokasi (menggantikan logika hardcode 21xx/22xx).
- **Kolom Utama:** `id`, `organization_id`, `code`, `name`, `fiscal_year`, `source_program`, `purpose`, `source_account_id`, `liability_account_id`, `status`

### `fund_allocations`
Mutasi dana masuk atau di-*commit*.
- **Kolom Utama:** `id`, `fund_id`, `date`, `allocated_amount`, `committed_amount`, `realized_amount`, `returned_amount`, `beneficiary_counterparty_id`, `purpose`, `status`

### `fund_realizations`
Mutasi dana keluar (penggunaan).
- **Kolom Utama:** `id`, `fund_allocation_id`, `business_transaction_id`, `journal_entry_id`, `amount`, `date`

## 6. Domain Reconciliation & Audit

### `reconciliations`
Laporan perbandingan rekening koran dengan ledger per periode.
- **Kolom Utama:** `id`, `bank_account_id`, `fiscal_period_id`, `statement_opening`, `statement_ending`, `ledger_opening`, `ledger_ending`, `difference`, `status` (OPEN, IN_PROGRESS, RECONCILED, CLOSED), `reconciled_by`, `reconciled_at`

### `reconciliation_items`
Bukti pencocokan per baris.
- **Kolom Utama:** `id`, `reconciliation_id`, `bank_transaction_id`, `journal_entry_id`, `match_method` (EXACT, STRONG, COMPOSITE, MANUAL), `match_status`, `evidence`, `matched_by`

### `audit_logs`
Catatan jejak sistem (Append-only).
- **Kolom Utama:** `id`, `organization_id`, `user_id`, `entity_table`, `entity_id`, `action` (CREATE, UPDATE, DELETE, POST, REVERSE, dll), `before_json`, `after_json`, `reason`, `ip_address`, `created_at`
- **Constraint:** Tidak boleh ada operasi `DELETE` maupun `UPDATE` pada tabel ini secara arsitektur.
