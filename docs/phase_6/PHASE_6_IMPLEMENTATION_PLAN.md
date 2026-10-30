# IMPLEMENTATION PLAN: PHASE 6 — TRANSACTION CLASSIFICATION & RULES ENGINE (REVISED & HARDENED)
**Sistem Keuangan LP Ma'arif NU PCNU Cilacap**  
**Status Baseline:** Phase 5 (Bank Ingestion & Import Engine) SELESAI & LULUS GATE (83 Tests Passing, 286 Assertions)  
**Target:** Phase 6 — Deterministic Classification Rules Engine, Legacy Code Mapping, AI Suggestion Interface, Transfer Pairing, Closed Period Protection, Classification Queue & Business Transaction Foundation  
**Specification of Record:** `Spesifikasi_Teknis_Sistem_Keuangan_LP_Maarif_REV3_Production_Ready.docx` (REV3-02, REV3-07, REV3-10, REV3-11, REV3-13, REV3-14, REV3-22, REV3-24)  
**Golden Dataset:** Workbook Rekening Koran & Mutasi Maret 2026 (`3. MARET 2026.xlsx`)  

---

## 1. Ringkasan Eksekutif & Tujuan Phase 6

Phase 6 membangun subsistem **Transaction Classification & Rules Engine** yang bertindak sebagai otak penentu klasifikasi finansial. Modul ini menjembatani mutasi perbankan mentah yang telah dinormalisasi (`BankTransaction` dari Phase 5) menuju entitas transaksi bisnis resmi (`BusinessTransaction`) sebelum diproses oleh mesin jurnal akuntansi berpasangan ganda (*double-entry journal posting* pada Phase 7).

Sesuai hierarki *Source-of-Truth* Spesifikasi Teknis Rev. 3 dan *Transaction Rulebook* REV3-24:
```text
RAW SOURCE (Excel / File Rekening Koran Mentah)
    ↓
RAW PRESERVATION (bank_raw_sources: File, Sheet, Row, JSON Mentah)
    ↓
NORMALIZATION & FINGERPRINTING (bank_transactions: Direction IN/OUT, Row Sequence)
    ↓
CLASSIFICATION & RULES ENGINE (Phase 6 - Precedence 1-7)
    ├── 1. Explicit Approved User Mapping       ──→ AUTO-POST ELIGIBLE
    ├── 2. Exact Invoice / Reference Match     ──→ AUTO-POST ELIGIBLE
    ├── 3. Approved Legacy Code Mapping        ──→ AUTO-POST ELIGIBLE
    ├── 4. Deterministic Category/Text Rules   ──→ AUTO-POST ELIGIBLE
    ├── 5. Counterparty + Historical Pattern    ──→ SUGGESTION ONLY (NO AUTO-POST)
    ├── 6. AI / Fuzzy Suggestion Engine         ──→ SUGGESTION ONLY (NEVER AUTO-POST)
    └── 7. Unclassified Fallback                ──→ EXCEPTION / REVIEW QUEUE
    ↓
BUSINESS TRANSACTION (business_transactions: Entity Bridge & Status Tracking)
    ↓
JOURNAL POSTING ENGINE (Phase 7 - Double-Entry Ledger)
```

Modul ini **bukan sekadar penanda kategori teks biasa**, melainkan benteng pertahanan akuntansi cerdas dengan pengamanan komprehensif:
1. **Penegakan Hierarki Presedensi 7 Tingkat (REV3-07)**:
   Evaluasi klasifikasi dijalankan secara deterministik dari prioritas 1 hingga 7. Evaluasi langsung berhenti pada prioritas deterministik tertinggi pertama yang cocok (*first matching priority*).
2. **Pagar Pengaman Kecerdasan Buatan (*AI Suggestion Safety Invariant - REV3-10*)**:
   Mesin AI/Fuzzy hanya boleh menghasilkan rekomendasi terstruktur (`confidence`, `evidence`, `suggested_type`, `suggested_account`, `explanation`). **AI mutlak dilarang melakukan direct-posting atau auto-posting.**
