# PHASE 0.5 FINAL CONSISTENCY REVIEW

**Reviewer:** Principal Software Architect / Adversarial Reviewer
**Tanggal:** 12 Agustus 2026
**Tujuan:** Final Consistency Review & Adversarial Assessment sebelum Phase 1 dimulai.

---

## A. Executive Summary
Review adversarial secara mendalam telah dilakukan terhadap 10 dokumen hasil Phase 0.5, disilang dengan Spesifikasi Teknis Rev.3 dan prinsip akuntansi ganda (*double-entry accounting*). Meskipun checklist di permukaan terlihat lengkap, **ditemukan beberapa inkonsistensi kritikal dan kontradiksi desain** antara ERD, Data Dictionary, dan Strategi Akuntansi. Jika desain ini dilanjutkan ke Phase 1, akan terjadi *schema lock-in* pada keputusan yang belum disetujui, dan risiko kebocoran data multi-tenant.

---

## B. Critical Findings (Temuan Fatal)

1. **Kontradiksi Opening Balance (Saldo Awal):**
   - **Di `ERD_REVISED.md`:** Tabel `BankAccount` memiliki kolom `decimal opening_balance`.
   - **Di `GOLDEN_DATASET_STRATEGY.md`:** Ditegaskan bahwa saldo awal "tidak boleh dimasukkan sebagai angka ajaib di tabel bank", melainkan via mekanisme "Opening Balance Journal".
   - **Dampak:** Ini adalah kontradiksi fatal. Adanya kolom `opening_balance` di master bank akan memancing developer menggunakan kolom tersebut untuk kalkulasi pelaporan, mem-bypass buku besar (ledger), dan merusak integritas *reporting source of truth*.

2. **Hardcoding Keputusan Terbuka (Open Decision) ke dalam Skema:**
   - **Di `OPEN_DECISIONS.md` (D2):** Status definisi akun 21xx/22xx masih ambigu (apakah *Liability* atau *Restricted Equity*).
   - **Di `ERD_REVISED.md`:** Tabel `Fund` memiliki kolom `bigint liability_account_id FK`.
   - **Dampak:** Desain ERD secara diam-diam (implisit) telah memaksa penetapan bahwa dana tersebut adalah *Liability*, padahal statusnya OPEN. Ini melanggar aturan "jangan mengarang policy". Seharusnya cukup dinamakan `account_id` atau `fund_account_id`.

---

## C. Major Findings

1. **Inkonsistensi RBAC (Role-Based Access Control) Model:**
   - **Di `PHASE_1_CORRECTED_PLAN.md`:** Menginstruksikan penggunaan package `spatie/laravel-permission`.
   - **Di `ERD_REVISED.md`:** Mendefinisikan tabel kustom tunggal `RolePermission` (`role_name`, `permissions_json`).
   - **Dampak:** Jika developer mengikuti ERD, mereka tidak akan memakai Spatie. Jika mereka mengikuti Plan, ERD menjadi tidak valid karena Spatie menggunakan struktur tabel relasional (roles, permissions, model_has_roles).

---

## D. Minor Findings

1. Kolom `is_active` ada pada entitas `Organization`, `User`, `Account`, `Counterparty`, tetapi tidak ada pada `BankAccount` atau `Fund`. Diperlukan standardisasi *soft-delete* atau flag inaktif untuk master data finansial (mengingat master data tidak boleh di-*hard delete* jika sudah ada relasi jurnal).

---

## E. Cross-document Inconsistencies (ERD ↔ DATA DICTIONARY)

| ENTITY | ERD | DATA DICTIONARY | CONSISTENT | ISSUE |
|---|---|---|---|---|
| **RolePermission** | Ada | Tidak disebut rinci | ❌ NO | Bertentangan dengan rencana penggunaan Spatie package. |
| **BankAccount** | `opening_balance` | Tidak di-*highlight* | ❌ NO | Keberadaan `opening_balance` bertentangan dengan arsitektur jurnal saldo awal. |
| **Fund** | `liability_account_id` | Menyinggung *Restricted Equity* | ❌ NO | Menutup Open Decision secara prematur lewat penamaan kolom skema. |

---

## F. Accounting Risks

- Pemisahan `FundAllocation`, `FundCommitment`, `FundRealization`, dan `FundReturn` sudah baik, namun ketiadaan `organization_id` langsung di tabel-tabel tersebut (mengandalkan JOIN ke `Fund`) berisiko tinggi. Jika query alokasi luput melakukan join ke parent, saldo antar-organisasi bisa tercampur di laporan agregat.

---

## G. Security Risks (Tenant Isolation)

- **Leaky Tenant Boundaries:** Tabel-tabel transaksi tingkat dua dan tiga seperti `BankImport`, `FundAllocation`, `FundRealization`, `ReceivableAllocation`, dan `ReconciliationItem` **TIDAK MEMILIKI** kolom `organization_id` di ERD. 
- **Dampak Keamanan:** Dalam arsitektur Laravel dengan *Global Scopes*, ketiadaan `organization_id` di setiap tabel mengharuskan scope melakukan `WHERE HAS` atau `JOIN` yang rawan bocor (developer lupa) dan berdampak pada penurunan performa *query* masif.

---

## H. Migration Risks

- Membiarkan tabel kustom `RolePermission` di ERD akan menyebabkan bentrok (*clash*) pada saat menjalankan migrasi default dari Spatie saat eksekusi Phase 1.

---

## I. Required Corrections (Koreksi yang Diwajibkan)

1. Hapus kolom `opening_balance` dari entitas `BankAccount` di ERD dan spesifikasi. Selesaikan *Opening Balance* murni melalui `JournalEntry` bersumber dari `OpeningBalanceSource`.
2. Ubah kolom `liability_account_id` di tabel `Fund` menjadi `account_id` (netral) agar tidak memaksakan interpretasi akuntansi (Liabilitas vs Ekuitas).
3. Hapus entitas `RolePermission` dari ERD dan nyatakan secara eksplisit penggunaan skema tabel standar `spatie/laravel-permission` (roles, permissions, model_has_roles, role_has_permissions).
4. Tambahkan kolom `organization_id` sebagai Foreign Key yang *non-nullable* pada tabel: `BankImport`, `FundAllocation`, `FundCommitment`, `FundRealization`, `FundReturn`, `ReceivableAllocation`, dan `ReconciliationItem`. Pastikan ini terdokumentasi dalam *Security Invariants*.

---

## J. Final Decision

Karena terdapat kontradiksi kritikal pada mekanisme saldo awal, pelanggaran pada kebijakan penetapan asumsi finansial (Open Decision di-*hardcode* ke skema DB), serta celah keamanan pada batasan *multi-tenant*...

### **NOT APPROVED — CORRECTIONS REQUIRED**

**Status Gate: FAIL**
Phase 1 **TIDAK BOLEH DIAWAL** hingga seluruh revisi di atas diimplementasikan dan ERD serta Implementation Plan diselaraskan.
