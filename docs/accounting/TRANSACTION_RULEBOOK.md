# TRANSACTION RULEBOOK

Buku aturan ini mendefinisikan secara pasti (deterministic) bagaimana suatu transaksi bank diklasifikasikan dan diposting ke dalam jurnal akuntansi. Aturan ini bersumber dari Spesifikasi Rev.3 dan TIDAK BOLEH diubah tanpa persetujuan pihak otoritas akuntansi.

## Prinsip Dasar (Rulebook Final REV3-24)

- **R01** Bank CREDIT ≠ Journal CREDIT. Bank CREDIT means cash-in.
- **R02** Bank DEBIT ≠ Journal DEBIT. Bank DEBIT means cash-out.
- **R03** Direction (IN/OUT/TRANSFER) diturunkan dari pergerakan bank (bank movement).
- **R04** Counterparty role (Payer/Payee/Bank) mengikuti direction dan konteks.
- **R05** Unapplied cash BUKAN revenue secara default.
- **R06** Penyelesaian piutang (Receivable settlement) mengurangi outstanding AR, BUKAN menambah revenue.
- **R07** Transfer antar-rekening bersifat internal dan P&L-neutral (tidak mempengaruhi laba/rugi).
- **R08** Penggunaan dana/alokasi membutuhkan account mapping yang sudah diapprove (jangan sembarang tembak akun 22xx sebagai expense).
- **R09** Posted journals bersifat IMMUTABLE. Koreksi menggunakan metode jurnal Reversal.
- **R10** File import bersifat idempotent. Baris data mentah (raw rows) dari Excel wajib dipertahankan.
- **R11** Penutupan periode (Period Close) dipaksakan di level database / service layer.
- **R12** Report dihasilkan dari data Ledger, BUKAN dari hardcode baris/kolom spreadsheet.
- **R13** Sistem AI hanya bersifat merekomendasikan (suggests). Aturan yang diapprove (approved rules/humans) yang menentukan posting.
- **R14** RINCIAN MAKMUR adalah OUT OF SCOPE.
- **R15** Data Maret 2026 adalah golden regression fixture. Jurnal harus balance dan cocok dengan angka di dokumen sumber.

---

## Transaction Taxonomy & Posting Matrix

### 1. RECEIVABLE_PAYMENT (Pelunasan Piutang)
- **Kondisi:** Direction IN + Cocok dengan Receivable yang masih outstanding.
- **Jurnal:**
  - `Debit` : Akun Bank (Kas/Bank)
  - `Kredit`: Akun Piutang (Receivable, misal 13xx)
  
### 2. DIRECT_INCOME (Pendapatan Langsung)
- **Kondisi:** Direction IN + Kategori Pendapatan (tanpa Piutang sebelumnya).
- **Jurnal:**
  - `Debit` : Akun Bank (Kas/Bank)
  - `Kredit`: Akun Pendapatan (Revenue, misal 41xx)

### 3. BANK_INTEREST (Bunga Bank)
- **Kondisi:** Direction IN + Counterparty = `BANK`.
- **Jurnal:**
  - `Debit` : Akun Bank (Kas/Bank)
  - `Kredit`: Akun Pendapatan Bunga/Lain-lain (misal 4116)

### 4. BANK_FEE_TAX (Biaya Admin & Pajak Bank)
- **Kondisi:** Direction OUT + Counterparty = `BANK`.
- **Jurnal:**
  - `Debit` : Akun Beban Bank (misal 5116 Biaya Admin)
  - `Kredit`: Akun Bank (Kas/Bank)

### 5. OPERATING_EXPENSE (Beban Operasional)
- **Kondisi:** Direction OUT + Kategori Beban.
- **Jurnal:**
  - `Debit` : Akun Beban (Expense, misal 51xx)
  - `Kredit`: Akun Bank (Kas/Bank)

### 6. ALLOCATION_DISBURSEMENT (Pencairan Dana Alokasi)
- **Kondisi:** Direction OUT + Target adalah entitas `Fund`.
- **Jurnal:**
  - `Debit` : Akun Dana/Alokasi (misal 21xx/22xx sesuai mapping)
  - `Kredit`: Akun Bank (Kas/Bank)

### 7. BANK_TRANSFER (Mutasi Antar Rekening Internal)
- **Kondisi:** Pemindahan uang antar rekening bank LP Ma'arif (2 leg: OUT dan IN).
- **Jurnal:**
  - `Debit` : Akun Bank Tujuan (Destination)
  - `Kredit`: Akun Bank Sumber (Source)

### 8. CASH_TRANSACTION (Transaksi Kas Fisik)
- **Kondisi:** Mutasi In/Out pada buku Kas (bukan bank).
- **Jurnal:** 
  - Sesuai dengan IN/OUT, namun menggunakan Akun Kas (1101).

---

## Classification Precedence (Prioritas Penggolongan)

Sistem akan mencoba mengklasifikasikan transaksi bank yang masuk (Normalized) berdasarkan urutan prioritas berikut:

1. **Explicit Approved User Mapping:** User secara manual menetapkan pemetaan pasti untuk transaksi ini. → **AUTO-POST**
2. **Exact Invoice/Reference Match:** ID Invoice atau Referensi persis cocok. → **AUTO-POST**
3. **Approved Legacy Code Mapping:** Terdapat mapping kode akun lama (misal 1314) yang telah disahkan ke akun kanonikal. → **AUTO-POST**
4. **Deterministic Category Rule:** Aturan kaku berbasis teks (contoh: deskripsi mengandung "Pajak Bulanan" → BANK_FEE_TAX). → **AUTO-POST**
5. **Counterparty + Category + Amount/Date:** Aturan komposit historis. → **SUGGESTION ONLY**
6. **AI/Fuzzy Suggestion:** Tebakan mesin (AI) dengan score confidence. → **SUGGESTION ONLY** (Tidak Boleh Diposting Otomatis)
7. **UNCLASSIFIED:** Tidak memenuhi satupun kriteria di atas. → Masuk Antrean Manusia (Exception Queue).