3. **Pematuhan Semantik Arah Arus Kas (*Bank Semantics Invariant - REV3-02, REV3-22*)**:
   - `Bank CREDIT (IN)` = Kas Masuk $\implies$ kandidat: `RECEIVABLE_PAYMENT`, `DIRECT_INCOME`, `BANK_INTEREST`, `BANK_TRANSFER (Dest)`. Dilarang dikaitkan dengan akun Beban (5xxx).
   - `Bank DEBIT (OUT)` = Kas Keluar $\implies$ kandidat: `BANK_FEE_TAX`, `OPERATING_EXPENSE`, `ALLOCATION_DISBURSEMENT`, `BANK_TRANSFER (Source)`. Dilarang dikaitkan dengan akun Pendapatan (4xxx).
4. **Deteksi & Pemasangan Transfer Antar-Rekening (*Bank Transfer 2-Leg Pairing - Rulebook R07*)**:
   Mendeteksi pemindahan dana internal antar 3 rekening bank LP Ma'arif (Giro 308, Giro 304, Tabungan 538) sehingga leg OUT dan leg IN dipasangkan (*paired*), mencegah duplikasi pencatatan kas atau distorsi laba rugi.
5. **Perlindungan Periode Fiskal Tertutup (*Closed Period Guard - Rulebook R11*)**:
   Transaksi yang tanggalnya berada dalam periode fiskal berstatus `CLOSED` atau `SOFT_CLOSE` diblokir dari kelayakan auto-post dan ditandai sebagai pengecualian periode.
6. **Perlindungan Pembayaran Berlebih (*Overpayment & Unapplied Cash - Rulebook R05*)**:
   Pelunasan piutang yang melebihi tagihan tidak boleh secara diam-diam dijadikan pendapatan, melainkan ditandai sebagai `UNAPPLIED_CASH` atau meminta peninjauan manual.
7. **Verifikasi Golden Gate Kasus Nyata (REV3-13, REV3-24)**:
   - Kasus **MI Darwata Sindangbarang**: Kredit Rp852.000 $\implies$ `direction = IN`, `role = PAYER`, terklasifikasi sebagai `RECEIVABLE_PAYMENT` / `DIRECT_INCOME`.
   - Kasus **Bank BRI**: Kredit Bunga $\implies$ `BANK_INTEREST` (`4116`), Debet Pajak/Admin $\implies$ `BANK_FEE_TAX` (`5116`), counterparty = `Bank BRI`.
8. **Isolasi Multi-Tenant Mutlak**:
   Seluruh aturan klasifikasi, saran AI, dan entitas transaksi bisnis diisolasi secara ketat per `organization_id`.

---

## 2. Invariants & Aturan Bisnis Kritis Phase 6

1. **INVARIANT: Hierarki Presedensi 7 Tingkat Tidak Boleh Dilanggar (REV3-07)**  
   Urutan evaluasi kaku:
   - Priority 1: Explicit User Mapping $\implies$ Auto-Post: YES
   - Priority 2: Exact Invoice / Reference Match $\implies$ Auto-Post: YES
   - Priority 3: Approved Legacy Code Mapping $\implies$ Auto-Post: YES
   - Priority 4: Deterministic Category/Text Rules $\implies$ Auto-Post: YES
   - Priority 5: Counterparty + Historical Pattern $\implies$ Suggestion Only: NO AUTO-POST
   - Priority 6: AI / Fuzzy Suggestion Engine $\implies$ Suggestion Only: NEVER AUTO-POST
   - Priority 7: Unclassified Fallback $\implies$ Review Queue: NO AUTO-POST

2. **INVARIANT: Pagar Pengaman AI & Heuristik (REV3-10, Rulebook R13)**  
   Hasil dari AI dan heuristik (P5 & P6) hanya menghasilkan status `SUGGESTED` dengan skor kepercayaan (*confidence score* 0.00 – 1.00) dan teks bukti (*evidence string*). Hanya manusia (Accounting Operator/Admin) yang dapat mengonfirmasi saran menjadi transaksi valid.

3. **INVARIANT: Konsistensi Arah Arus Kas Terhadap Akun (Rulebook R01 & R02)**  
   - Mutasi `IN` (Kredit Bank) dilarang dikaitkan dengan akun berkepala 5xxx (Beban) sebagai penambah beban.
   - Mutasi `OUT` (Debet Bank) dilarang dikaitkan dengan akun berkepala 4xxx (Pendapatan) sebagai penambah pendapatan.
   Pelanggaran melempar `InvalidClassificationDirectionException`.

