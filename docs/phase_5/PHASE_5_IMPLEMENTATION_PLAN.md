# IMPLEMENTATION PLAN: PHASE 5 — BANK INGESTION & IMPORT ENGINE (REVISED & HARDENED)
**Sistem Keuangan LP Ma'arif NU PCNU Cilacap**  
**Status Baseline:** Phase 4 (Master Data Foundation) SELESAI & LULUS GATE (72 Tests Passing)  
**Target:** Phase 5 — Bank Statement Ingestion, Raw Preservation, Normalization, Idempotency & Running Balance Validation  
**Specification of Record:** `Spesifikasi_Teknis_Sistem_Keuangan_LP_Maarif_REV3_Production_Ready.docx` (REV3-04, REV3-06, REV3-13, REV3-22)  
**Golden Dataset:** Workbook Rekening Koran & Mutasi Maret 2026 (`3. MARET 2026.xlsx`)  

---

## 1. Ringkasan Eksekutif & Tujuan Phase 5

Phase 5 membangun subsistem **Bank Ingestion & Import Engine** yang bertugas menelan (*ingest*) file rekening koran bank (BRI Giro 308, BRI Giro 304, BRI Tabungan 538, serta Buku Kas) ke dalam sistem secara aman, terverifikasi, dan sepenuhnya idempoten.

Sesuai aturan arsitektur Rev.3 (*Source-of-Truth Hierarchy*):
```text
RAW SOURCE (File Mentah / Baris Spreadsheet)
    ↓
RAW PRESERVATION (bank_raw_sources: File, Sheet, Row, JSON)
    ↓
NORMALIZATION & FINGERPRINTING (Arah IN/OUT, Row Sequence, Counterparty)
    ↓
RUNNING BALANCE VALIDATION (Deteksi gap/lonjakan saldo berjalan)
    ↓
NORMALIZED BANK TRANSACTION (bank_transactions: Status UNMATCHED)
    ↓
BUSINESS TRANSACTION (Phase 6 - Classification)
    ↓
JOURNAL (Phase 7 - Double-Entry Ledger)
```

Modul ini **bukan sekadar file uploader**, melainkan gerbang validasi akuntansi yang menjamin:
1. **Preservasi Sumber Mentah (*Raw Source Preservation*)**: Setiap baris mutasi bank yang dibaca wajib disimpan persis apa adanya (`raw_data` JSON, nama file, nomor baris, dan nama sheet) sebelum diproses, agar audit trail dapat melacak setiap angka laporan keuangan hingga ke baris file perbankan aslinya.
2. **Pembalikan Semantik Perbankan (*Bank Semantics Invariant*)**:
   $$\text{Kredit Bank} = \text{Kas Masuk (IN)}, \quad \text{Debet Bank} = \text{Kas Keluar (OUT)}$$
   Menghindari kekeliruan fatal perbankan vs akuntansi (*Bank Credit is NOT Journal Credit*).
3. **Idempotensi & Sidik Jari Mutasi Tanpa False-Positive (*Collision-Free Fingerprinting*)**:
   Sesuai mandat REV3-06:
   $$\text{fingerprint} = \text{hash}(\text{org\_id} + \text{bank\_account\_id} + \text{date} + \text{row\_sequence} + \text{direction} + \text{amount} + \text{normalized\_description})$$
   Penggunaan `row_sequence` (nomor urut kejadian mutasi pada tanggal yang sama) mencegah terhapusnya transaksi kembar yang sah (misal 2x biaya admin bank atau 2x setoran identik di hari yang sama), sekaligus tetap menjamin **0 duplikasi transaksi finansial** jika file atau baris yang sama diunggah ulang.
4. **Validasi Saldo Berjalan (*Running Balance Verification - REV3-06*)**:
   $$\text{Saldo Berjalan Dihitung} = \text{Saldo Sebelumnya} + \text{Kredit (IN)} - \text{Debet (OUT)}$$
   Jika bank mencantumkan saldo berjalan dan terdapat selisih (*gap* atau manipulasi data), sistem **tidak boleh melakukan koreksi diam-diam (*silent correction*)**, melainkan mencatatnya sebagai `exception` dan menandai batch import.
5. **Integrasi Profil Pemetaan Fleksibel (*Statement Mapping Profiles*)**:
   Mendukung variasi layout kolom rekening koran perbankan (BRI Giro, BRI Tabungan, Buku Kas, Generic CSV) secara deklaratif melalui versi pemetaan (`mapping_version`).
