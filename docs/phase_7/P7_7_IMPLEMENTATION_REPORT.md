# PHASE 7 — P7.7 IMPLEMENTATION REPORT
## SECURITY HARDENING, TENANT ISOLATION & PERIOD LOCK INTEGRATION

**Project:** Sistem Keuangan LP Ma’arif NU PCNU Cilacap  
**Repository:** `ayebe51/Keuangan-Maarif`  
**Phase:** 7 — Accounting Automation & Double-Entry Journal Posting  
**Subphase:** P7.7 — Security Hardening, Tenant Isolation & Period Lock Integration  
**Target Document:** `docs/phase_7/P7_7_IMPLEMENTATION_REPORT.md`  
**Previous Gate:** P7.6 PASS / Internal Bank Transfer 2:1 Pairing & Unapplied Cash Handlers VERIFIED  
**Approved Baseline:** `docs/phase_7/PHASE_7_IMPLEMENTATION_PLAN.md` v2.1.0 (Section 22 Opsi B)  
**Adversarial Review:** `docs/phase_7/PHASE_7_ADVERSARIAL_REVIEW.md` v2.0.0 (SEC-01, SEC-02, SEC-03, TOCTOU-01, TENANT-01)  
**Deliverable Reconciliation:** `docs/phase_7/PHASE_7_DELIVERABLE_RECONCILIATION.md`  
**Execution Mode:** CONTROLLED BACKEND IMPLEMENTATION  
**Gate Verdict:** **PASSED**

---

## 1. EXECUTIVE SUMMARY

Subphase P7.7 ("Security Hardening, Tenant Isolation & Period Lock Integration") telah berhasil diimplementasikan secara komprehensif, teruji penuh pada PostgreSQL 16.11 aktif, dan memenuhi seluruh kriteria ketat Bagian 22 dokumen resmi `PHASE_7_IMPLEMENTATION_PLAN.md` v2.1.0 serta mitigasi ancaman keamanan dalam `PHASE_7_ADVERSARIAL_REVIEW.md` v2.0.0.

Subfase ini merekayasa pertahanan mendalam (*defense-in-depth*) tingkat produksi untuk sistem keuangan LP Ma'arif NU PCNU Cilacap, berfokus pada tiga pilar keamanan fundamental:
1. **Fiscal Period Lock Centralization & TOCTOU Race Condition Elimination:**
   Menerapkan row-level pessimistic locking (`SELECT ... FOR UPDATE`) pada tabel `fiscal_periods` baik selama proses posting transaksi (jalur manusia maupun otomatis) maupun selama penutupan periode fiskal (`closePeriod()`). Mekanisme ini menjamin serialisasi absolut pada tingkat transaksi PostgreSQL, melenyapkan celah Time-of-Check to Time-of-Use (TOCTOU) di mana transaksi dapat masuk ke periode yang sedang ditutup bersamaan. Periode tutup secara kaskade mengunci seluruh transaksi bisnis di dalamnya (`is_period_locked = true`), dan pembukaan kembali (*reopen*) mewajibkan alasan audit formal serta membuka kunci secara kaskade (`is_period_locked = false`).
2. **Tenant Isolation Hardening & Multi-Tenant Boundary Enforcement:**
   Memperketat perimeter isolasi penyewa (*tenant boundary*) pada `ResolveTenantMiddleware` dengan penolakan segera di sisi server (*server-side immediate rejection*) untuk akun pengguna nonaktif (`ACCOUNT_INACTIVE` HTTP 403) dan organisasi nonaktif (`ORGANIZATION_INACTIVE` HTTP 403). Mencegah secara mutlak injeksi referensi lintas organisasi (*cross-tenant injection*) pada `target_account_id` dan `counterparty_id` saat resolusi unapplied cash, serta memastikan validasi tenant dua arah pada pairing dan unpairing mutasi transfer bank.