4. **INVARIANT: Deteksi Transfer Internal P&L-Neutral (Rulebook R07)**  
   Transaksi antar-rekening internal LP Ma'arif tidak boleh diakui sebagai beban atau pendapatan. Sistem mendeteksi nomor rekening internal dan kata kunci transfer untuk menandai tipe transaksi sebagai `BANK_TRANSFER`.

5. **INVARIANT: Proteksi Immutabilitas Pasca-Posting (Rulebook R09)**  
   Jika sebuah `BusinessTransaction` telah berstatus `POSTED` (sudah menghasilkan jurnal akuntansi pada Phase 7), pengklasifikasian ulang dilarang keras dan memicu `ImmutableClassifiedTransactionException` (HTTP 409). Koreksi wajib melalui alur jurnal pembalik (*reversal*).

6. **INVARIANT: Proteksi Periode Fiskal Tertutup (Rulebook R11)**  
   Transaksi dengan tanggal mutasi pada periode fiskal `CLOSED` tidak boleh diposting otomatis. Transaksi dialirkan ke antrean peninjauan dengan kode `PERIOD_LOCKED`.

7. **INVARIANT: Proteksi Pembayaran Berlebih (Rulebook R05, REV3-11)**  
   Pembayaran piutang yang melebihi nilai saldo piutang outstanding tidak boleh diakui otomatis sebagai pendapatan. Selisih lebih wajib dicatat sebagai `UNAPPLIED_CASH`.

8. **INVARIANT: Isolasi Multi-Tenant Ketat (Tenant Boundary Isolation)**  
   Setiap tabel (`classification_rules`, `business_transactions`, `classification_suggestions`) wajib memiliki kolom `organization_id` yang *NOT NULL*, menerapkan relasi `BelongsToTenant`, dan terproteksi dari kebocoran data antar-organisasi.

---

## 3. Matriks Taksonomi Transaksi Bisnis (Transaction Taxonomy Matrix)

Berdasarkan *Transaction Rulebook* REV3-24, 8 tipe transaksi bisnis resmi yang dikelola adalah:

| No | Tipe Transaksi (`transaction_type`) | Arah Bank | Indikator Utama | Akun Debit Standar | Akun Kredit Standar | Status Auto-Post |
| :--- | :--- | :---: | :--- | :--- | :--- | :---: |
| 1 | `RECEIVABLE_PAYMENT` | `IN` | Cocok dengan Piutang/Madrasah | 110x (Kas/Bank) | 131x (Piutang) | ✅ Eligible |
| 2 | `DIRECT_INCOME` | `IN` | Pendapatan langsung tanpa piutang | 110x (Kas/Bank) | 41xx (Pendapatan) | ✅ Eligible |
| 3 | `BANK_INTEREST` | `IN` | Counterparty = BANK (Bunga) | 110x (Kas/Bank) | 4116 (Pendapatan Bunga) | ✅ Eligible |
| 4 | `BANK_FEE_TAX` | `OUT` | Counterparty = BANK (Admin/Pajak) | 5116 (Beban Admin/Pajak) | 110x (Kas/Bank) | ✅ Eligible |
| 5 | `OPERATING_EXPENSE` | `OUT` | Beban operasional umum/kantor | 5xxx (Beban Operasional) | 110x (Kas/Bank) | ✅ Eligible |
| 6 | `ALLOCATION_DISBURSEMENT` | `OUT` | Terkait Program/Fund (21xx/22xx) | 21xx/22xx (Alokasi Dana) | 110x (Kas/Bank) | ✅ Eligible |
| 7 | `BANK_TRANSFER` | `IN` / `OUT` | Mutasi antar-rekening internal LP | 110x (Bank Tujuan) | 110x (Bank Asal) | ✅ Eligible |
| 8 | `CASH_TRANSACTION` | `IN` / `OUT` | Mutasi Buku Kas Tunai (1101) | Sesuai Uraian | Sesuai Uraian | ✅ Eligible |

---

## 4. Desain Arsitektur & Bounded Context (Domain/Classification)

