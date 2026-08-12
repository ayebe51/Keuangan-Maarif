# OPEN DECISIONS (KEPUTUSAN YANG BELUM FINAL)

Berdasarkan analisis spesifikasi Rev.3, dokumen ini mendaftar keputusan strategis, asumsi, dan ambiguitas yang TIDAK BOLEH ditebak oleh developer (agentic AI maupun manusia). Harus ada ketetapan resmi dari *Accounting Owner* sebelum fitur terkait dirilis ke production.

Selama masa pengembangan, sistem menggunakan *Safe Default* yang tertera.

---

### D1. Basis Pelaporan (Reporting Basis)
- **Konteks:** Sistem dibangun dengan pola *Bank-Statement-Centric*, namun laporan yang dihasilkan (Neraca, Jurnal, dsb) perlu didefinisikan secara resmi apakah ini basis murni kas (Cash Basis), basis akrual penuh, atau hybrid.
- **Dampak:** Mempengaruhi kapan Pendapatan diakui, terutama jika ada pendapatan non-tunai.
- **Safe Default:** Cash-basis (pendapatan diakui saat bank IN), namun piutang tetap ditrack via buku pembantu.

### D2. Definisi Resmi Akun 21xx dan 22xx
- **Konteks:** Workbook menggunakan akun 21xx/22xx dengan label "Alokasi" (misal: "Alokasi Ops Modul").
- **Ambiguitas:** Apakah ini liabilitas murni, deferred revenue, atau sekadar *restricted fund bucket* untuk manajemen?
- **Dampak:** Jika dipetakan salah, Neraca (Balance Sheet) tidak akan seimbang atau ekuitas akan under/over-stated.
- **Safe Default:** Dianggap sebagai *Fund Liability Bucket* yang mengurangi P&L secara khusus saat Realization, bukan Expense langsung.

### D3. Mapping Kanonikal untuk Legacy Code (Khusus 5000 & 2500)
- **Konteks:** Akun `5000` (Buku Ramadhan 1447 H) memiliki sejarah transaksi yang *mixed* (campur aduk antara pendapatan dan beban operasional). Akun `2500` juga ambigu penggunaannya.
- **Ambiguitas:** Tidak mungkin di-post secara otomatis sebelum jelas ini akun Pendapatan atau Beban.
- **Safe Default:** Ditandai dengan *flag* `LEGACY_MIXED`. Transaksi yang memetakan ke akun ini harus ditinjau manual (Manual Review) sebelum diposting.

### D4. Pengakuan Revenue vs Penyelesaian Piutang (AR Settlement)
- **Konteks:** Ketika ada uang masuk (Bank IN).
- **Ambiguitas:** Kapan kita otomatis membuat jurnal Dr Bank / Cr Revenue vs Dr Bank / Cr Piutang?
- **Safe Default:** Jika ada kecocokan referensi invoice atau counterparty memiliki piutang *outstanding*, dahulukan potong piutang (AR). Jika tidak ada, masuk ke Antrean Klasifikasi (UNCLASSIFIED) agar manusia memilih, kecuali ada rule spesifik Direct Income.

### D5. Kebijakan Overpayment (Pembayaran Piutang Berlebih)
- **Konteks:** Seseorang membayar Rp 1.000.000 untuk tagihan Rp 800.000.
- **Ambiguitas:** Apakah sisa Rp 200.000 diubah menjadi saldo kredit pelanggan, diubah menjadi sumbangan (revenue), atau ditolak (block)?
- **Safe Default:** **BLOCK** alokasi melebihi jumlah outstanding. Sisa dana harus menjadi status UNAPPLIED dan butuh jurnal manual.

### D6. Ambang Batas Materialitas (Materiality Threshold) untuk Adjustment
- **Konteks:** Perubahan atau koreksi jurnal (Reversal/Adjustment).
- **Ambiguitas:** Apakah koreksi kecil (misal di bawah Rp 10.000 karena selisih pembulatan admin) membutuhkan *Approval* dari tingkat atas?
- **Safe Default:** Seluruh jurnal adjustment membutuhkan role `APPROVER` tanpa mengenal batas nilai (Threshold = Rp 0).

### D7. Matrix Persetujuan Role (Role Approval Matrix)
- **Konteks:** `APPROVER` bertugas menyetujui jurnal, rekonsiliasi, dan penutupan periode.
- **Ambiguitas:** Apakah `ACCOUNTING_ADMIN` juga boleh melakukan *posting* jurnal sehari-hari, atau mutlak harus `APPROVER`?
- **Safe Default:** Pisahkan tugas (Segregation of Duties). `ACCOUNTING_OPERATOR` membuat draft, `APPROVER` mem-posting.

### D8. Kebijakan Retensi Backup & Target Pemulihan (RPO/RTO)
- **Konteks:** Sistem keuangan rentan terhadap kegagalan infrastruktur.
- **Ambiguitas:** Berapa lama data harus bisa dikembalikan maksimal mundur ke belakang (RPO) dan seberapa cepat sistem harus nyala (RTO)?
- **Safe Default:** Backup DB dijalankan harian, dengan retensi 30 hari lokal + remote S3.

### D9. Format Pelaporan Akhir (Final Golden Output)
- **Konteks:** Output akhir dari sistem ini harus bisa menggantikan Excel Maret 2026.
- **Ambiguitas:** Apakah tata letak (layout) dan warna kolom PDF/Excel-export harus 100% sama (pixel-perfect) dengan workbook lama untuk diakui sah?
- **Safe Default:** Memastikan angka total (Totals) 100% sama dengan logika drill-down, namun desain visual tabel disesuaikan dengan template standar laporan aplikasi.

### D10. Klasifikasi Arus Kas (Cash-Flow Classification)
- **Konteks:** Laporan Arus Kas.
- **Ambiguitas:** Apakah formatnya memisahkan Operasi, Investasi, Pendanaan (Standar Akuntansi), atau persis seperti pengelompokkan manual di sheet `LAP. ARUS KAS` lama?
- **Safe Default:** Ikuti format Excel `LAP. ARUS KAS` (Custom Classification) menggunakan kategori transaksi yang telah ditetapkan.

### D11. Status Rekening 1101 (Kas Fisik) dan 1102 (BMT)
- **Konteks:** Di Neraca Awal ada saldo 1101 dan 1102, tapi mutasi bulan Maret fokus di 3 rekening BRI.
- **Ambiguitas:** Apakah 1101 dan 1102 akan di-import transaksinya setiap hari / bisa di-input manual di MVP?
- **Safe Default:** Anggap sebagai rekening saldo historis (*history-only*) di MVP. Pembuatan `BankAccount` baru untuk ini dinonaktifkan import-nya sampai ada instruksi.

### D12. Laporan "RINCIAN KAS RIIL"
- **Konteks:** Laporan ini di Excel memecah uang tunai di bank ke pos-pos peruntukan spesifik (misal Batik, Buku).
- **Ambiguitas:** Apakah ini akun akuntansi sejati (Restricted Cash) yang dijurnal secara terpisah, atau sekadar laporan manajerial dari perpotongan Fund Dimension vs Bank Dimension?
- **Safe Default:** Potongan Laporan Manajerial (Fund vs Bank). Uang tetap berada di 1 Ledger Account Bank, namun sistem query akan memilah berdasar dimensi `FundAllocation`.