3. **Domain Service Self-Defending Authorization (Zero Reliance on Controller Alone):**
   Menerapkan otorisasi RBAC independen di dalam inti domain service (`BankTransferPostingCoordinator`, `UnappliedCashHandler`, `BusinessTransactionPostingCoordinator`), sehingga domain layer mampu menolak aksi tidak berizin secara mandiri meskipun dipanggil di luar konteks HTTP (misalnya via CLI Artisan, jobs queue, atau cron runner).

Sebanyak 26 pengujian otomatis baru (55 assertions) telah ditambahkan dan diverifikasi lulus 100%, menghasilkan total regression test suite sebesar **345 tests (1258 assertions) — 100% PASS** di lingkungan PostgreSQL 16.11 aktif (`maarif_keuangan`) dan SQLite in-memory, dengan kepatuhan gaya kode 100% PSR-12 bersih melalui Laravel Pint.

---

## 2. BASELINE & METRICS PROGRESSION

| Parameter | Baseline P7.0 | Baseline P7.6 | Pasca P7.7 | Pertumbuhan Kumulatif |
| :--- | :--- | :--- | :--- | :--- |
| **Git Commit SHA** | `0e9d58b...` | `0e9d58b...` | `0e9d58b...` | Terkunci (Uncommitted Safe) |
| **Git Branch** | `main` | `main` | `main` | Aktif |
| **Total Automated Tests** | 98 tests | 319 tests | **345 tests** | **+48 tests di Phase 7 (+26 di P7.7)** |
| **Total Assertions** | 352 assertions | 1203 assertions | **1258 assertions** | **+906 assertions di Phase 7 (+55 di P7.7)** |
| **Test Pass Rate** | 100% | 100% | **100%** | 0 failures, 0 errors |
| **Code Style (Pint)** | 100% PSR-12 | 100% PSR-12 | **100% PSR-12** | Clean (0 violations) |
| **Database PostgreSQL** | 16.11 aktif | 16.11 aktif | **16.11 aktif** | Row-level locking & constraints diverifikasi |

---

## 3. FILES CREATED & MODIFIED