```text
backend/app/
├── Domain/
│   ├── Classification/
│   │   ├── Contracts/
│   │   │   ├── ClassificationRuleEngineInterface.php
│   │   │   ├── AiSuggestionInterface.php
│   │   │   └── LegacyCodeResolverInterface.php
│   │   ├── DTOs/
│   │   │   ├── ClassificationMatchResult.php
│   │   │   └── AiSuggestionResult.php
│   │   ├── Enums/
│   │   │   ├── ClassificationMethod.php       # EXPLICIT_MAPPING, INVOICE_REFERENCE, LEGACY_CODE, DETERMINISTIC_RULE, HISTORICAL_RULE, AI_SUGGESTION, MANUAL
│   │   │   ├── ClassificationStatus.php       # UNCLASSIFIED, SUGGESTED, CLASSIFIED, REVIEWED, APPROVED, REJECTED
│   │   │   ├── ClassificationRuleType.php     # DETERMINISTIC, KEYWORD, REGEX, EXACT_REFERENCE, COUNTERPARTY
│   │   │   └── BusinessTransactionType.php    # RECEIVABLE_PAYMENT, DIRECT_INCOME, BANK_INTEREST, BANK_FEE_TAX, OPERATING_EXPENSE, ALLOCATION_DISBURSEMENT, BANK_TRANSFER, CASH_TRANSACTION
│   │   ├── Exceptions/
│   │   │   ├── InvalidClassificationDirectionException.php
│   │   │   ├── ConflictingRuleException.php
│   │   │   └── ImmutableClassifiedTransactionException.php
│   │   ├── Matchers/
│   │   │   ├── ExplicitMappingMatcher.php      # Priority 1
│   │   │   ├── InvoiceReferenceMatcher.php     # Priority 2
│   │   │   ├── LegacyCodeMatcher.php           # Priority 3
│   │   │   ├── DeterministicRuleMatcher.php    # Priority 4
│   │   │   └── HistoricalHeuristicMatcher.php  # Priority 5
│   │   ├── Models/
│   │   │   ├── BusinessTransaction.php         # Model transaksi bisnis perantara ke jurnal
│   │   │   ├── ClassificationRule.php          # Master aturan klasifikasi deklaratif
│   │   │   ├── ClassificationSuggestion.php    # Wadah saran klasifikasi AI/Heuristic
│   │   │   └── TransactionCategory.php         # (Existing Phase 4)
│   │   ├── Providers/
│   │   │   ├── HeuristicAiSuggestionProvider.php # Provider lokal/offline deterministik untuk test/CI
│   │   │   └── GeminiAiSuggestionProvider.php   # Provider produksi terhubung LLM eksternal
│   │   └── Services/
│   │       ├── ClassificationRuleService.php
│   │       ├── TransactionClassificationService.php # Master Pipeline Orchestrator (Batch & Single)
│   │       ├── TransferDetectionService.php    # Deteksi & Pemasangan 2-Leg Transfer Bank
│   │       ├── AiSuggestionService.php          # Wrapper & Resolver Provider AI
│   │       └── TransactionCategoryService.php   # (Existing Phase 4)
│   └── Bank/
│       └── Models/BankTransaction.php           # (Sinkronisasi status klasifikasi & relasi ke BusinessTransaction)
├── Http/
│   ├── Controllers/Api/
│   │   ├── ClassificationRuleController.php
│   │   ├── TransactionClassificationController.php
│   │   └── BusinessTransactionController.php
│   └── Requests/
│       ├── StoreClassificationRuleRequest.php
│       ├── ApplyClassificationSuggestionRequest.php
│       └── ManualClassifyTransactionRequest.php
```

---

## 5. Rencana Langkah Kerja Eksekusi (Step-by-Step Execution Plan)

### Step 6.1: Database Schema & Migrations
1. **Migration 1:** `create_classification_rules_table`:
   - `id`, `organization_id` (FK), `name`, `priority` (integer default 100), `is_active` (boolean default true).
   - `rule_type` (`KEYWORD`, `REGEX`, `EXACT_REFERENCE`, `COUNTERPARTY`).
   - `direction` (nullable string: `IN`, `OUT`, `TRANSFER`).
   - `bank_account_id` (nullable FK ke `bank_accounts`).
   - `pattern` (string pencocokan teks), `pattern_type` (`contains`, `exact`, `regex`, `starts_with`, `ends_with`).
   - `min_amount`, `max_amount` (nullable `DECIMAL(18,2)`).
   - `transaction_type` (string).
   - `transaction_category_id` (nullable FK ke `transaction_categories`).
   - `target_account_id` (nullable FK ke `accounts`).
   - `counterparty_id` (nullable FK ke `counterparties`).
   - `fund_id` (nullable FK ke `funds`).
   - `auto_post` (boolean default true).
   - `confidence` (decimal 4,2 default 1.00).
   - `description` (text nullable).
   - Index: `(organization_id, is_active, priority)`, `(organization_id, direction)`.

