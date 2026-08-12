# SECURITY INVARIANTS (CORRECTED)

Dokumen ini mendefinisikan batas keamanan dan isolasi pada aplikasi Sistem Keuangan LP Ma'arif. Terdapat 3 level isolasi: Batas Organisasi (Tenant), Batas Otorisasi (RBAC), dan Jejak Audit.

## 1. Batas Organisasi (Tenant Isolation Boundary)
Aplikasi mendukung skenario Multi-Tenant (Multi-Organisasi).

**Aturan Emas:**
Data milik Organisasi A TIDAK BOLEH DAPAT DIBACA, DIUBAH, DIHAPUS, ATAU DIRUJUK (Referensi FK) oleh User/Sistem dari Organisasi B.

**Desain Invariant:**
- **Seluruh Entitas Utama & Relasional Wajib Memiliki `organization_id`**: Termasuk `BankImport`, `FundAllocation`, `FundCommitment`, `FundRealization`, `FundReturn`, `ReceivableAllocation`, dan `ReconciliationItem`.
- **Global Scope Laravel:** Di layer aplikasi, Eloquent model untuk semua entitas wajib menerapkan Global Scope `where('organization_id', current_tenant_id)` secara otomatis.

## 2. Cross-Tenant Integrity & Reference Protection (CRITICAL)
Sistem HARUS secara aktif menolak terjadinya kebocoran referensi antar tenant. Tidak cukup hanya memberikan `organization_id` pada parent dan child table. 

**Risiko Kebocoran (Leakage Scenarios):**
- Jurnal Organisasi A memiliki `JournalLine` yang menunjuk ke `Account` milik Organisasi B.
- `ReceivableAllocation` Organisasi A menunjuk ke `Receivable` milik Organisasi B.

**Mekanisme Penjaminan Domain Validation:**
Semua Application Service (misal `AccountingEngine`, `FundService`) wajib memverifikasi kepemilikan Foreign Key pada memori aplikasi atau database sebelum *commit*:
1. **Application-Level Validation:**
   ```php
   if ($account->organization_id !== $currentOrgId) { throw new CrossTenantViolationException(); }
   ```
2. **Database-Level Composite Uniqueness (Opsional namun disarankan):**
   Meskipun tidak semua relasi FK DB bisa dimodifikasi menggunakan composite keys (karena Laravel ORM menggunakan single `id`), validasi absolut diterapkan pada Application Transaction Logic dan **Automated Security Tests**.

## 3. Batas Otorisasi (RBAC - Segregation of Duties)
Operasi sensitif dipisahkan berdasarkan skema standar **Spatie Laravel Permission**:
- `roles`, `permissions`, `model_has_roles`.
- Tidak ada tabel buatan `RolePermission`.

Pembuat dilarang merangkap Penyetuju.
Tutup Buku hanya oleh `ACCOUNTING_ADMIN`.

## 4. Jejak Audit Immutabilitas (Immutable Audit Trail)
Semua aktivitas finansial (mutasi) direkam dalam `audit_logs` secara *Append-Only* dengan `before_json` dan `after_json`. Aksi yang wajib diaudit: Journal Creation, Approval, Posting, Reversal, Period Close, Override Klasifikasi.
