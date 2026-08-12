# GOLDEN DATASET STRATEGY (CORRECTED)

Dataset bulan Maret 2026 (`3. MARET 2026.xlsx`) adalah **Golden Regression Fixture**. Dataset ini di-load pada Phase 1.

## 1. Expected Opening Balance Strategy (Saldo Awal via Journal)
Saldo awal bank **dilarang** dimasukkan sebagai angka mutlak di kolom konfigurasi master (seperti `opening_balance` pada tabel `bank_accounts`). 
- **Solusi Tepat:** Menggunakan tabel `OpeningBalanceSource` yang menampung rekaman saldo per 1 Maret 2026. Entitas ini selanjutnya di-posting menjadi `JournalEntry` (Opening Balance Journal). Laporan neraca akan menyedot saldo awal langsung dari Ledger.

## 2. Transaction Semantics Mapping (Contoh Kasus Golden)
**MI Darwata Sindangbarang, Kredit Rp852.000:**
- Raw Bank Side: `bank_credit_amount` = 852.000.
- Normalization (Bank Semantic): `direction` = IN.
- Business Semantic: Counterparty Role = PAYER / DEPOSITOR.
- Classification Treatment: Klasifikasi (Rule Engine) akan menentukan apakah ini akan menghasilkan jurnal `Dr Bank, Cr Receivable` atau tindakan spesifik lainnya yang didukung oleh Rulebook.
- **Peringatan:** Angka kredit bank tidak boleh secara instan diasumsikan sebagai "Revenue".

## 3. Fixture Accounts & RINCIAN MAKMUR
- Semua 3 rekening BRI + 2 Kas (historis) disertakan.
- **RINCIAN MAKMUR** tetap dan akan selamanya menjadi area **OUT OF SCOPE**. Tidak di-load, tidak dimasukkan dalam modul, dan diabaikan dalam pipeline impor.