2. **Migration 2:** `create_business_transactions_table`:
   - `id`, `organization_id` (FK), `transaction_number` (string unik per org, misal `BT-2026-00001`).
   - `bank_transaction_id` (nullable FK ke `bank_transactions`).
   - `fiscal_period_id` (FK ke `fiscal_periods`).
   - `transaction_date` (date), `value_date` (date nullable).
   - `transaction_type` (string), `direction` (string: `IN`, `OUT`, `TRANSFER`).
   - `amount` (`DECIMAL(18,2)`), `counterparty_id` (nullable FK).
   - `target_account_id` (nullable FK ke `accounts`).
   - `transaction_category_id` (nullable FK ke `transaction_categories`).
   - `fund_id` (nullable FK ke `funds`).
   - `paired_business_transaction_id` (nullable FK untuk 2-leg transfer).
   - `classification_status` (`unclassified`, `suggested`, `classified`, `reviewed`, `approved`, `rejected`).
   - `classification_method` (nullable string), `classification_confidence` (nullable decimal 4,2).
   - `is_period_locked` (boolean default false).
   - `description` (text), `notes` (text nullable), `status` (string default `'draft'`: `draft`, `approved`, `posted`, `rejected`).
   - Unique: `(organization_id, transaction_number)`.
   - Index: `(organization_id, bank_transaction_id)`, `(organization_id, classification_status)`.

3. **Migration 3:** `create_classification_suggestions_table`:
   - `id`, `organization_id` (FK), `bank_transaction_id` (FK), `business_transaction_id` (nullable FK).
   - `suggested_type` (string), `suggested_account_id` (nullable FK ke `accounts`).
   - `suggested_category_id` (nullable FK ke `transaction_categories`).
   - `suggested_counterparty_id` (nullable FK ke `counterparties`).
   - `confidence` (`DECIMAL(4,2)`), `evidence` (text), `explanation` (text), `metadata` (json nullable).
   - `status` (string default `'pending'`: `pending`, `accepted`, `rejected`).
   - `reviewed_by` (nullable FK ke `users`), `reviewed_at` (timestamp nullable).
   - Index: `(organization_id, bank_transaction_id)`, `(organization_id, status)`.

4. **Migration 4:** `add_classification_status_and_links_to_existing_tables`:
   - Menambahkan kolom `classification_status` (string default `'unclassified'`) dan `business_transaction_id` (nullable FK ke `business_transactions`) pada tabel `bank_transactions`.
   - Menambahkan nullable FK `business_transaction_id` pada tabel `journal_entries` sesuai `ERD_REVISED.md`.

### Step 6.2: Domain Models, Enums, DTOs & Contracts
1. **Enums:**
   - `ClassificationMethod`
   - `ClassificationStatus`
   - `ClassificationRuleType`
   - `BusinessTransactionType`
2. **DTOs:**
   - `ClassificationMatchResult`: Objek immutable hasil evaluasi (`isMatched`, `priority`, `method`, `confidence`, `type`, `accountId`, `categoryId`, `counterpartyId`, `fundId`, `autoPost`, `evidence`, `explanation`).
   - `AiSuggestionResult`: Objek saran AI dengan evidence, reasoning, dan alternatif akun.
3. **Contracts:**
   - `ClassificationRuleEngineInterface`: Kontrak evaluasi transaksi bank.
   - `AiSuggestionInterface`: Kontrak pembuatan saran cerdas.
   - `LegacyCodeResolverInterface`: Kontrak resolusi kode akun warisan.
4. **Exceptions:**
   - `InvalidClassificationDirectionException` (HTTP 422: Arah mutasi bertentangan dengan saldo normal/akun).
   - `ImmutableClassifiedTransactionException` (HTTP 409: Penolakan ubah transaksi posted).
5. **Models:**
   - `ClassificationRule`: Eloquent model dengan tenant scoping, helper evaluasi pola teks/nominal.
   - `BusinessTransaction`: Model perantara transaksi bisnis dengan auto-numbering `BT-YYYY-XXXXX`, proteksi immutabilitas, dan relasi ke `BankTransaction` serta `ClassificationSuggestion`.
   - `ClassificationSuggestion`: Model wadah penampung saran AI/heuristik dengan pelacakan status review.