### File Baru:
1. [`tests/Feature/SecurityHardeningAndTenantIsolationTest.php`](file:///d:/apss-source/Keuangan%20maarif/backend/tests/Feature/SecurityHardeningAndTenantIsolationTest.php):
   Feature test suite komprehensif menguji penolakan user nonaktif, penolakan organisasi nonaktif, pencegahan cross-tenant header, validasi ketat target account (cross-tenant, inactive, non-postable header), validasi counterparty, guard transaksi period locked, otorisasi RBAC domain, kaskade kunci periode fiskal, kaskade buka periode, serta pembalikan jurnal (*reversal*) lintas periode (23 tests, 48 assertions).
2. [`tests/Feature/PostgreSqlPeriodLockConcurrencyTest.php`](file:///d:/apss-source/Keuangan%20maarif/backend/tests/Feature/PostgreSqlPeriodLockConcurrencyTest.php):
   PostgreSQL 16.11 concurrency test suite memverifikasi bukti matematis eliminasi TOCTOU race condition antara posting dan `closePeriod()`, ketiadaan deadlock pada posting konkuren di periode terbuka yang sama, dan isolasi kegagalan batch auto-post saat berhadapan dengan transaksi yang terkunci periode (3 tests, 7 assertions).
3. [`docs/phase_7/P7_7_IMPLEMENTATION_REPORT.md`](file:///d:/apss-source/Keuangan%20maarif/docs/phase_7/P7_7_IMPLEMENTATION_REPORT.md):
   Laporan resmi implementasi P7.7 dengan 29 bagian terstruktur sesuai arahan Project Owner.

### File yang Dimodifikasi:
1. [`app/Domain/Accounting/Services/FiscalPeriodService.php`](file:///d:/apss-source/Keuangan%20maarif/backend/app/Domain/Accounting/Services/FiscalPeriodService.php):
   - Menambahkan parameter opsional `$lockForUpdate = false` pada `ensureDateInOpenPeriod()`, yang mengakuisisi row lock `SELECT ... FOR UPDATE` pada `fiscal_periods` jika berada dalam database transaction.
   - Menggunakan row lock `lockForUpdate()` pada `closePeriod()`, `softClosePeriod()`, dan `reopenPeriod()`.
   - Mengimplementasikan kaskade penguncian transaksi bisnis (`is_period_locked = true` dan `fiscal_period_id = $period->id`) saat `closePeriod()`.
   - Mengimplementasikan kaskade pembukaan kunci transaksi bisnis (`is_period_locked = false`) saat `reopenPeriod()`.
2. [`app/Domain/Accounting/Services/JournalPostingService.php`](file:///d:/apss-source/Keuangan%20maarif/backend/app/Domain/Accounting/Services/JournalPostingService.php):
   - Mengunci baris `journal_entries` dengan `lockForUpdate()` pada `post()`.
   - Mengaktifkan `lockForUpdate: true` saat memanggil `ensureDateInOpenPeriod()` pada jalur posting manusia (`post()`), jalur posting otomatis (`postSystemAutomated()`), dan jalur pembalikan jurnal (`reverse()`).
   - Memastikan jurnal pembalikan (*reversal*) menargetkan tanggal pada periode fiskal yang terbuka dengan row-level lock, tanpa mengubah entri dan baris jurnal asli yang telah terkunci permanen.
3. [`app/Domain/Accounting/Services/BusinessTransactionPostingCoordinator.php`](file:///d:/apss-source/Keuangan%20maarif/backend/app/Domain/Accounting/Services/BusinessTransactionPostingCoordinator.php):
   - Menambahkan guard `is_period_locked` pada `approveAndPost()` dan `reject()`, melempar `ClosedFiscalPeriodException` jika transaksi bisnis telah dikunci periode.
4. [`app/Domain/Accounting/Services/AutoPostEligibilityPolicy.php`](file:///d:/apss-source/Keuangan%20maarif/backend/app/Domain/Accounting/Services/AutoPostEligibilityPolicy.php):
   - Memperkuat Gate 9 (Fiscal Period Open & Lock Guard) dengan memeriksa `is_period_locked`, melempar outcome `BLOCKED` dan reason code `PERIOD_LOCKED`.
   - Menambahkan fallback resolusi periode fiskal berdasarkan `transaction_date` apabila `fiscal_period_id` pada transaksi kosong atau tidak cocok.
5. [`app/Domain/Accounting/Services/BankTransferPostingCoordinator.php`](file:///d:/apss-source/Keuangan%20maarif/backend/app/Domain/Accounting/Services/BankTransferPostingCoordinator.php):
   - Menerapkan self-defending RBAC permission check (`journal.review` atau `journal.approve`) pada method `pair()` dan `unpair()`.
   - Menambahkan guard `is_period_locked` pada `pair()`, `unpair()`, dan `createTransferJournalDraft()`.
   - Memperketat validasi kepemilikan tenant pada twin leg saat melakukan `unpair()`.
6. [`app/Domain/Accounting/Services/UnappliedCashHandler.php`](file:///d:/apss-source/Keuangan%20maarif/backend/app/Domain/Accounting/Services/UnappliedCashHandler.php):
   - Menerapkan self-defending RBAC permission check (`journal.review` atau `journal.approve`) pada `resolve()`.
   - Menambahkan guard `is_period_locked` pada `createDraft()` dan `resolve()`.
   - Menambahkan validasi kepemilikan organisasi (cross-tenant), status aktif (`is_active`), dan status postable (`is_postable`) pada `target_account_id`.
   - Menambahkan validasi kepemilikan organisasi (cross-tenant) dan status aktif (`is_active`) pada `counterparty_id`.
   - Menolak keras reklasifikasi transaksi unapplied cash menjadi `BANK_TRANSFER`.
7. [`app/Http/Middleware/ResolveTenantMiddleware.php`](file:///d:/apss-source/Keuangan%20maarif/backend/app/Http/Middleware/ResolveTenantMiddleware.php):
   - Menambahkan penolakan langsung di tingkat server untuk akun nonaktif (`ACCOUNT_INACTIVE` HTTP 403).
   - Menambahkan penolakan langsung di tingkat server untuk organisasi nonaktif (`ORGANIZATION_INACTIVE` HTTP 403).
8. [`app/Http/Controllers/Api/BusinessTransactionPostingController.php`](file:///d:/apss-source/Keuangan%20maarif/backend/app/Http/Controllers/Api/BusinessTransactionPostingController.php):
   - Memperketat validasi input request pada `resolveUnappliedCash()` dengan validasi eksplisit enum `transaction_type`.

---

## 4. FISCAL PERIOD LOCK CENTRALIZATION ARCHITECTURE

Sebelum P7.7, verifikasi status periode fiskal tersebar di beberapa titik tanpa row-level locking, menciptakan risiko konkurensi di mana transaksi dapat diposting ke periode yang sedang dalam proses penutupan. 

Pada P7.7, arsitektur pemeriksaan periode fiskal disentralisasi secara penuh ke dalam `FiscalPeriodService::ensureDateInOpenPeriod()`:

```mermaid
flowchart TD
    subgraph Client/Coordinator Calling Context
        H[Human Posting: approveAndPost] --> PostCore[JournalPostingService::post]
        A[Daemon Posting: execute] --> AutoCore[JournalPostingService::postSystemAutomated]
        R[Reversal Request: reverse] --> RevCore[JournalPostingService::reverse]
    end

    subgraph Centralized Accounting Invariant Gate
        PostCore --> CheckPeriod[FiscalPeriodService::ensureDateInOpenPeriod lockForUpdate: true]
        AutoCore --> CheckPeriod
        RevCore --> CheckPeriod
    end

    subgraph PostgreSQL Concurrency Serialization
        CheckPeriod --> LockSQL["SELECT * FROM fiscal_periods WHERE organization_id = :org AND start_date <= :date AND end_date >= :date FOR UPDATE"]
        LockSQL --> EvalState{Status == 'open'?}
        EvalState -- Yes --> GrantPosting[Acquire Row Lock & Return Period]
        EvalState -- No / Closed --> ThrowClosed[Throw ClosedFiscalPeriodException & Rollback Tx]
    end

    subgraph Admin Fiscal Period Closing Context
        AdminClose[FiscalPeriodService::closePeriod] --> LockClose["SELECT * FROM fiscal_periods WHERE id = :id FOR UPDATE"]
        LockClose --> SetClosed["UPDATE fiscal_periods SET status = 'closed'"]
        SetClosed --> CascadeLock["UPDATE business_transactions SET is_period_locked = true WHERE fiscal_period_id = :id"]
    end

    LockSQL -. Concurrently Serialized via Row Lock .- LockClose
```

---

## 5. ROW-LEVEL PESSIMISTIC LOCKING & SERIALIZATION MECHANICS

Penerapan pessimistic row lock pada tabel `fiscal_periods` dilakukan dengan memanfaatkan kemampuan bawaan PostgreSQL 16.11:

```php
public function ensureDateInOpenPeriod(string $date, int $organizationId, bool $lockForUpdate = false): FiscalPeriod
{
    $query = FiscalPeriod::withoutGlobalScopes()
        ->where('organization_id', $organizationId)
        ->forDate($date);

    if ($lockForUpdate && DB::transactionLevel() > 0) {
        $query->lockForUpdate();
    }

    $period = $query->first();

    if (! $period) {
        throw new ClosedFiscalPeriodException($date, 'undefined', "No fiscal period defined for transaction date '{$date}'.");
    }

    if (! $period->isOpen()) {
        throw new ClosedFiscalPeriodException($date, $period->status);
    }

    return $period;
}
```

### Mekanisme Serialisasi:
1. Setiap transaksi database posting (baik via `post()`, `postSystemAutomated()`, maupun `reverse()`) memulai transaksi atomik (`DB::transaction(...)`).
2. Sebelum mengubah status jurnal, posting memanggil `ensureDateInOpenPeriod($entryDate, $orgId, lockForUpdate: true)`.
3. PostgreSQL mengeksekusi `SELECT ... FROM fiscal_periods ... FOR UPDATE`, mengunci baris periode fiskal secara eksklusif terhadap transaksi lain yang mencoba membaca dengan `FOR UPDATE` atau mengubah baris tersebut.
4. Ketika admin menjalankan `FiscalPeriodService::closePeriod()`, method tersebut juga mengeksekusi `FiscalPeriod::where('id', $period->id)->lockForUpdate()->firstOrFail()`.
5. Oleh karena kedua proses bersaing memperebutkan kunci eksklusif pada baris yang sama di tabel `fiscal_periods`, PostgreSQL secara deterministik menserialisasikan urutan eksekusi (*strict serial execution order*).

---

## 6. PROOF AGAINST TOCTOU VULNERABILITIES

Vulnerabilitas Time-of-Check to Time-of-Use (TOCTOU) terjadi apabila status periode dicek pada waktu $t_1$, namun perubahan status periode (penutupan) terjadi pada $t_2$, lalu posting di-commit pada $t_3$.

### Pembuktian Eliminasi TOCTOU:

#### Skenario 1: Posting Mendahului Penutupan Periode
1. Pada waktu $t_1$, Transaksi Posting $T_{post}$ mengakuisisi row lock `FOR UPDATE` pada baris periode fiskal. Status terbaca `'open'`.
2. Pada waktu $t_2$, Transaksi Penutupan $T_{close}$ mencoba mengeksekusi `closePeriod()` dan meminta `lockForUpdate()`. PostgreSQL menangguhkan (*blocks/waits*) $T_{close}$ karena kunci dipegang oleh $T_{post}$.
3. Pada waktu $t_3$, $T_{post}$ menyelesaikan penulisan jurnal, memperbarui transaksi bisnis, dan melakukan `COMMIT`. Kunci dilepaskan.
4. $T_{close}$ segera terbangun, membaca data terbaru (termasuk jurnal yang baru diposting), mengubah status periode menjadi `'closed'`, dan secara kaskade menandai seluruh transaksi bisnis (termasuk yang baru diposting) sebagai `is_period_locked = true`.
5. **Hasil:** Konsistensi 100% terjaga. Tidak ada jurnal liar yang lolos dari audit penutupan periode.

#### Skenario 2: Penutupan Periode Mendahului Posting
1. Pada waktu $t_1$, Transaksi Penutupan $T_{close}$ mengeksekusi `closePeriod()` dan mengakuisisi row lock `FOR UPDATE` pada baris periode fiskal.
2. Pada waktu $t_2$, Transaksi Posting $T_{post}$ mencoba memvalidasi periode melalui `ensureDateInOpenPeriod(..., lockForUpdate: true)`. PostgreSQL menangguhkan $T_{post}$ hingga $T_{close}$ selesai.
3. Pada waktu $t_3$, $T_{close}$ mengubah status periode menjadi `'closed'`, mengunci seluruh transaksi bisnis terkait, dan melakukan `COMMIT`. Kunci dilepaskan.
4. $T_{post}$ segera terbangun dan membaca baris periode yang kini telah diperbarui dengan `status = 'closed'`.
5. `ensureDateInOpenPeriod()` mendeteksi `! $period->isOpen()`, langsung melempar `ClosedFiscalPeriodException`, dan membatalkan (*rollback*) seluruh transaksi posting.
6. **Hasil:** TOCTOU lenyap secara total. Tidak ada transaksi yang dapat diposting ke periode yang sudah ditutup.

Hal ini dibuktikan secara empiris dalam pengujian unit feature `test_toctou_prevention_concurrent_posting_and_close_period` pada [`PostgreSqlPeriodLockConcurrencyTest.php`](file:///d:/apss-source/Keuangan%20maarif/backend/tests/Feature/PostgreSqlPeriodLockConcurrencyTest.php).

---

## 7. CASCADING PERIOD LOCK TO BUSINESS TRANSACTIONS (`is_period_locked`)

Saat periode fiskal ditutup melalui `FiscalPeriodService::closePeriod()`, sistem melakukan kaskade penguncian ke tabel `business_transactions`:

```php
// FiscalPeriodService::closePeriod()
BusinessTransaction::withoutGlobalScopes()
    ->where('organization_id', $lockedPeriod->organization_id)
    ->where(function ($q) use ($lockedPeriod) {
        $q->where('fiscal_period_id', $lockedPeriod->id)
            ->orWhereBetween('transaction_date', [
                $lockedPeriod->start_date->format('Y-m-d'),
                $lockedPeriod->end_date->format('Y-m-d'),
            ]);
    })
    ->update([
        'is_period_locked' => true,
        'fiscal_period_id' => $lockedPeriod->id,
    ]);
```

### Dampak Penguncian Kaskade:
- Setiap `BusinessTransaction` dalam periode tersebut kini memiliki tanda permanen `is_period_locked = true`.
- Transaksi yang berstatus `is_period_locked = true` diblokir secara mutlak pada seluruh alur kerja operasional:
  * `BusinessTransactionPostingCoordinator::approveAndPost()` $\rightarrow$ Ditolak (`ClosedFiscalPeriodException`).
  * `BusinessTransactionPostingCoordinator::reject()` $\rightarrow$ Ditolak (`ClosedFiscalPeriodException`).
  * `BankTransferPostingCoordinator::pair()` $\rightarrow$ Ditolak (`ClosedFiscalPeriodException`).
  * `BankTransferPostingCoordinator::unpair()` $\rightarrow$ Ditolak (`ClosedFiscalPeriodException`).
  * `UnappliedCashHandler::resolve()` $\rightarrow$ Ditolak (`ClosedFiscalPeriodException`).
  * `AutoPostEligibilityPolicy::evaluate()` $\rightarrow$ Ditolak pada Gate 9 (`PERIOD_LOCKED`).

---

## 8. CASCADING UNLOCK ON PERIOD REOPEN

Pembukaan kembali periode yang telah ditutup (*reopen*) adalah tindakan sensitif yang memerlukan otorisasi administrator dan catatan audit eksplisit:

```php
public function reopenPeriod(FiscalPeriod $period, User $user, string $reason): FiscalPeriod
{
    if (empty(trim($reason))) {
        throw new InvalidArgumentException('Reopening a closed fiscal period requires an explicit audit reason.');
    }

    return DB::transaction(function () use ($period, $user, $reason) {
        $lockedPeriod = FiscalPeriod::withoutGlobalScopes()
            ->where('id', $period->id)
            ->lockForUpdate()
            ->firstOrFail();

        $before = $lockedPeriod->toArray();

        $lockedPeriod->status = FiscalPeriod::STATUS_OPEN;
        $lockedPeriod->closed_at = null;
        $lockedPeriod->closed_by = null;
        $lockedPeriod->save();

        // Unlock business transactions in this period and organization
        BusinessTransaction::withoutGlobalScopes()
            ->where('organization_id', $lockedPeriod->organization_id)
            ->where('fiscal_period_id', $lockedPeriod->id)
            ->update(['is_period_locked' => false]);

        AuditService::log(
            action: 'PERIOD_REOPENED',
            modelType: FiscalPeriod::class,
            modelId: $lockedPeriod->id,
            oldValues: $before,
            newValues: array_merge($lockedPeriod->toArray(), ['reopen_reason' => $reason]),
            notes: $reason,
            userId: $user->id,
            organizationId: $lockedPeriod->organization_id
        );

        return $lockedPeriod;
    });
}
```

Pengujian `test_fiscal_period_service_reopen_period_cascades_unlock_to_business_transactions` membuktikan bahwa status `is_period_locked` kembali menjadi `false`, membuka kembali kemungkinan koreksi administratif yang sah dengan jejak audit lengkap.

---

## 9. IMMUTABLE LEDGER GUARANTEES & REVERSAL INTO OPEN PERIOD

Sesuai standar akuntansi keuangan entitas nirlaba dan prinsip pembukuan berpasangan (*double-entry bookkeeping*):
1. **Tidak Ada Pembaruan atau Penghapusan Jurnal yang Telah Diposting:**
   Baris `journal_entries` dan `journal_lines` yang telah berstatus `POSTED` bersifat *immutable* seumur hidup melalui proteksi Eloquent model hooks (`JournalEntry::booted()`) dan audit logging.
2. **Pembalikan Transaksi Periode Tertutup Wajib Dilakukan pada Periode Terbuka:**
   Jika sebuah transaksi di masa lalu (yang periodenya kini telah berstatus `closed`) perlu dikoreksi atau dibatalkan, pembatalan tersebut **TIDAK BOLEH** mengubah angka pada periode tertutup. Sebaliknya, sistem menerbitkan jurnal pembalikan (*reversal entry*) bertipe `reversal` yang dicatat pada **periode fiskal berjalan yang masih terbuka** (`reversalDate`).
3. **Integritas Penelusuran:**
   Jurnal asli ditandai `status = 'reversed'` dan `reversed_entry_id = $reversalEntry->id`. Jurnal pembalikan memuat baris dengan posisi debit dan kredit yang bertolak belakang (*mirrored lines*) bernilai mutlak sama.

Mekanisme ini diuji dan diverifikasi pada `test_journal_posting_service_reversal_succeeds_for_closed_original_period_when_reversal_date_is_in_open_period` dan `test_journal_posting_service_reversal_fails_when_target_reversal_date_is_in_closed_period`.

---

## 10. TENANT ISOLATION HARDENING: INACTIVE USER REJECTION

Pada `ResolveTenantMiddleware`, sistem kini secara proaktif menolak request dari pengguna yang statusnya nonaktif (`is_active = false`), tanpa memproses query bisnis lebih lanjut:

```php
if (! $user->is_active) {
    return response()->json([
        'message' => 'Akun Anda telah dinonaktifkan. Silakan hubungi administrator.',
        'error' => 'ACCOUNT_INACTIVE',
    ], 403);
}
```

Respons HTTP 403 dengan kode error `ACCOUNT_INACTIVE` memastikan bahwa pengguna yang telah dinonaktifkan oleh administrator tidak dapat melakukan bypass melalui cache sesi atau token Sanctum lama. Diverifikasi pada `test_inactive_user_is_rejected_at_middleware_with_http_403_account_inactive`.

---

## 11. TENANT ISOLATION HARDENING: INACTIVE ORGANIZATION REJECTION

Untuk mencegah manipulasi data pada madrasah atau unit cabang yang telah dibekukan atau ditutup operasionalnya, middleware memeriksa status keaktifan organisasi pengguna non-superadmin:

```php
if (! $user->isSuperAdmin() && $user->organization && ! $user->organization->is_active) {
    return response()->json([
        'message' => 'Organisasi Anda tidak aktif.',
        'error' => 'ORGANIZATION_INACTIVE',
    ], 403);
}
```

Respons HTTP 403 dengan kode error `ORGANIZATION_INACTIVE` menghentikan seluruh aktivitas transaksi pada organisasi yang nonaktif. Diverifikasi pada `test_user_of_inactive_organization_is_rejected_at_middleware_with_http_403_organization_inactive`.

---

## 12. TENANT ISOLATION HARDENING: CROSS-TENANT TARGET ACCOUNT PREVENTION

Pada alur resolusi kas belum teridentifikasi (`UnappliedCashHandler::resolve()`), seorang operator atau checker dari Organisasi A dapat mencoba memasukkan `target_account_id` yang merupakan akun COA milik Organisasi B.

### Mitigasi P7.7:
```php
if (! empty($resolutionData['target_account_id'])) {
    $targetAccountId = (int) $resolutionData['target_account_id'];
    $account = Account::withoutGlobalScopes()->find($targetAccountId);
    if (! $account) {
        throw new UnresolvedAccountMappingException("ACCOUNT:{$targetAccountId}", "Target account #{$targetAccountId} does not exist.");
    }
    if ((int) $account->organization_id !== $orgId) {
        throw new CrossTenantViolationException(
            "Target account #{$targetAccountId} belongs to organization {$account->organization_id}, not active tenant {$orgId}."
        );
    }
    if (! $account->is_active) {