6. **Integrasi Resolusi Rekanan Otomatis (*Counterparty Auto-Resolution*)**:
   Setiap baris yang dinormalisasi langsung dihubungkan ke master counterparty melalui engine resolusi 3-tier Phase 4 (contoh: *MI Darwata Sindangbarang* atau *Adm Bank* $\rightarrow$ *Bank BRI*).
7. **Penyimpanan File Fisik Aman (*Immutable Storage*)**:
   File yang diunggah disimpan pada storage terproteksi per tenant (`storage/app/bank_imports/{tenant_id}/{year}/{hash}.ext`) untuk pemenuhan kepatuhan audit regulasi.

---

## 2. Invariants & Aturan Bisnis Kritis Phase 5

1. **INVARIANT: Semantik Arah Arus Kas Perbankan (REV3-02, REV3-22)**  
   - Kolom Kredit pada rekening koran menunjukkan penambahan saldo bank $\implies$ `direction = IN`.
   - Kolom Debet pada rekening koran menunjukkan pengurangan saldo bank $\implies$ `direction = OUT`.
   - Nilai nominal (`amount`) selalu bernilai mutlak positif ($> 0$), disimpan dengan tipe data fixed-point `DECIMAL(18, 2)`, dan **dilarang menggunakan tipe FLOAT**.

2. **INVARIANT: Idempotensi Impor & Zero Financial Duplication (REV3-06)**  
   - Dua tingkat perlindungan duplikasi:
     1. **File-Level:** Hash SHA-256 dari seluruh isi file (`file_hash`) dicatat pada `bank_imports`. Upaya mengunggah file identik untuk rekening yang sama ditolak (`DuplicateImportException`) kecuali pengguna memilih mode re-evaluasi.
     2. **Row-Level:** Kombinasi `(organization_id, fingerprint)` dilindungi oleh *Unique Constraint* di basis data pada tabel `bank_transactions`. Baris mutasi yang sidik jarinya telah terdaftar otomatis dilewati (`skipped_rows++`).

3. **INVARIANT: Preservasi Audit Trail Sumber Mentah (Raw Source Immutability)**  
   - Data mentah pada tabel `bank_raw_sources` bersifat *append-only* dan *read-only*. Sekali tersimpan, baris mentah tidak boleh diubah atau dihapus, menjadi bukti audit forensik primer.

4. **INVARIANT: Saldo Berjalan Tidak Boleh Dikoreksi Diam-Diam (REV3-06)**  
   - Jika kolom Saldo disediakan oleh bank, kontinuitas saldo wajib divalidasi. Mismatch saldo dicatat sebagai audit exception pada `bank_raw_sources.error_message` dan `bank_imports.has_discrepancies = true`.

5. **INVARIANT: Isolasi Multi-Tenant Ketat**  
   - Seluruh entitas (`BankImport`, `BankRawSource`, `BankTransaction`) wajib mengimplementasikan trait `BelongsToTenant`. Akses lintas organisasi diblokir dengan `CrossTenantViolationException` (HTTP 403).

6. **INVARIANT: Status Transaksi Bank Non-Destruktif (REV3-03)**  
   - Transaksi bank yang baru dinormalisasi berstatus awal `unmatched`. Perubahan status menuju `matched` atau `reconciled` hanya boleh dilakukan oleh modul klasifikasi dan rekonsiliasi pada fase berikutnya.

---

## 3. Baseline Rekening Maret 2026 (REV3-13 Golden Dataset)

Untuk menjamin akurasi parsial dan total, parsing dan normalisasi harus merekonsiliasi angka resmi Maret 2026:

| Rekening | Nomor Rekening | Saldo Awal | Total Debet (OUT) | Total Kredit (IN) | Saldo Akhir |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **BRI Giro 308** | `0012-01-000308-30-8` | Rp72.125.396,20 | Rp5.539,00 | Rp9.077.695,00 | Rp81.197.552,20 |
| **BRI Giro 304** | `0012-01-000304-30-4` | Rp469.776.285,00 | Rp124.258.949,00 | Rp101.846.004,00 | Rp447.363.340,00 |
| **BRI Tabungan 538** | `0012-01-001538-53-8` | Rp571.955.656,80 | Rp61.193,00 | Rp109.362.464,00 | Rp681.256.927,80 |

Unit test wajib memverifikasi bahwa kalkulasi parsial dan total dari mutasi yang diimpor menghasilkan nominal persis seperti tabel di atas tanpa selisih 1 sen pun.

---

## 4. Desain Arsitektur & Bounded Context

Mengikuti arsitektur **Modular Monolith**:

```text
backend/app/
├── Domain/
│   └── Bank/
│       ├── Enums/