### Step 6.3: Rules Matchers & Engine Services
1. **`ExplicitMappingMatcher` (Priority 1 - Auto-Post: YES)**:
   Mengecek pemetaan manual eksplisit pengguna yang tersimpan sebelumnya untuk pola mutasi tertentu.
2. **`InvoiceReferenceMatcher` (Priority 2 - Auto-Post: YES)**:
   Mengekstrak nomor referensi/tagihan madrasah dari uraian transaksi dan mencocokkannya ke piutang outstanding (`receivables`).
   - Jika nilai pembayaran tepat $\implies$ `RECEIVABLE_PAYMENT`.
   - Jika pembayaran berlebih $\implies$ pisahkan kelebihan sebagai `UNAPPLIED_CASH` (Invarian R05).
3. **`LegacyCodeMatcher` (Priority 3 - Auto-Post: YES)**:
   Mengekstrak kode warisan (misal `1314`, `4116`, `5116`) dari deskripsi transaksi atau kolom legacy, lalu menyelaraskannya melalui `AccountMapping::where('mapping_type', 'legacy_code')`.
4. **`DeterministicRuleMatcher` (Priority 4 - Auto-Post: YES)**:
   Mengevaluasi tabel `classification_rules` berdasarkan prioritas terendah lebih dulu (`ORDER BY priority ASC`), arah kas, limit nominal, dan pencocokan teks (`contains`, `regex`, `starts_with`).
5. **`HistoricalHeuristicMatcher` (Priority 5 - Suggestion Only: NO AUTO-POST)**:
   Menganalisis riwayat transaksi bank berulang pada rekanan yang sama untuk menghasilkan rekomendasi berstatus `SUGGESTED` (confidence 0.70 – 0.85).
6. **`AiSuggestionService` & Providers (Priority 6 - Suggestion Only: NEVER AUTO-POST)**:
   - `HeuristicAiSuggestionProvider`: Provider deterministik lokal untuk lingkungan test/CI tanpa ketergantungan API eksternal.
   - `GeminiAiSuggestionProvider`: Provider produksi untuk model LLM.
   - Keduanya wajib menghasilkan rekomendasi berstatus `SUGGESTED` dengan *evidence reasoning*, **mutlak tidak pernah auto-post**.
7. **`TransferDetectionService` (Rulebook R07)**:
   Mendeteksi mutasi internal antar 3 rekening bank LP Ma'arif (`Giro 308`, `Giro 304`, `Tabungan 538`) berdasarkan kata kunci transfer dan nomor rekening tujuan/asal, lalu memasangkan leg OUT dan leg IN.
8. **Unclassified Fallback (Priority 7 - Review Queue)**:
   Menandai transaksi sebagai `UNCLASSIFIED` dengan alasan diagnostik transparan untuk antrean peninjauan operator.

### Step 6.4: Master Pipeline Orchestrator (`TransactionClassificationService`)
1. Menerima mutasi `BankTransaction` (single atau batch).
2. Melakukan *preloading* data pendukung (aturan aktif, rekanan, pemetaan kode, periode fiskal) untuk mencegah masalah N+1 Query pada impor massal.
3. Menjalankan alur pencocokan berurutan dari Priority 1 hingga Priority 7.
4. Mengecek status periode fiskal: jika periode berstatus `CLOSED`, transaksi ditandai `is_period_locked = true` dan dialirkan ke antrean peninjauan (*no auto-post*).
5. Membuat/memperbarui entitas `BusinessTransaction` dan `ClassificationSuggestion`.
6. Sinkronisasi status `classification_status` pada baris `BankTransaction`.
7. Mencatat log ke `AuditService`.

### Step 6.5: HTTP API Layer
1. `ClassificationRuleController`:
   - `GET /api/v1/classification-rules` (List aturan aktif terurut prioritas).
   - `POST /api/v1/classification-rules` (Tambah aturan baru).
   - `GET /api/v1/classification-rules/{id}` (Detail aturan).
   - `PUT /api/v1/classification-rules/{id}` (Update aturan).
   - `DELETE /api/v1/classification-rules/{id}` (Hapus aturan).
