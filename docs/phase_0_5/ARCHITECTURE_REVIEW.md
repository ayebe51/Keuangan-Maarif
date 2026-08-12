# ARCHITECTURE REVIEW

Dokumen ini membedah kesiapan arsitektur Sistem Keuangan LP Ma'arif NU PCNU Cilacap, dengan fokus pada pemisahan batas (boundaries), aliran data akuntansi yang mutlak, dan semantik bank.

## 1. Critical Accounting Flow (Aliran Mutlak)
Sistem ini menggunakan arsitektur event/transaction pipeline yang bersifat **forward-only** dengan presisi.

**Flow yang Disetujui (Approved Flow):**
`RAW EXCEL` → `RAW BANK TRANSACTION` → `NORMALIZED BANK TRANSACTION` → `BUSINESS TRANSACTION` → `CLASSIFICATION` → `JOURNAL DRAFT` → `APPROVAL` → `POSTED JOURNAL` → `LEDGER` → `REPORT`

**Anti-Patterns yang DILARANG KERAS:**
- ❌ **Excel → Direct Report:** Laporan tidak boleh membaca langsung baris excel. Laporan murni hasil query Ledger dan Domain.
- ❌ **AI → Direct Posting:** AI hanya memberikan `confidence` dan `suggested_type` pada tahapan `CLASSIFICATION`. Final approval selalu ada di rule deterministik atau campur tangan manusia.
- ❌ **Bank Transaction → Direct Revenue:** Uang masuk tidak serta-merta diakui sebagai pendapatan. Harus melewati Business Transaction untuk mengecek apakah ini pelunasan piutang (AR Settlement), dana titipan (Fund), atau pendapatan langsung.

## 2. Bank Semantics vs Journal Semantics
Arsitektur wajib secara tegas memisahkan "Bank Side" dan "Accounting Side".

**Bank Semantics:**
Berdasarkan pembacaan rekening koran / bank statement:
- **Bank Credit (`bank_credit_amount > 0`)** = CASH IN (Uang Masuk ke Rekening)
- **Bank Debit (`bank_debit_amount > 0`)** = CASH OUT (Uang Keluar dari Rekening)

**Accounting Semantics:**
- Kas bertambah = **Journal Debit**
- Pendapatan bertambah / Piutang berkurang = **Journal Credit**
- Beban bertambah = **Journal Debit**
- Kas berkurang = **Journal Credit**

**Golden Case:** MI Darwata Sindangbarang, Kredit Rp852.000.
1. `NormalizedBankTransaction` menangkapnya sebagai: `direction = IN`, `counterparty_role = PAYER / DEPOSITOR`.
2. `Classification` mencari piutang / aturan pendapatan.
3. `JournalEntry` diposting sebagai:
   - Debit: Kas Bank (Rp852.000)
   - Kredit: Piutang / Pendapatan (Rp852.000)

Arsitektur melarang keras mengasumsikan Bank Credit sebagai Journal Credit. Keduanya adalah entitas logika yang berbeda.

## 3. Reporting Source of Truth
Seluruh laporan (Buku Bank, Jurnal Umum, Buku Besar, Trial Balance, Neraca, Laporan Arus Kas, dll) wajib dibangun dengan menjumlahkan baris-baris `POSTED LEDGER` (yakni `journal_lines` dari jurnal berstatus POSTED) serta data domain yang telah disahkan.

Kemampuan *Drill-down* wajib ada:
`Report` → `Journal Entry` → `Journal Line` → `Business Transaction` → `Bank Transaction` → `Raw Source Row (JSONB)`

## 4. Import & Idempotency Architecture
File Bank Statement diimpor dengan arsitektur idempotent untuk mencegah penggandaan transaksi finansial (double accounting).
1. `UPLOAD`
2. `FILE HASH` (Mencegah unggah file fisik yang sama).
3. `IMPORT BATCH`
4. `RAW ROW` (Dipertahankan di JSONB).
5. `NORMALIZATION`
6. `FINGERPRINT` (Hash dari `org_id + bank_account_id + date + seq + debit + credit + raw_desc`). Database akan menolak duplikat fingerprint menggunakan *Unique Constraint*.

## 5. Rincian Makmur (Out of Scope)
Secara arsitektur, `RINCIAN MAKMUR` dipastikan tidak ada dalam *bounded context* manapun. Tidak ada modul, tabel, import, report, UI, atau migrasi yang akan dibuat untuk Rincian Makmur.
