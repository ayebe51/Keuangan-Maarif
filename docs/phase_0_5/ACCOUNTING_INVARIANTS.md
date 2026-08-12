# ACCOUNTING INVARIANTS (CORRECTED)

Dokumen ini menjelaskan aturan ketat (invariants) yang dijamin oleh sistem untuk memastikan integritas data finansial tidak pernah rusak di level database maupun kode aplikasi.

## 1. Aturan Baris Jurnal (Journal Line Constraints)
Pada tabel `journal_lines`:
1. `debit >= 0` dan `credit >= 0`. Dilarang ada nilai uang negatif (minus).
2. `NOT (debit > 0 AND credit > 0)`. Satu baris jurnal tidak boleh memiliki debit sekaligus kredit.
3. `NOT (debit = 0 AND credit = 0)`. Satu baris jurnal tidak boleh kosong keduanya.

## 2. Jurnal Seimbang (Balanced Journal Constraint)
Pada entitas `JournalEntry`: Total penjumlahan `debit` HARUS SAMA dengan total penjumlahan `credit`. (`SUM(debit) = SUM(credit)`).
Dijamin oleh Domain Service `AccountingEngine` dalam `DB::transaction()`.

## 3. Saldo Awal (Opening Balance Invariant)
Saldo awal bank TIDAK BOLEH menjadi sekadar angka *hardcode* di dalam master rekening.
- **Entitas Penjamin:** `OpeningBalanceSource` menyimpan deklarasi saldo dari *Neraca Awal*.
- **Alur Transformasi:** `OpeningBalanceSource` secara paksa men-generate `JournalEntry` bersatus POSTED ke Ledger yang melibatkan akun Kas/Bank di sisi debit dan Ekuitas/Saldo Awal di sisi kredit (agar seimbang).
- **Hasil:** Mutasi dan *reporting* akan bertumpu murni pada Ledger, bukan kalkulasi terpisah dari tabel BankAccount.

## 4. Immutability (Sifat Tidak Dapat Diubah)
Jurnal berstatus `POSTED` bersifat mutlak **Immutable** (tidak bisa diubah atau dihapus).
- Aplikasi dilarang memodifikasi (`UPDATE`/`DELETE`) entitas `journal_entries` atau `journal_lines` yang sudah memiliki status `POSTED`.

## 5. Koreksi via Reversal (Reversal Constraint)
Jika jurnal `POSTED` salah, cara koreksinya adalah:
1. Membuat Jurnal **REVERSAL** baru.
2. Jurnal baru ini WAJIB menunjuk jurnal lama via field `reversal_of_id`.
3. Aplikasi tidak memodifikasi baris lama, melainkan menambah entri baru yang mendebet-kredit secara berlawanan (*self-balancing*).

## 6. Kuncian Periode Fiskal (Period Lock Constraint)
Jurnal tidak boleh dibuat menembus periode `CLOSED`. Dijamin lewat `ClosedPeriodException` di layer Service.
