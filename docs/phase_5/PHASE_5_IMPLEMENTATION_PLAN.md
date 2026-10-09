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
│       │   ├── BankImportStatus.php           # pending, processing, done, failed
│       │   ├── BankTransactionDirection.php   # IN, OUT
│       │   └── BankTransactionStatus.php      # unmatched, matched, reconciled, excluded
│       ├── Exceptions/
│       │   ├── DuplicateImportException.php
│       │   ├── InvalidBankStatementFormatException.php
│       │   └── UnsupportedBankFormatException.php
│       ├── Models/
│       │   ├── BankAccount.php                # (Existing Phase 4)
│       │   ├── BankImport.php                 # Header batch impor + audit hash
│       │   ├── BankRawSource.php              # Preservasi baris mentah spreadsheet
│       │   └── BankTransaction.php            # Mutasi perbankan ternormalisasi
│       ├── Parsers/
│       │   ├── Contracts/
│       │   │   ├── BankStatementParserInterface.php
│       │   │   └── StatementMappingProfileInterface.php
│       │   ├── Profiles/
│       │   │   ├── BriGiroMappingProfile.php   # Layout kolom BRI Giro
│       │   │   ├── BriTabunganMappingProfile.php # Layout kolom BRI Tabungan
│       │   │   ├── BukuKasMappingProfile.php   # Layout kolom Buku Kas
│       │   │   └── GenericCsvMappingProfile.php # Layout CSV standar
│       │   ├── CsvBankStatementParser.php      # Parser CSV (RFC 4180 + Autodetect)
│       │   ├── XlsxBankStatementParser.php     # Parser XLSX OpenXML native streaming
│       │   └── BankStatementParserFactory.php  # Resolver parser & mapping otomatis
│       └── Services/
│           ├── BankImportService.php           # Orkestrator impor & transaksi DB
│           ├── BankTransactionNormalizer.php   # Transformasi raw row -> DTO ternormalisasi
│           ├── FingerprintGenerator.php        # Algoritma deterministik SHA-256 (REV3-06)
│           └── RunningBalanceValidator.php     # Validasi kontinuitas saldo berjalan
├── Http/
│   └── Controllers/Api/
│       ├── BankImportController.php            # Upload, list, batch details & exception queue
│       └── BankTransactionController.php       # Query mutasi bank & drill-down raw source
```

---

## 5. Rencana Langkah Kerja (Step-by-Step Execution Plan)

### Step 5.1: Database Schema & Migrations
- [ ] **Migration 1:** `add_enhanced_columns_to_bank_imports_table`  
  - Tambahkan kolom pada `bank_imports`:
    - `file_hash` (string 64, SHA-256)
    - `file_path` (string 500, nullable)
    - `mapping_version` (string 50, default `'bri_v1'`)
    - `has_discrepancies` (boolean, default false)
  - Tambahkan index `(organization_id, bank_account_id, file_hash)`
- [ ] **Migration 2:** `create_bank_raw_sources_table`  
  - Buat tabel `bank_raw_sources` dengan kolom:
    - `id`, `organization_id`, `bank_import_id`, `bank_account_id`
    - `source_file` (string 300)
    - `source_sheet` (string 100, nullable)
    - `source_row` (integer)
    - `raw_data` (JSONB / JSON)
    - `row_fingerprint` (string 64)
    - `status` (`pending`, `normalized`, `duplicate`, `error`)
    - `error_code` (string 50, nullable: `INVALID_DATE`, `INVALID_AMOUNT`, `BALANCE_DISCREPANCY`)
    - `error_message` (text, nullable)
    - `timestamps`
  - Index: `(organization_id, bank_import_id)`, `(organization_id, row_fingerprint)`, `(organization_id, status)`
- [ ] **Migration 3:** `add_raw_source_and_sequence_to_bank_transactions_table`  
  - Tambahkan kolom pada `bank_transactions`:
    - `raw_source_id` (nullable FK ke `bank_raw_sources(id)` on delete set null)
    - `row_sequence` (integer default 1)

---

### Step 5.2: Domain Models & Enums
- [ ] Buat Enums:
  - `BankImportStatus`: `PENDING`, `PROCESSING`, `DONE`, `FAILED`
  - `BankTransactionDirection`: `IN`, `OUT`
  - `BankTransactionStatus`: `UNMATCHED`, `MATCHED`, `RECONCILED`, `EXCLUDED`
- [ ] Buat Domain Exceptions:
  - `DuplicateImportException` (HTTP 409)
  - `InvalidBankStatementFormatException` (HTTP 422)
  - `UnsupportedBankFormatException` (HTTP 415)
- [ ] Buat Model `BankRawSource`:
  - Trait `BelongsToTenant`
  - Casts: `raw_data` (array), `source_row` (integer)
  - Relasi ke `BankImport`, `BankAccount`, dan `BankTransaction`
- [ ] Buat Model `BankImport`:
  - Trait `BelongsToTenant`
  - Relasi ke `BankAccount`, `User` (`importedBy`), `rawSources()`, `transactions()`
  - Scope: filter per periode, per rekening, per status
- [ ] Buat Model `BankTransaction`:
  - Trait `BelongsToTenant`
  - Relasi ke `BankAccount`, `BankImport`, `BankRawSource`, `Counterparty`
  - Casts: `amount` (string decimal), `balance_after` (string decimal), `transaction_date` (date), `value_date` (date)
  - Accessor: `is_credit` (IN), `is_debit` (OUT)

---

### Step 5.3: Parsing Engine & Extensible Mapping Profiles
- [ ] Interface:
  - `BankStatementParserInterface`: contract `parse(string $filePath, array $options = []): array`
  - `StatementMappingProfileInterface`: definisi indeks/nama kolom tanggal, keterangan, debet, kredit, saldo, referensi.
- [ ] Mapping Profiles:
  - `BriGiroMappingProfile`: kolom Tanggal, Keterangan, Debet, Kredit, Saldo
  - `BriTabunganMappingProfile`: kolom No, Tanggal, Transaksi/Uraian, Debet, Kredit, Saldo
  - `BukuKasMappingProfile`: kolom Tanggal, No Bukti, Uraian, Penerimaan (Kredit), Pengeluaran (Debet), Saldo
  - `GenericCsvMappingProfile`: format standar industri
- [ ] `CsvBankStatementParser`:
  - Deteksi delimiter otomatis (koma `,`, titik koma `;`, tab `\t`).
  - Pembersihan BOM UTF-8 dan karakter non-ASCII tersembunyi.
  - Deteksi baris header secara dinamis.
- [ ] `XlsxBankStatementParser`:
  - Parser OpenXML streaming berbasis PHP native `ZipArchive` dan `SimpleXML` (zero 3rd-party bloat).
  - Ekstraksi `sharedStrings.xml` dan lembar kerja XML.
  - Multi-sheet routing: jika parameter `sheet_name` kosong, deteksi otomatis berdasarkan kecocokan nama/nomor rekening (`308`, `304`, `538`, `Kas`), atau fallback ke sheet pertama.
  - Konversi serial date Excel (`46082` $\rightarrow$ `2026-03-01`) dengan koreksi *Excel 1900 leap year bug*.
- [ ] `BankStatementParserFactory`:
  - Deteksi ekstensi file (`.csv`, `.xlsx`, `.xls`) dan pemetaan otomatis ke parser yang sesuai.

---

### Step 5.4: Normalization, Fingerprinting & Validation Services
- [ ] `FingerprintGenerator` (REV3-06):
  - Mengimplementasikan rumus deterministik SHA-256:
    ```php
    sha256(implode('|', [
        $orgId,
        $bankAccountId,
        $normalizedDate, // Y-m-d
        $rowSequence,    // 1, 2, ... kejadian berurutan pada tanggal yang sama
        $direction,      // IN atau OUT
        number_format($amount, 2, '.', ''),
        $normalizedDescription
    ]))
    ```
- [ ] `BankTransactionNormalizer`:
  - Normalisasi Tanggal: menangani format Indonesia (`dd/mm/yyyy`, `dd-mm-yyyy`, `d Bulan yyyy`) serta serial date.
  - Normalisasi Nominal: pembersihan simbol `Rp`, spasi, titik ribuan vs koma desimal, tanda kurung negatif, konversi eksklusif menggunakan string/bcmath (tanpa konversi float).
  - Normalisasi Teks Keterangan: uppercase, hapus spasi ganda, trim.
  - Resolusi Arah Arus Kas: Kredit $\rightarrow$ `IN`, Debet $\rightarrow$ `OUT`.
  - Integrasi `CounterpartyService::resolve()` untuk memetakan deskripsi ke entitas rekanan.
- [ ] `RunningBalanceValidator` (REV3-06):
  - Memverifikasi kontinuitas saldo jika kolom saldo tersedia:
    $$\text{balance}_{t} = \text{balance}_{t-1} + \text{credit}_{t} - \text{debit}_{t}$$
  - Menandai mismatch sebagai audit exception tanpa mengubah data mentah secara diam-diam.
- [ ] `BankImportService`:
  - Orkestrator impor menyeluruh yang aman:
    1. Validasi MIME dan ukuran file (maksimal 10MB).
    2. Hitung SHA-256 `file_hash`.
    3. Cek duplikasi file pada rekening yang sama $\implies$ lempar `DuplicateImportException`.
    4. Simpan file ke storage lokal terproteksi (`bank_imports/{tenant_id}/{year}/{hash}.ext`).
    5. Buat entitas `BankImport` dengan status `processing`.
    6. Parse baris mentah dan simpan ke `bank_raw_sources`.
    7. Normalisasi setiap baris, generate fingerprint dengan penomoran sequence.
    8. Cek fingerprint pada database:
       - Jika duplikat: tandai `raw_source` status `duplicate`, `skipped_rows++`.
       - Jika valid baru: buat `bank_transactions`, tautkan `raw_source_id`, `imported_rows++`.
    9. Jalankan `RunningBalanceValidator` untuk mendeteksi anomali saldo.
    10. Hitung rentang tanggal mutasi (`period_start`, `period_end`).
    11. Finalisasi status `BankImport` menjadi `done` dan catat audit log.

---

### Step 5.5: HTTP API Controllers & Routes
- [ ] `BankImportController`:
  - `POST /api/v1/bank-imports`: Upload file (multipart/form-data: `file`, `bank_account_id`, opsional: `sheet_name`, `mapping_version`).
  - `GET /api/v1/bank-imports`: List seluruh batch impor dengan pagination & filtering.
  - `GET /api/v1/bank-imports/{id}`: Detail batch impor, metrik (`imported`, `skipped`, `error`), dan ringkasan transaksi.
  - `GET /api/v1/bank-imports/{id}/raw-sources`: Audit trail baris mentah dari file asli.
  - `GET /api/v1/bank-imports/{id}/exceptions`: Antrean baris error/duplikat/anomali saldo untuk verifikasi operator (*Exception Queue*).
- [ ] `BankTransactionController`:
  - `GET /api/v1/bank-transactions`: List mutasi bank ternormalisasi dengan filter:
    - `bank_account_id`
    - `date_from`, `date_to`
    - `direction` (`IN`, `OUT`)
    - `status` (`unmatched`, `matched`, `reconciled`, `excluded`)
    - `counterparty_id`
    - `search` (keterangan atau nomor referensi)
  - `GET /api/v1/bank-transactions/{id}`: Detail mutasi lengkap dengan relasi `rawSource`, `bankAccount`, dan `counterparty`.
- [ ] Daftarkan seluruh endpoint di `backend/routes/api.php` di bawah middleware `auth:sanctum` dan `tenant.require`.
- [ ] Daftarkan exception renderers (`DuplicateImportException`, `InvalidBankStatementFormatException`) di `backend/bootstrap/app.php`.

---

### Step 5.6: Automated Testing Suite (`BankImportTest.php`)
Membangun test suite komprehensif mencakup 11 skenario kritis:
- [ ] **Test 1: CSV Statement Ingestion (Normal Flow)**  
  Menguji parsing file CSV (koma & titik koma), memverifikasi seluruh baris terimpor dengan arah kas yang tepat.
- [ ] **Test 2: XLSX Statement Ingestion (Native OpenXML Streaming)**  
  Menguji parser OpenXML native pada spreadsheet Excel, mengonversi serial date dan sheet selection dengan presisi.
- [ ] **Test 3: Bank Semantics Invariant (Bank Credit ≠ Accounting Credit)**  
  Memverifikasi mutasi Kredit bank menghasilkan `direction = IN` dan nominal positif.
- [ ] **Test 4: Idempotency & Zero Financial Duplication (Phase Gate Core)**  
  Mengunggah ulang file yang sama persis:
  - Request kedua menolak duplikasi file (`DuplicateImportException`).
  - Mengunggah file baru yang memuat 3 baris lama dan 2 baris baru: sistem mengimpor 2 baris baru dan melewati 3 baris lama (`skipped_rows = 3`, `imported_rows = 2`), dengan total record transaksi perbankan tetap akurat.
- [ ] **Test 5: Same-Day Multiple Identical Transactions Disambiguation**  
  Memverifikasi bahwa dua transaksi terpisah pada hari yang sama dengan nominal dan keterangan yang identik (misal 2x biaya admin Rp 2.500) **tidak hilang**, melainkan keduanya berhasil diimpor dengan `row_sequence` berbeda.
- [ ] **Test 6: Raw Source Preservation & Drill-Down Audit**  
  Memverifikasi bahwa setiap baris `BankTransaction` terhubung ke `BankRawSource` yang menyimpan `source_file`, `source_sheet`, `source_row`, dan JSON asli.
- [ ] **Test 7: Running Balance Discrepancy Detection (REV3-06)**  
  Memverifikasi bahwa jika terdapat lonjakan saldo atau ketidakcocokan saldo berjalan pada baris mutasi bank, sistem mencatat exception pada antrean audit tanpa melakukan silent override.
- [ ] **Test 8: Counterparty Auto-Resolution Integration**  
  Memverifikasi bahwa baris dengan deskripsi *"Bunga Rekening (Bank)"* otomatis terhubung ke `BANK-BRI`, dan baris *"SETORAN MI DARWATA"* otomatis terhubung ke `MI-DARWATA-01`.
- [ ] **Test 9: Golden Dataset Baseline Reconciliation (REV3-13)**  
  Memverifikasi bahwa impor mutasi Maret 2026 menghasilkan total Debet dan Kredit yang persis sama dengan dokumen spesifikasi:
  - BRI Giro 308: Debet Rp5.539,00, Kredit Rp9.077.695,00
  - BRI Giro 304: Debet Rp124.258.949,00, Kredit Rp101.846.004,00
  - BRI Tabungan 538: Debet Rp61.193,00, Kredit Rp109.362.464,00
- [ ] **Test 10: Multi-Tenant Isolation**  
  Memverifikasi bahwa file yang diimpor pada Tenant A tidak dapat diakses atau dilihat oleh Admin Tenant B.
- [ ] **Test 11: Error Resilience & Anomaly Isolation**  
  Baris korup (misal: kolom nominal bukan angka) dicatat pada `error_rows` dan `bank_raw_sources.error_message` tanpa membatalkan baris-baris mutasi yang valid.

---

### Step 5.7: Review & Phase Gate Verification
- [ ] Jalankan seluruh suite test (`php artisan test`) memastikan 100% lulus (target $\ge 83$ test cases passing).
- [ ] Susun dokumen rilis `docs/phase_5/PHASE_5_RESULT.md`.
- [ ] Evaluasi kriteria Phase Gate dan laporkan kepada pengguna.

---

## 6. Kriteria Phase Gate (Pass / Fail)

Phase 5 dinyatakan **PASS** apabila:
1. **Duplicate Import = Zero Duplicate Financial Transactions**: Unggah ulang file mutasi atau rekaman yang sama sama sekali tidak menghasilkan duplikasi record di `bank_transactions`.
2. **Bank Semantics Respected**: Mutasi Kredit bank selalu ternormalisasi menjadi `direction = IN`, dan Debet bank menjadi `direction = OUT`.
3. **Raw Preservation Guaranteed**: Setiap mutasi perbankan dapat ditelusuri kembali (*drill-down*) hingga ke file, sheet, dan nomor baris sumber aslinya.
4. **Running Balance Verification Enforced**: Saldo berjalan divalidasi dan setiap selisih dicatat sebagai audit exception.
5. **Counterparty Resolution Working**: Sistem mengaitkan transaksi bank dengan entitas counterparty yang sesuai berdasarkan aturan Phase 4.
6. **Golden Dataset Baseline Reconciled**: Total nominal Debet/Kredit Maret 2026 terbukti klop tanpa selisih 1 sen pun.
7. **Multi-Tenant Scoped**: Seluruh data mutasi dan batch impor terisolasi per organisasi.
8. **100% Test Suite Pass**: Seluruh test berjalan hijau tanpa kegagalan atau regresi pada modul akuntansi sebelumnya.
