# DATA DICTIONARY REVISED (CORRECTED)

Dokumen ini menjelaskan struktur data dan tipe kolom kritikal untuk memastikan kepatuhan terhadap standar finansial yang ketat, pencegahan data loss, dan penyelarasan dengan spesifikasi teknis Rev.3.

## 1. Tipe Data Uang (Money Model)
Sistem **DILARANG KERAS** menggunakan `FLOAT` atau `DOUBLE` untuk nilai moneter.
- **Tipe Kolom:** `DECIMAL(18, 2)` (atau `NUMERIC(18, 2)` pada PostgreSQL).
- **Presisi:** Mendukung hingga triliunan rupiah dengan ketelitian sen.
- **Rounding Policy:** Pembulatan standar (Half-Up) pada level aplikasi jika terdapat pembagian (alokasi proporsional), namun total debit vs kredit harus *exact match*.
- **Mata Uang:** Base currency diasumsikan IDR, tidak ada konversi multi-currency pada MVP.

## 2. Struktur Akun (Chart of Accounts)
Akun menggunakan hierarchical approach. Standar kanonikal:
- `1000 - 1999`: ASSET (Assets, normal balance DEBIT)
- `2000 - 2999`: LIABILITY (Liabilities, normal balance CREDIT)
- `3000 - 3999`: EQUITY (Equity / Fund Balance, normal balance CREDIT)
- `4000 - 4999`: REVENUE (Pendapatan, normal balance CREDIT)
- `5000 - 5999`: EXPENSE (Beban, normal balance DEBIT)

## 3. Saldo Awal Bank (Opening Balance Source)
Tabel `BankAccount` **tidak memiliki kolom `opening_balance`**. Sebagai gantinya, digunakan entitas `OpeningBalanceSource`:
- **Fungsi:** Menyimpan rekam jejak deklarasi saldo awal (misal dari "Neraca Awal").
- **Workflow:** `OpeningBalanceSource` -> men-generate `JournalEntry` bersatus POSTED -> meng-update Ledger.
- **Kolom:** `amount`, `source_date`, `source_type`.

## 4. Bank Transaction (Raw vs Typed)
`bank_transactions` dilarang hanya menyimpan JSON. Harus ada ekstraksi typed columns:
- `transaction_date` (DATE)
- `description` (TEXT)
- `debit_amount` (DECIMAL)
- `credit_amount` (DECIMAL)
- `direction` (VARCHAR: 'IN', 'OUT', 'TRANSFER')
- `balance` (DECIMAL)
- `fingerprint` (VARCHAR/Hash, UNIQUE Constraint)
- `raw_data` (JSONB): Menyimpan row Excel murni untuk fidelity.
- **Traceability:** `source_file`, `source_sheet`, `source_row` wajib terisi.

## 5. Fund Model (Policy-Neutral)
Satu tabel `funds` tidak mencampur event. Dipecah menjadi 5 tabel, di mana **semua tabel** (FundAllocation, FundCommitment, FundRealization, FundReturn) wajib memiliki `organization_id` yang NON-NULL.
- Pada entitas `Fund` master, referensi akun adalah `account_id` yang **netral**, bukan `liability_account_id`, agar skema database tidak mengunci keputusan "Liability vs Restricted Equity" sebelum Accounting Owner menetapkannya.

## 6. Receivable Model (Piutang)
Tabel `receivables` menggunakan status terpisah untuk siklus hidup: `ISSUED`, `PARTIALLY_PAID`, `PAID`, `OVERDUE`, `CANCELLED`.
Pembayaran ditangani oleh `receivable_allocations` (wajib ber-`organization_id`) yang memetakan pembayaran ke piutang. Unapplied payment tidak dimasukkan sebagai alokasi, melainkan menggantung di buku *Unapplied Cash Liability* hingga dialokasikan secara manual.

## 7. RBAC (Spatie Permission)
Struktur hak akses sepenuhnya mengacu pada skema bawaan paket `spatie/laravel-permission` (tabel `roles`, `permissions`, `model_has_roles`, `role_has_permissions`). Entitas khusus `RolePermission` telah dihapus.
