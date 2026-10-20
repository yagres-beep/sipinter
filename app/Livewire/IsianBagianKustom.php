<?php

namespace App\Livewire;

use App\Models\BagianKustom;
use App\Models\BagianKustomPoin;
use App\Models\MasterIku;
use App\Models\Periode;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Isian bagian kustom untuk peran selain Ketua Tim (Tim SAKIP & Kepala) — dipilih per
 * tahun & bulan, lalu per bagian diisi beberapa poin (tiap poin = satu IKU + uraian +
 * kolom tambahan). Bagian mana saja yang tampil diatur di Data Master > Bagian Kustom.
 */
class IsianBagianKustom extends Component
{
    /** Kunci menu tempat komponen ini dipasang (lihat App\Support\MenuBagianKustom). */
    public string $menu = 'isian_bagian_kustom';

    public int $tahun;

    public int $bulan;

    /**
     * Baris poin per bagian, dikunci pada id bagian: [id, iku_id, teks, kolom].
     *
     * @var array<int, array<int, array{id: ?int, iku_id: ?string, teks: string, kolom: array<string, string>}>>
     */
    public array $baris = [];

    public function mount(string $menu = 'isian_bagian_kustom'): void
    {
        $this->menu = $menu;
        $this->tahun = (int) now()->year;
        $this->bulan = (int) now()->month;

        $this->muat();
    }

    public function updatedTahun(): void
    {
        $this->muat();
    }

    public function updatedBulan(): void
    {
        $this->muat();
    }

    protected function peran(): ?string
    {
        return BagianKustom::peranDariNama(auth()->user()?->namaRole());
    }

    /**
     * Bagian yang tampil untuk peran yang login di menu ini. Kosong bila peran tidak
     * punya akses ke menu ini.
     *
     * @return \Illuminate\Support\Collection<int, BagianKustom>
     */
    protected function bagianUntukSaya()
    {
        $peran = $this->peran();

        if ($peran === null || ! in_array($peran, \App\Support\MenuBagianKustom::peranDiizinkan($this->menu), true)) {
            return collect();
        }

        return BagianKustom::daftarUntuk($peran, $this->menu);
    }

    protected function periode(): ?Periode
    {
        return Periode::where('tahun', $this->tahun)->where('bulan', $this->bulan)->first();
    }

    protected function barisKosong(BagianKustom $bagian): array
    {
        return ['id' => null, 'iku_id' => null, 'teks' => '', 'kolom' => $bagian->nilaiKolomLengkap([])];
    }

    protected function terisi(array $row): bool
    {
        return trim((string) $row['teks']) !== ''
            || collect($row['kolom'] ?? [])->contains(fn ($v) => trim((string) $v) !== '');
    }

    public function muat(): void
    {
        $periode = $this->periode();
        $this->baris = [];

        foreach ($this->bagianUntukSaya() as $bagian) {
            $daftar = $periode
                ? BagianKustomPoin::where('bagian_kustom_id', $bagian->id)->where('periode_id', $periode->id)->orderBy('id')->get()
                : collect();

            $this->baris[$bagian->id] = $daftar->isEmpty()
                ? [$this->barisKosong($bagian)]
                : $daftar->map(fn (BagianKustomPoin $p) => [
                    'id' => $p->id,
                    'iku_id' => (string) $p->iku_id,
                    'teks' => (string) $p->teks,
                    'kolom' => $bagian->nilaiKolomLengkap($p->nilai_kolom),
                ])->values()->all();
        }
    }

    public function tambahBaris(int $bagianId): void
    {
        $bagian = $this->bagianUntukSaya()->firstWhere('id', $bagianId);

        if ($bagian) {
            $this->baris[$bagianId][] = $this->barisKosong($bagian);
        }
    }

    public function hapusBaris(int $bagianId, int $index): void
    {
        unset($this->baris[$bagianId][$index]);
        $this->baris[$bagianId] = array_values($this->baris[$bagianId]) ?: [$this->barisKosong($this->bagianUntukSaya()->firstWhere('id', $bagianId))];
    }

