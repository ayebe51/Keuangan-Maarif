# OPEN DECISIONS (REVISED)

Berikut adalah rekapitulasi keputusan akuntansi dan teknis yang masih berstatus **OPEN (TBD)**. 
Saya **TIDAK** menutup (menetapkan) keputusan ini karena kewenangan penetapan berada di tangan *Accounting Owner*, dan dokumen spesifikasi belum merincinya. Jika dipaksakan (asumsi ditebak), integritas golden dataset dapat terancam.

| Decision | Current Assumption (Safe Default) | Source | Risk | Required Decision | Impact if Unresolved |
|---|---|---|---|---|---|
| **D1. Basis Pelaporan** | Cash Basis (Pendapatan diakui saat kas masuk) | Implementation Plan Rev.1 | Medium | Laporan apakah murni Cash Basis atau Hybrid Accrual? | P&L dan Neraca bisa missmatch dengan ekspektasi PSAK organisasi. |
| **D2. Definisi Resmi 21xx/22xx** | Fund Liability Bucket (Menurunkan ekuitas/pendapatan saat dana masuk ke bucket) | Implementation Plan Rev.1 | High | Apakah ini Kewajiban (Liability) atau Restricted Equity? | Nilai ekuitas di Neraca bisa meleset jika klasifikasi induknya salah. |
| **D3. Saldo Awal Piutang vs Revenue** | Pelunasan tagihan dipotong ke Piutang, bukan diklaim ulang sebagai Revenue berjalan | Accounting Principles | High | Bagaimana cara membedakan uang masuk dari tagihan tahun lalu vs tagihan baru? | Overstatement pendapatan bulan Maret jika tagihan lama dihitung ulang sebagai revenue baru. |
| **D4. Kebijakan Overpayment** | BLOCK (Sistem menolak alokasi pembayaran melebihi sisa hutang) | Implementation Plan Rev.1 | Low | Apa yang harus dilakukan dengan kelebihan transfer (Sumbangan atau Unapplied Cash)? | Jika unapplied cash dibiarkan menumpuk, bank vs ledger bisa reconcile tapi AR menjadi ambigu. |
| **D5. Ambang Batas Approval Adjustment** | Threshold Rp0 (Semua adjustment butuh APPROVER) | Implementation Plan Rev.1 | Low | Apakah koreksi di bawah nilai Rp10.000 boleh di-post langsung oleh Operator? | Proses closing bulan bisa lambat jika banyak perbedaan receh (admin bank) yang tertahan persetujuan. |
| **D6. Status Rekening 1101 (Kas Fisik)** | Rekening saldo historis (Tidak diimpor harian pada MVP) | Workbook Neraca Awal | Medium | Apakah arus kas fisik benar-benar terjadi, atau hanya pencatatan formalitas bulanan? | Rekonsiliasi kas tunai akan selalu mandek jika pergerakan fisiknya tidak dilaporkan rutin. |
| **D7. Penggunaan Akun 5000 / 2500** | Flag `LEGACY_MIXED`. Wajib direview manual oleh manusia sebelum posting | Analisis Workbook | High | Apa canonical mapping (1-to-1) untuk akun ambigu ini? | Transaksi bisa masuk ke beban operasi (expense) padahal seharusnya pengurang liabilitas, merusak balance. |
| **D8. RINCIAN KAS RIIL Report** | Report manajerial yang dihasilkan dari cross-tabulation (Fund vs Bank) | Spesifikasi Rev.3 | Medium | Apakah "Batik", "Buku" itu sub-rekening kas, atau murni dimensi alokasi dana? | Jika dimodelkan sebagai sub-rekening bank riil, akan sangat sulit disinkronkan dengan rekening koran BRI. |

**Keputusan yang SUDAH DITUTUP (TIDAK BOLEH DIRUBAH):**
- **Semantik Bank:** Kredit = IN, Debet = OUT.
- **Rincian Makmur:** OUT OF SCOPE (Tidak dimasukkan ke sistem).
- **Golden Dataset:** Excel Maret 2026 adalah sumber regresi final.