2. `TransactionClassificationController`:
   - `POST /api/v1/transactions/classify-batch` (Trigger klasifikasi massal untuk batch import bank).
   - `POST /api/v1/transactions/{id}/classify` (Klasifikasi ulang transaksi tunggal).
   - `GET /api/v1/transactions/unclassified` (Antrean transaksi belum terklasifikasi).
   - `GET /api/v1/transactions/suggestions` (Antrean saran AI/heuristik yang butuh persetujuan).
   - `POST /api/v1/transactions/{id}/apply-suggestion` (Menerima rekomendasi saran).
   - `POST /api/v1/transactions/{id}/reject-suggestion` (Menolak rekomendasi saran).
   - `POST /api/v1/transactions/{id}/manual-classify` (Klasifikasi manual dengan opsi `save_as_rule`).
3. `BusinessTransactionController`:
   - `GET /api/v1/business-transactions` (Daftar transaksi bisnis ternormalisasi).
   - `GET /api/v1/business-transactions/{id}` (Detail transaksi bisnis beserta rujukan mutasi bank).

### Step 6.6: Automated Test Suite (15 Comprehensive Scenarios)
Pengujian mendalam melalui `backend/tests/Feature/ClassificationTest.php`:
- **Test 1:** Evaluasi hierarki 7 presedensi berjalan kaku sesuai urutan prioritas (P1 $\rightarrow$ P7).
- **Test 2:** Pencocokan Pemetaan Eksplisit Pengguna (P1) menghasilkan auto-post eligible.
- **Test 3:** Pencocokan Nomor Invoice/Referensi (P2) mencocokkan piutang madrasah secara tepat.
- **Test 4:** Pencocokan Kode Warisan (P3) mengekstrak kode legacy (misal `1314`) dan mengarahkan ke akun kanonikal.
- **Test 5:** Aturan Kategori Deterministik (P4) mengklasifikasikan bunga bank (`BANK_INTEREST`) dan biaya admin bank (`BANK_FEE_TAX`).
- **Test 6:** **Golden Gate Kasus MI Darwata Sindangbarang**: Mutasi Kredit Rp852.000 teridentifikasi `direction=IN`, `role=PAYER`, dan terklasifikasi ke madrasah terkait.
- **Test 7:** Pencocokan Pola Historis Rekanan (P5) menghasilkan saran (`SUGGESTED`), **bukan auto-post**.
- **Test 8:** Pagar Pengaman Saran AI (P6) menghasilkan `confidence` & `evidence`, **mutlak tidak auto-post**.
- **Test 9:** Fallback Unclassified (P7) masuk antrean Review Queue dengan alasan transparan.
- **Test 10:** Deteksi & Pemasangan 2-Leg Transfer Bank Internal (`BANK_TRANSFER` P&L-Neutral).
- **Test 11:** Proteksi Periode Fiskal Tertutup (Memblokir auto-post untuk transaksi di periode `CLOSED`).
- **Test 12:** Proteksi Pembayaran Berlebih Piutang (Kelebihan dialokasikan ke `UNAPPLIED_CASH`, bukan pendapatan).
- **Test 13:** Validasi Semantik Arah Kas (Pencegahan kas masuk menjadi beban atau kas keluar menjadi pendapatan).
- **Test 14:** Klasifikasi Manual dengan Opsi `save_as_rule` (Membentuk aturan baru secara otomatis).
- **Test 15:** Isolasi Multi-Tenant Mutlak (Aturan dan saran tenant A tidak bocor ke tenant B).

---

## 6. Kriteria Kelulusan Phase Gate 6 (Acceptance Criteria)

Phase 6 dinyatakan selesai dan lulus jika seluruh kriteria berikut terpenuhi:
1. Seluruh 15 skenario pengujian baru pada `ClassificationTest.php` lulus 100% tanpa kegagalan (`zero failures`).
2. Seluruh 83 pengujian eksisting (Phase 1–5) tetap lulus tanpa regresi (total $\ge$ 98 tests).
3. Kasus uji spesifikasi wajib `MI Darwata Sindangbarang Kredit Rp852.000` diverifikasi lulus dengan arah `IN` dan peran rekanan `PAYER`.
4. Invarian `AI Suggestion Safety` terbukti secara otomatis: tidak ada transaksi dari saran AI yang langsung berstatus `POSTED` tanpa verifikasi operator.
5. Invarian `Bank Transfer Pairing` dan `Closed Period Protection` terbukti berjalan dengan benar.
6. Invarian `Multi-Tenant Isolation` terbukti tidak tembus lintas tenant.