    /**
     * Simpan satu bagian. Baris yang kosong tidak disimpan; baris yang dihapus dari
     * daftar ikut dihapus dari database (kecuali yang sudah punya bukti dukung).
     */
    public function simpan(int $bagianId): void
    {
        $bagian = $this->bagianUntukSaya()->firstWhere('id', $bagianId);
        abort_unless($bagian, 403);

        $this->resetErrorBag();

        $periode = $this->periode();

        if (! $periode) {
            $this->addError("baris.{$bagianId}", 'Periode bulan ini belum tersedia di sistem — hubungi admin.');

            return;
        }

        $valid = true;

        foreach ($this->baris[$bagianId] ?? [] as $i => $row) {
            if (! $this->terisi($row)) {
                continue;
            }

            if (blank($row['iku_id']) || ! MasterIku::whereKey($row['iku_id'])->exists()) {
                $this->addError("baris.{$bagianId}.{$i}.iku_id", 'Pilih IKU untuk poin ini.');
                $valid = false;
            }

            if (trim((string) $row['teks']) === '') {
                $this->addError("baris.{$bagianId}.{$i}.teks", 'Isi '.mb_strtolower($bagian->labelUraianEfektif()).' untuk poin ini.');
                $valid = false;
            }

            foreach ($bagian->kolomList() as $kolom) {
                $nilai = trim((string) ($row['kolom'][$kolom['kunci']] ?? ''));
                $kunciError = "baris.{$bagianId}.{$i}.kolom.{$kolom['kunci']}";

                if ($nilai === '' && $kolom['wajib']) {
                    $this->addError($kunciError, "Kolom \"{$kolom['label']}\" wajib diisi.");
                    $valid = false;
                } elseif ($nilai !== '' && $kolom['tipe'] === 'pilihan' && ! in_array($nilai, $kolom['opsi'], true)) {
                    $this->addError($kunciError, "Pilih salah satu opsi untuk \"{$kolom['label']}\".");
                    $valid = false;
                } elseif ($nilai !== '' && $kolom['tipe'] === 'angka' && ! is_numeric(str_replace(',', '.', $nilai))) {
                    $this->addError($kunciError, "\"{$kolom['label']}\" harus berupa angka.");
                    $valid = false;
                }
            }
        }

        if (! $valid) {
            return;
        }

        DB::transaction(function () use ($bagian, $bagianId, $periode) {
            $idDisimpan = [];

            foreach ($this->baris[$bagianId] as $row) {
                if (! $this->terisi($row)) {
                    continue;
                }

                $data = [
                    'iku_id' => (int) $row['iku_id'],
                    'teks' => trim((string) $row['teks']),
                    'nilai_kolom' => $bagian->bersihkanNilaiKolom($row['kolom']),
                ];

                $poin = $row['id']
                    ? BagianKustomPoin::where('bagian_kustom_id', $bagian->id)->where('periode_id', $periode->id)->whereKey($row['id'])->first()
                    : null;

                if ($poin) {
                    $poin->update($data);
                } else {
                    $poin = BagianKustomPoin::create($data + [
                        'bagian_kustom_id' => $bagian->id,
                        'periode_id' => $periode->id,
                        // Tidak ada alur verifikasi untuk peran ini — langsung dianggap sah.
                        'status_verifikasi' => 'terverifikasi',
                    ]);
                }

                $idDisimpan[] = $poin->id;
            }

            BagianKustomPoin::where('bagian_kustom_id', $bagian->id)
                ->where('periode_id', $periode->id)
                ->whereNotIn('id', $idDisimpan)
                ->whereDoesntHave('berkas')
                ->delete();
        });

        $this->muat();
        session()->flash('status-'.$bagianId, 'Isian "'.$bagian->nama.'" tersimpan.');
    }

    public function render()
    {
        $bulanNama = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return view('livewire.isian-bagian-kustom', [
            'bagianList' => $this->bagianUntukSaya(),
            'ikuList' => MasterIku::daftarUrutKode(),
            'periodeAda' => $this->periode() !== null,
            'namaBulan' => $bulanNama,
            'namaPeran' => BagianKustom::NAMA_PERAN[$this->peran()] ?? '',
            'namaMenu' => \App\Support\MenuBagianKustom::label($this->menu),            'tipeKolom' => BagianKustom::TIPE_KOLOM,
            'tahunPilihan' => range((int) now()->year - 2, (int) now()->year + 1),
        ]);
    }
}
