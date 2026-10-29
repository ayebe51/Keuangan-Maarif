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

