# MODULE MAP

Arsitektur aplikasi menggunakan **Modular Monolith** dengan Next.js (Frontend) dan Laravel (Backend API). Dokumen ini memetakan batas-batas modul (Bounded Contexts) pada Backend (Laravel).

## 1. Domain/Organization
**Tanggung Jawab:** Manajemen tenant, pengguna, role (RBAC), dan periode fiskal.
**Entitas Utama:**
- `Organization`
- `User`
- `Role` / `Permission`
- `FiscalPeriod` (OPEN, SOFT_CLOSE, CLOSED)
**Batasan:** Modul lain harus mengecek `FiscalPeriod` sebelum melakukan posting jurnal. Security (auth/authorization) terpusat di sini.

## 2. Domain/Accounting
**Tanggung Jawab:** Inti pencatatan akuntansi (double-entry), chart of accounts, dan integritas jurnal.
**Entitas Utama:**
- `Account` (COA dengan legacy_code)
- `AccountMapping` (Legacy ke Canonical)
- `AccountingDimension`
- `JournalEntry` (Header)
- `JournalLine` (Detail debit/kredit)
**Batasan:** Tidak ada modul lain yang boleh menulis langsung ke tabel jurnal. Semua harus melalui `AccountingEngine` untuk memvalidasi `total debit == total credit` dan status `FiscalPeriod`.

## 3. Domain/Bank
**Tanggung Jawab:** Manajemen rekening bank, proses import file Excel (bank statement), dan normalisasi raw data.
**Entitas Utama:**
- `BankAccount` (Giro 308, Giro 304, Tabungan 538)
- `BankImport` (Batch import, file hash)
- `BankTransaction` (Raw data dan normalized data)
**Batasan:** Berperan sebagai ingestion layer. Menjamin prinsip idempotensi (tidak ada duplikat transaksi bank berkat fingerprinting).

## 4. Domain/Classification
**Tanggung Jawab:** Menganalisis `BankTransaction` yang sudah dinormalisasi dan menentukan `transaction_type`, `counterparty`, dan `account`.
**Entitas Utama:**
- Rules Engine
- AI Suggestion Interface
**Batasan:** Hanya memberikan rekomendasi (`suggested_type`, `confidence`). Final state (POSTED) membutuhkan human approval atau explicit deterministic rule.

## 5. Domain/Receivable
**Tanggung Jawab:** Mengelola siklus hidup piutang dan alokasi pembayaran.
**Entitas Utama:**
- `Receivable` (Piutang master)
- `ReceivableAllocation` (Pembayaran)
**Batasan:** Satu pembayaran (`BankTransaction` IN) dapat dialokasikan ke banyak piutang. Pembayaran berlebih / tidak cocok masuk ke status `UNAPPLIED`.

## 6. Domain/Fund
**Tanggung Jawab:** Mengelola alokasi dan realisasi dana (khususnya untuk akun legacy 21xx/22xx).
**Entitas Utama:**
- `Fund` (Master alokasi/program)
- `FundAllocation` (Dana masuk / komitmen)
- `FundRealization` (Pencairan / penggunaan)
**Batasan:** Menjaga formula saldo `available = allocated - committed - realized + returned`. Realisasi tidak boleh melebihi alokasi kecuali diizinkan.

## 7. Domain/Counterparty
**Tanggung Jawab:** Resolusi dan pemetaan entitas pihak ketiga.
**Entitas Utama:**
- `Counterparty`
- `CounterpartyAlias`
**Batasan:** Mengkonsolidasi berbagai variasi nama dari Excel menjadi satu entitas (misal: "MI Darwata Sindangbarang"). Memiliki system counterparties khusus seperti `BANK` dan `INTERNAL`.

## 8. Domain/Reconciliation
**Tanggung Jawab:** Mencocokkan `BankTransaction` (statement) dengan `JournalEntry` (ledger).
**Entitas Utama:**
- `Reconciliation` (Header per periode/rekening)
- `ReconciliationItem` (Match evidence)
**Batasan:** Penutupan periode (`FiscalPeriod` CLOSE) hanya diizinkan jika reconciliation difference = 0 atau ada explicit exception approval.

## 9. Domain/Reporting
**Tanggung Jawab:** Membangun semua 12 laporan finansial.
**Entitas Utama:**
- Report Builders
**Batasan:** Read-only. HARUS bersumber dari database (Ledger, Fund, AR, Bank). DILARANG KERAS menggunakan hardcoded cell Excel.

## 10. Domain/Audit
**Tanggung Jawab:** Mencatat semua mutasi state yang sensitif.
**Entitas Utama:**
- `AuditLog`
**Batasan:** Append-only (tidak ada UPDATE/DELETE). Dicatat otomatis via middleware/event listener.

---
**Core Workflow:**
`Bank` (Import) -> `Classification` (Suggest) -> `Receivable/Fund` (Context) -> `Accounting` (Post Journal) -> `Reconciliation` (Match) -> `Reporting` (View).
