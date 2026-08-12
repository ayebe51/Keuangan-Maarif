# ACCOUNTING DOMAIN MAP

Dokumen ini memetakan konsep domain akuntansi inti dari spesifikasi Rev.3, yang menuntut pemahaman bahwa Sistem Keuangan LP Ma'arif ini menggunakan pendekatan **Bank-Statement-Centric**, di mana pergerakan bank adalah titik awal dari pengakuan akuntansi.

## 1. Bank Debit vs Bank Credit & Direction IN/OUT

### Masalah Mendasar
Buku Excel sumber memandang "Kredit" dan "Debet" dari perspektif rekening bank, bukan dari perspektif pembukuan LP Ma'arif (perusahaan). Jika tidak dipetakan dengan benar, Jurnal akan terbalik.

### Aturan Semantik (Semantic Contract)
- **KREDIT BANK (bank_credit_amount > 0)**
  - Makna: Uang masuk ke rekening LP Ma'arif.
  - Direction: **IN**
  - Efek Jurnal (Default): **Debit** Kas/Bank, **Kredit** Pendapatan/Piutang.
  
- **DEBET BANK (bank_debit_amount > 0)**
  - Makna: Uang keluar dari rekening LP Ma'arif.
  - Direction: **OUT**
  - Efek Jurnal (Default): **Debit** Beban/Dana, **Kredit** Kas/Bank.

## 2. Counterparty Role

`Counterparty` adalah entitas pihak ketiga. Rolenya tidak statis, melainkan bergantung pada *direction* transaksi bank saat itu.

| Bank Movement | Direction | Counterparty Role | Deskripsi |
|---|---|---|---|
| Kredit | IN | **PAYER / DEPOSITOR** | Pihak yang menyetorkan uang (misal: MI/MTs membayar piutang). |
| Kredit | IN | **BANK** (System Role) | Bank memberikan bunga/jasa giro. |
| Debet | OUT | **PAYEE / RECIPIENT** | Pihak yang menerima pencairan dana dari LP Ma'arif. |
| Debet | OUT | **BANK** (System Role) | Bank memotong biaya admin / pajak. |
| (Kredit+Debet) | TRANSFER | **INTERNAL** | Pemindahan dana antar 3 rekening BRI internal. |

## 3. Receivable & Payment

**Receivable (Piutang)** bukan sekadar saldo statis. Ini adalah entitas hidup:
- **Lifecycle:** ISSUED → PARTIALLY_PAID → PAID atau OVERDUE.
- **Payment Constraint:** Bank IN diklasifikasikan sebagai pelunasan (RECEIVABLE_PAYMENT).
- **Matching:** Satu pembayaran (Bank IN) dapat dialokasikan ke banyak Invoice/Receivable. Satu Piutang bisa dicicil berkali-kali.
- **Unapplied Cash:** Jika ada sisa pembayaran yang tidak ter-match dengan invoice, dananya berstatus **UNAPPLIED**. *Ini BUKAN pendapatan.* Harus tetap terhutang / mengendap hingga diklarifikasi.

## 4. Fund / Allocation

Akun-akun **21xx / 22xx** (seperti "Alokasi Ops Modul", "Alokasi ASAS") bukan beban (expense) murni dan bukan liabilitas statis. Ini adalah **Fund/Allocation Buckets**.
- **Model:** Uang masuk/ditugaskan ke bucket (Allocated/Committed).
- **Usage:** Saat uang keluar (Bank OUT) dengan kategori kegiatan tersebut, ini dihitung sebagai **Realized**.
- **Formula:** `Available = Allocated - Committed - Realized + Returned`.
- **Constraint:** `Realized <= Allocated`. Sistem harus memblokir pengeluaran melebihi dana yang dialokasikan, kecuali ada policy *deficit/advance*.

## 5. Journal & Posting

- **Immutable:** Journal yang sudah POSTED dilarang di-UPDATE atau di-DELETE (hard delete dilarang).
- **Reversal:** Jika salah posting, mekanisme koreksinya adalah membuat jurnal **REVERSAL** (membalik debit/kredit dengan amount sama) sebagai referensi ke jurnal asli, lalu membuat jurnal baru yang benar.
- **Constraint:** Total Debit Jurnal HARUS SAMA DENGAN Total Kredit Jurnal. Jika tidak, posting ditolak oleh Service Layer.

## 6. Reconciliation (Rekonsiliasi Bank)

Mencocokkan dunia "Bank" (Bank Transaction) dengan dunia "Sistem" (Journal Ledger).
- **Kontrol:** Statement Ending Balance harus sama dengan Ledger Ending Balance.
- **Unmatched Items:** Rekonsiliasi bisa menggantung (In Progress) bila ada mutasi bank yang belum ter-jurnal atau salah di-jurnal.
- **Dependency:** Periode Fiskal (Fiscal Period) tidak boleh di-CLOSE jika status Rekonsiliasi pada bulan tersebut masih selisih (Difference != 0), kecuali ada *reconciling item* yang di-approve eksplisit.

## 7. Fiscal Period

Siklus pelaporan bulanan (contoh: Maret 2026).
- **OPEN:** Transaksi bebas di-posting.
- **SOFT_CLOSE:** Persiapan tutup buku, hanya role tertentu (Approver/Reconciler) yang bisa melakukan posting adjustment.
- **CLOSED:** Harga mati, tidak ada jurnal yang bisa di-posting atau direverse dengan tanggal transaksi di bulan ini.
- **REOPEN:** Hanya via prosedur *authorize* ketat dan dicatat kuat di Audit Log.

## 8. Report Dependencies

Laporan **HARUS** di-generate secara hierarkis dari sumber kebenaran Ledger/Domain, dan dilarang mereferensi baris Excel.
- **Buku Bank** $\leftarrow$ Bank Transactions & Posted Ledger Bank.
- **Buku Besar / Trial Balance / Jurnal Umum / Neraca** $\leftarrow$ Posted Journal Lines.
- **Arus Kas** $\leftarrow$ Cash Ledger dikelompokkan berdasarkan Classification Rule.
- **Laporan Piutang / Aging** $\leftarrow$ Receivables & ReceivableAllocations.
- **Rincian Kas Riil** $\leftarrow$ Hasil kali silang dimensi `Fund` dengan dimensi `BankAccount/CashAccount`.
