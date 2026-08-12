# PHASE 0.5 DECISION LOG (CORRECTED)

Log keputusan yang diambil selama proses Review Arsitektur & Model Data (Phase 0.5) beserta perbaikan final (*Final Consistency Review*). Catatan ini mengunci arah teknis agar *developer* tidak menyimpang selama implementasi Phase 1.

| No | Kategori | Keputusan Arsitektural / Data Model | Justifikasi (Alasan) |
|----|----------|-------------------------------------|----------------------|
| 01 | Multi-Tenant | Seluruh tabel termasuk sub-ledger (`FundAllocation`, `ReceivableAllocation`, dll) WAJIB menggunakan `organization_id`. | Menjamin isolasi data total dan mencegah kebocoran referensi antar tenant via Query yang salah. |
| 02 | Bank Semantics | Memisahkan mentah-mentah kolom `bank_credit_amount` dan `bank_debit_amount` dari nilai Debit/Kredit buku besar. | Kredit Bank adalah Uang Masuk, Kredit Jurnal Kas adalah Uang Keluar. Harus ada jembatan rekonsiliasi. |
| 03 | Immutability | Kolom pada `audit_logs` dan `journal_entries` (setelah POSTED) tidak memiliki mekanisme `UPDATE`/`DELETE`. | Menjamin *audit trail* sesuai standar pelaporan keuangan yang diakui auditor eksternal. |
| 04 | Fund Model | Meniadakan *single-table fund pattern*. Pemisahan *allocations*, *commitments*, *realizations*, *returns*. Penamaan FK menggunakan `account_id` netral. | Menghindari penguncian skema pada kebijakan Liabilitas vs Ekuitas (masih Open Decision). |
| 05 | Money Type | PostgreSQL `DECIMAL(18,2)` dipilih mutlak di seluruh kolom yang menyangkut uang (saldo, debit, kredit, alokasi). | Mencegah pembulatan ireguler *(floating-point rounding errors)* yang merusak kesatuan balancing jurnal `total debit = total credit`. |
| 06 | Rincian Makmur | Diblokir total dari sistem (Tidak ada modul, tabel, migration). | Menghindari *scope creep* sesuai ketetapan spesifikasi. |
| 07 | JSONB Usage | Digunakan untuk `raw_data` di mutasi bank dan `before_json`/`after_json` di jejak audit. Tidak boleh menggantikan field Typed (relational). | Mempertahankan fidelitas penuh data Excel asli jika di masa depan ada perubahan logika *parsing/normalizing*. |
| 08 | Opening Balance | Dilarang menggunakan kolom `opening_balance` di tabel `bank_accounts`. Menggunakan `OpeningBalanceSource` -> Journal. | Angka saldo harus dapat diverifikasi dengan prinsip *double-entry* dan *drill-down*. |
| 09 | RBAC System | Meniadakan entitas *custom* (`RolePermission`). Sepenuhnya mengikuti package bawaan `spatie/laravel-permission`. | Standarisasi keamanan dan menghindari konflik skema (Duplicate RBAC). |
| 10 | Idempotency | Kolom `row_fingerprint` di-generate pada level normalisasi transaksi bank dan diberi UNIQUE constraint di DB. | Mengunggah ulang file Excel rekening koran yang sama secara berulang tidak akan menggandakan jurnal. |
