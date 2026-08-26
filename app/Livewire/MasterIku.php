<?php

namespace App\Livewire;

use App\Exports\MasterIkuTemplateExport;
use App\Imports\MasterIkuImport;
use App\Models\CapaianTahunan;
use App\Models\MasterIku as MasterIkuModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Livewire\Component;
use Livewire\WithFileUploads;

class MasterIku extends Component
{
    use WithFileUploads;

    public $excelFile = null;

    /**
     * Tahun yang dipakai untuk menyimpan Target Tahunan/Alokasi TW I-IV hasil
     * import ke App\Models\CapaianTahunan (satu baris per iku_id+tahun) — file
     * Excel sendiri tidak punya kolom "Tahun", jadi dipilih di sini sebelum upload
     * (sama seperti pola di App\Livewire\TargetTahunan).
     */
    public int $tahunImpor;

    /**
     * 'insert': Kode Indikator yang sudah ada di database DITOLAK (baris error).
     * 'upsert': Kode Indikator yang sudah ada di database DIPERBARUI.
     */
    public string $modeImpor = 'insert';

    /**
     * true (default): bila ADA satu saja baris error di pratinjau, konfirmasiImpor()
     * menolak SELURUHNYA (tidak menyimpan baris yang valid sekalipun). false: baris
     * valid tetap disimpan, baris error dilewati.
     */
    public bool $batalkanSemuaBilaError = true;

    /**
     * Hasil pratinjauExcel() — daftar baris {baris, valid, data, errors} dari
     * App\Services\MasterIkuImportValidator, ditampilkan SEBELUM konfirmasiImpor()
     * benar-benar menyimpan apa pun. Null berarti belum ada pratinjau berjalan.
     *
     * @var list<array{baris: int, valid: bool, data: array|null, errors: list<string>}>|null
     */
    public ?array $pratinjau = null;

    /** @var list<string> pesan error struktural (header/baris contoh/petunjuk template rusak) */
    public array $pratinjauErrorStruktural = [];

    public ?int $editingId = null;

    public ?int $pendingDeleteId = null;

    /** @var list<string>|null */
    public ?array $alasanTidakBisaHapus = null;

    public string $kode = '';

    public string $kodeTujuan = '';

    public string $namaTujuan = '';

    public string $kodeSasaran = '';

    public string $indikator = '';

    public string $tim = '';

    public string $penanggungJawab = '';

    public string $sasaran = '';

    public string $dasarHitung = '';

    public string $basisData = '';

    public string $satuan = 'Persen';

    /**
     * 'langsung': Alokasi Target/Realisasi diketik langsung sebagai angka (default,
     * cocok untuk IKU bertipe Non %). 'rasio': diketik lewat Pembilang (X)/Penyebut
     * (Y) mentah, persentasenya dihitung otomatis X÷Y×100 — sesuai Kertas Kerja
     * Pengukuran Kinerja Triwulanan resmi untuk IKU bertipe % (lihat
     * App\Models\MasterIku::pakaiRasio(), App\Models\CapaianTahunan).
     */
    public string $metodeCapaian = 'langsung';

    /**
     * 'iku': indikator inti (default). 'proksi': indikator pendukung/pengganti
     * sementara — sesuai kolom "Jenis (IKU atau Proksi)" Kertas Kerja Pengukuran
     * Kinerja Triwulanan resmi (App\Models\MasterIku::JENIS_IKU/JENIS_PROKSI).
     */
    public string $jenisIku = 'iku';

    /**
     * 'tahunan' (default) | 'triwulanan' — sesuai kolom "Jenis (Triwulanan atau
     * Tahunan)" Kertas Kerja resmi, murni informasional (lihat
     * App\Models\MasterIku::pakaiTriwulanan()).
     */
    public string $jenisPeriode = 'tahunan';

    public string $deskripsiX = '';

    public string $deskripsiY = '';

    public function mount(): void
    {
        $this->tahunImpor = (int) now()->year;
    }

    protected function rules(): array
    {
        return [
            'kode' => [
                'required', 'string', 'max:50',
                'unique:master_iku,kode,'.($this->editingId ?? 'NULL').',id',
            ],
            'kodeTujuan' => ['nullable', 'string', 'max:50'],
            'namaTujuan' => ['nullable', 'string', 'max:255'],
            'kodeSasaran' => ['nullable', 'string', 'max:50'],
            'indikator' => ['required', 'string'],
            'tim' => ['required', 'string', 'max:255'],
            'penanggungJawab' => ['required', 'string', 'max:255'],
            'sasaran' => ['nullable', 'string', 'max:255'],
            'dasarHitung' => ['nullable', 'string'],
            'basisData' => ['nullable', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'in:Persen,Poin'],
            'metodeCapaian' => ['required', 'string', 'in:langsung,rasio'],
            'jenisIku' => ['required', 'string', 'in:iku,proksi'],
            'jenisPeriode' => ['required', 'string', 'in:triwulanan,tahunan'],
            'deskripsiX' => ['nullable', 'string', 'max:255'],
            'deskripsiY' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'kode' => 'kode',
            'kodeTujuan' => 'kode tujuan',
            'namaTujuan' => 'nama tujuan',
            'kodeSasaran' => 'kode sasaran',
            'indikator' => 'indikator',
            'tim' => 'tim',
            'penanggungJawab' => 'penanggung jawab',
            'sasaran' => 'sasaran',
            'dasarHitung' => 'dasar hitung',
            'basisData' => 'basis data',
            'satuan' => 'satuan',
            'metodeCapaian' => 'metode perhitungan',
            'jenisIku' => 'jenis IKU',
            'jenisPeriode' => 'jenis periode',
            'deskripsiX' => 'label pembilang (X)',
            'deskripsiY' => 'label penyebut (Y)',
        ];
    }

    public function downloadTemplate()
    {
        return ExcelFacade::download(new MasterIkuTemplateExport, 'template-master-iku.xlsx');
    }

    /**
     * Tahap 1/2 alur import (spek 6.3) — mengurai & memvalidasi berkas, TIDAK
     * menyimpan apa pun ke DB. Hasilnya ditampilkan sebagai pratinjau (baris valid
     * vs error) lewat $pratinjau, Tim SAKIP menekan "Konfirmasi Import" (lihat
     * konfirmasiImpor()) untuk benar-benar menyimpan.
     */
    public function pratinjauExcel(): void
    {
        $this->validate([
            'excelFile' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
            'tahunImpor' => ['required', 'integer', 'min:2000', 'max:2100'],
            'modeImpor' => ['required', 'in:insert,upsert'],
        ]);

        $import = new MasterIkuImport($this->modeImpor === 'upsert');

        ExcelFacade::import($import, $this->excelFile);

        $this->pratinjauErrorStruktural = $import->errors;
        $this->pratinjau = $import->errors ? null : $import->hasilValidasi;

        $this->reset('excelFile');
    }

    public function batalkanPratinjau(): void
    {
        $this->reset(['pratinjau', 'pratinjauErrorStruktural']);
    }

    /**
     * Tahap 2/2 alur import — menyimpan baris valid ke MasterIku + CapaianTahunan
     * (tahun $tahunImpor) dalam SATU transaksi. Bila batalkanSemuaBilaError aktif
     * DAN masih ada baris error di pratinjau, TIDAK ADA yang disimpan sama sekali
     * (rollback penuh, sesuai spek 6.4 "Import harus transaksional").
     */
    public function konfirmasiImpor(): void
    {
        if ($this->pratinjau === null) {
            return;
        }

        $baris = collect($this->pratinjau);
        $barisValid = $baris->where('valid', true);

        if ($barisValid->isEmpty()) {
            session()->flash('error', 'Tidak ada baris valid untuk disimpan.');

            return;
        }

        if ($this->batalkanSemuaBilaError && $baris->contains('valid', false)) {
            session()->flash('error', 'Import dibatalkan — masih ada baris error dan opsi "batalkan semua bila ada error" aktif. Tidak ada data yang disimpan.');

            return;
        }

        $tahun = $this->tahunImpor;

        DB::transaction(function () use ($barisValid, $tahun): void {
            foreach ($barisValid as $baris) {
                $iku = MasterIkuModel::updateOrCreate(
                    ['kode' => $baris['data']['kode']],
                    $baris['data']['master_iku']
                );

                CapaianTahunan::updateOrCreate(
                    ['iku_id' => $iku->id, 'tahun' => $tahun],
                    $baris['data']['capaian_tahunan']
                );
            }
        });

        MasterIkuModel::lupakanCache();

        session()->flash('status', "Berhasil mengimpor {$barisValid->count()} indikator untuk tahun {$tahun}.");

        $this->reset(['pratinjau', 'pratinjauErrorStruktural']);
    }

    public function edit(int $id): void
    {
        $iku = MasterIkuModel::findOrFail($id);

        $this->editingId = $iku->id;
        $this->kode = $iku->kode;
        $this->kodeTujuan = $iku->kode_tujuan ?? '';
        $this->namaTujuan = $iku->nama_tujuan ?? '';
        $this->kodeSasaran = $iku->kode_sasaran ?? '';
        $this->indikator = $iku->indikator;
        $this->tim = $iku->tim ?? '';
        $this->penanggungJawab = $iku->penanggung_jawab ?? '';
        $this->sasaran = $iku->sasaran ?? '';
        $this->dasarHitung = $iku->dasar_hitung ?? '';
        $this->basisData = $iku->basis_data ?? '';
        $this->satuan = $iku->satuan;
        $this->metodeCapaian = $iku->metode_capaian;
        $this->jenisIku = $iku->jenis_iku;
        $this->jenisPeriode = $iku->jenis_periode;
        $this->deskripsiX = $iku->deskripsi_x ?? '';
        $this->deskripsiY = $iku->deskripsi_y ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'kode', 'kodeTujuan', 'namaTujuan', 'kodeSasaran', 'indikator', 'tim', 'penanggungJawab', 'sasaran', 'dasarHitung', 'basisData', 'deskripsiX', 'deskripsiY']);
        $this->satuan = 'Persen';
        $this->metodeCapaian = 'langsung';
        $this->jenisIku = 'iku';
        $this->jenisPeriode = 'tahunan';
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            // Kode dipakai polos angka/kodenya saja (mis. "1131"), tanpa awalan "IKU-"
            // — dinormalisasi di sini juga (bukan cuma migrasi backfill data lama) supaya
            // tetap konsisten walau seseorang terbiasa mengetik "IKU-1131" di kolom Kode.
            'kode' => preg_replace('/^\D+/', '', trim($this->kode)) ?: trim($this->kode),
            'kode_tujuan' => $this->kodeTujuan ?: null,
            'nama_tujuan' => $this->namaTujuan ?: null,
            'kode_sasaran' => $this->kodeSasaran ?: null,
            'indikator' => $this->indikator,
            'tim' => $this->tim,
            'penanggung_jawab' => $this->penanggungJawab,
            'sasaran' => $this->sasaran ?: null,
            'dasar_hitung' => $this->dasarHitung ?: null,
            'basis_data' => $this->basisData ?: null,
            'satuan' => $this->satuan,
            'metode_capaian' => $this->metodeCapaian,
            'jenis_iku' => $this->jenisIku,
            'jenis_periode' => $this->jenisPeriode,
            'deskripsi_x' => $this->deskripsiX ?: null,
            'deskripsi_y' => $this->deskripsiY ?: null,
        ];

        if ($this->editingId) {
            MasterIkuModel::whereKey($this->editingId)->update($data);
        } else {
            MasterIkuModel::create($data);
        }

        MasterIkuModel::lupakanCache();

        session()->flash('status', $this->editingId ? 'IKU berhasil diperbarui.' : 'IKU baru berhasil ditambahkan.');

        $this->cancelEdit();
    }

    public function confirmDelete(int $id): void
    {
        // Dicek DI SINI, sebelum modal konfirmasi muncul — supaya Tim SAKIP langsung
        // diberi tahu KENAPA tidak bisa dihapus, bukan baru tahu setelah klik Hapus
        // dan gagal (lihat relasiYangMenghalangiHapus() untuk daftar relasinya).
        $iku = MasterIkuModel::findOrFail($id);

        $this->pendingDeleteId = $id;
        $this->alasanTidakBisaHapus = $iku->relasiYangMenghalangiHapus() ?: null;
    }

    public function cancelDelete(): void
    {
        $this->pendingDeleteId = null;
        $this->alasanTidakBisaHapus = null;
    }

    public function delete(): void
    {
        if (! $this->pendingDeleteId || $this->alasanTidakBisaHapus) {
            return;
        }

        try {
            MasterIkuModel::whereKey($this->pendingDeleteId)->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            // Jaring pengaman kalau ada relasi baru muncul di antara confirmDelete()
            // dicek dan tombol Hapus benar-benar diklik (mis. dua tab dibuka
            // bersamaan) — 23503 = foreign_key_violation (Postgres).
            if ($e->getCode() === '23503') {
                $this->pendingDeleteId = null;
                $this->alasanTidakBisaHapus = null;
                session()->flash('error', 'IKU ini tidak bisa dihapus karena masih ada data terkait yang baru saja ditambahkan. Muat ulang halaman lalu coba lagi.');

                return;
            }

            throw $e;
        }

        MasterIkuModel::lupakanCache();

        $this->pendingDeleteId = null;

        session()->flash('status', 'IKU berhasil dihapus.');
    }

    public function render()
    {
        // Satu query untuk seluruh daftar IKU, dipakai ulang di PHP untuk saran
        // datalist Kode/Tim/Sasaran DAN untuk kode IKU yang mau dihapus — bukan
        // 4 query distinct() + 1 query find() terpisah seperti sebelumnya. Halaman
        // ini sengaja TIDAK memakai cache Laravel untuk ikuList sendiri (supaya
        // perubahan sendiri selalu terlihat instan), tapi query distinct terpisah
        // untuk saran datalist itu murni pemborosan — datanya sudah ada di sini.
        $ikuList = MasterIkuModel::orderBy('kode')->get();

        $daftarPenanggungJawab = $ikuList->pluck('penanggung_jawab')->filter()
            ->merge(User::where('status_verifikasi', 'terverifikasi')->orderBy('nama')->pluck('nama'))
            ->unique()
            ->sort()
            ->values();

        $pratinjau = collect($this->pratinjau);

        return view('livewire.master-iku', [
            'ikuList' => $ikuList,
            'totalIndikator' => $ikuList->count(),
            'daftarKode' => $ikuList->pluck('kode')->filter()->unique()->sort()->values()->all(),
            'daftarTim' => $ikuList->pluck('tim')->filter()->unique()->sort()->values()->all(),
            'daftarSasaran' => $ikuList->pluck('sasaran')->filter()->unique()->sort()->values()->all(),
            'daftarPenanggungJawab' => $daftarPenanggungJawab,
            'pendingDeleteKode' => $this->pendingDeleteId
                ? $ikuList->firstWhere('id', $this->pendingDeleteId)?->kode
                : null,
            'jumlahValid' => $pratinjau->where('valid', true)->count(),
            'jumlahError' => $pratinjau->where('valid', false)->count(),
        ]);
    }
}
