<?php

namespace App\Livewire;

use App\Models\Capaian;
use App\Models\Kegiatan;
use App\Models\KendalaSolusi;
use App\Models\MasterIku;
use App\Models\RtlEvaluasi;
use App\Models\StorageAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Dasbor ringkas (RF-47) — dibagikan ketiga peran, satu tempat memantau status
 * pengisian & capaian secara menyeluruh. Tabel "Status Pengisian per IKU" bisa
 * dicari, difilter triwulan/bulan, diurutkan per kolom, dan tiap baris bisa
 * diklik menuju halaman yang relevan sesuai peran yang sedang login.
 */
class DasborUtama extends Component
{
    public string $cari = '';

    public string $filterTriwulan = '';

    public string $filterBulan = '';

    public string $urutanKolom = 'periode';

    public string $urutanArah = 'desc';

    public function urutkan(string $kolom): void
    {
        if ($this->urutanKolom === $kolom) {
            $this->urutanArah = $this->urutanArah === 'asc' ? 'desc' : 'asc';
        } else {
            $this->urutanKolom = $kolom;
            $this->urutanArah = 'asc';
        }
    }

    public function updatedFilterTriwulan(): void
    {
        $this->filterBulan = '';
    }

    /**
     * Sinkron dua arah: begitu bulan dipilih langsung (tanpa lebih dulu memilih
     * triwulan), triwulan ikut menyesuaikan supaya daftar bulan yang tampil tetap
     * konsisten dengan triwulan yang sedang aktif.
     */
    public function updatedFilterBulan(): void
    {
        if (filled($this->filterBulan)) {
            $this->filterTriwulan = (string) (int) ceil(((int) $this->filterBulan) / 3);
        }
    }

    public function resetFilter(): void
    {
        $this->cari = '';
        $this->filterTriwulan = '';
        $this->filterBulan = '';
        $this->urutanKolom = 'periode';
        $this->urutanArah = 'desc';
    }

    /**
     * IKU yang SAMA SEKALI belum punya kegiatan pada triwulan berjalan (tahun & triwulan
     * saat ini) — bukan pemblokiran (satu IKU boleh saja tidak diisi tiap bulan), hanya
     * pengingat visual karena tiap IKU tetap diharapkan terisi minimal sekali per triwulan.
     *
     * @return Collection<int, MasterIku>
     */
    protected function ikuBelumTerisiTriwulanIni()
    {
        $triwulanIni = (int) ceil(now()->month / 3);
        $tahunIni = now()->year;

        $ikuSudahTerisi = Kegiatan::whereHas(
            'periode',
            fn ($q) => $q->where('tahun', $tahunIni)->where('triwulan', $triwulanIni)
        )->pluck('iku_id')->unique();

        return MasterIku::whereNotIn('id', $ikuSudahTerisi)->orderBy('kode')->get();
    }

    protected function ringkasan(): array
    {
        $lewatTenggat = RtlEvaluasi::whereNotNull('batas_waktu')
            ->where('batas_waktu', '<', now())
            ->where(fn ($q) => $q->whereNull('realisasi')->orWhere('realisasi', ''))
            ->count();

        // Satu query dengan agregat bersyarat (FILTER, sintaks Postgres) alih-alih dua
        // query Capaian::count() terpisah untuk status yang berbeda. Dihitung dari
        // Capaian::status (satu status per IKU+bulan) bukan lagi Kegiatan::status_dokumen
        // — supaya "2 sudah diverifikasi" berarti 2 ISIAN (IKU+bulan), bukan 2 baris
        // kegiatan mentah yang bisa menghitung satu isian berkali-kali.
        $statusCapaian = Capaian::selectRaw(
            "count(*) filter (where status in ('diverifikasi','disetujui')) as sudah_diverifikasi,
             count(*) filter (where status in ('diajukan','sedang_ditangani')) as menunggu_verifikasi"
        )->first();

        return [
            'total_iku' => MasterIku::count(),
            'sudah_diverifikasi' => (int) $statusCapaian->sudah_diverifikasi,
            'menunggu_verifikasi' => (int) $statusCapaian->menunggu_verifikasi,
            'lewat_tenggat' => $lewatTenggat,
        ];
    }

    /**
     * Query + filter + sort didorong seluruhnya ke database (JOIN ke master_iku &amp; periode)
     * alih-alih memuat semua baris ke memori lalu memfilter/mengurutkan lewat Collection —
     * supaya pencarian dan sort tetap cepat walau jumlah isian terus bertambah.
     *
     * SATU baris = SATU Capaian (IKU+bulan, lihat App\Models\Capaian), bukan lagi satu
     * baris per Kegiatan — sebelumnya satu IKU+bulan bisa tampil berkali-kali di tabel
     * ini dengan status berbeda-beda tiap barisnya (mis. "Dikembalikan" dua kali lalu
     * "Diverifikasi" dua kali) karena tiap Kegiatan punya status_dokumen sendiri;
     * sekarang statusnya SATU per IKU+bulan (Capaian::status), jadi satu baris cukup.
     *
     * Status "draft" SENGAJA disembunyikan dari Tim SAKIP — isian itu milik Ketua
     * Tim yang belum pernah diajukan sama sekali (lihat Capaian::STATUS_DRAFT), jadi
     * Tim SAKIP tidak perlu (dan tidak boleh) melihatnya sampai benar-benar diajukan.
     * Peran lain tetap melihat baris draft milik mereka sendiri (Ketua Tim perlu
     * lihat draftnya sendiri di dasbor).
     *
     * @return Collection<int, Capaian>
     */
    protected function daftarCapaian(string $role)
    {
        $query = Capaian::query()
            ->join('master_iku', 'capaian.iku_id', '=', 'master_iku.id')
            ->join('periode', 'capaian.periode_id', '=', 'periode.id')
            ->select('capaian.*')
            ->with(['masterIku.timList', 'periode']);

        if ($role === 'Tim SAKIP') {
            $query->where('capaian.status', '!=', Capaian::STATUS_DRAFT);
        }

        if (filled($this->filterTriwulan)) {
            $query->where('periode.triwulan', $this->filterTriwulan);
        }

        if (filled($this->filterBulan)) {
            $query->where('periode.bulan', $this->filterBulan);
        }

        if (filled($this->cari)) {
            // LOWER()+LIKE (bukan ILIKE) supaya query yang sama jalan baik di Postgres
            // (production) maupun SQLite (tests, lihat phpunit.xml).
            $kataKunci = '%'.mb_strtolower($this->cari).'%';

            $query->where(function ($q) use ($kataKunci) {
                $q->whereRaw('LOWER(master_iku.kode) LIKE ?', [$kataKunci])
                    ->orWhereRaw('LOWER(master_iku.indikator) LIKE ?', [$kataKunci])
                    // Satu IKU boleh punya lebih dari satu tim (App\Models\IkuTim) --
                    // dicocokkan lewat EXISTS ke iku_tim, bukan lagi kolom scalar
                    // master_iku.tim.
                    ->orWhereExists(function ($sub) use ($kataKunci) {
                        $sub->select(DB::raw(1))
                            ->from('iku_tim')
                            ->whereColumn('iku_tim.iku_id', 'master_iku.id')
                            ->whereRaw('LOWER(iku_tim.tim) LIKE ?', [$kataKunci]);
                    });
            });
        }

        if ($this->urutanKolom === 'tim') {
            // Diurutkan dari tim "terkecil" (alfabet) milik tiap IKU -- portable di
            // Postgres (production) & SQLite (tests), MIN() berlaku sama di keduanya.
            $query->orderByRaw(
                '(select min(tim) from iku_tim where iku_tim.iku_id = master_iku.id) '.$this->urutanArah
            );

            return $query->take(20)->get();
        }

        $kolomUrut = match ($this->urutanKolom) {
            'kode' => 'master_iku.kode',
            'indikator' => 'master_iku.indikator',
            'triwulan' => 'periode.triwulan',
            'status' => 'capaian.status',
            default => 'periode.tahun',
        };

        $query->orderBy($kolomUrut, $this->urutanArah);

        if ($kolomUrut === 'periode.tahun') {
            $query->orderBy('periode.bulan', $this->urutanArah);
        }

        return $query->take(20)->get();
    }

    /**
     * Kegiatan pendukung per Capaian yang tampil di halaman ini, SATU query untuk
     * seluruh daftar (bukan N+1 lewat $capaian->kegiatanList() per baris) — dipakai
     * untuk turunkan jumlah MAUPUN rincian status kegiatan (lihat Kegiatan::rincianStatus()),
     * supaya campuran status dalam satu Capaian (mis. "3 diverifikasi, 2 dikembalikan")
     * tetap terlihat di tabel ini tanpa perlu buka halaman lain.
     *
     * @param  Collection<int, Capaian>  $daftarCapaian
     * @return Collection<int, Collection<int, Kegiatan>>
     */
    protected function kegiatanPerCapaian($daftarCapaian)
    {
        if ($daftarCapaian->isEmpty()) {
            return collect();
        }

        $perPasangan = Kegiatan::whereIn('iku_id', $daftarCapaian->pluck('iku_id'))
            ->whereIn('periode_id', $daftarCapaian->pluck('periode_id'))
            ->get(['iku_id', 'periode_id', 'status_dokumen'])
            ->groupBy(fn ($k) => $k->iku_id.'-'.$k->periode_id);

        return $daftarCapaian->mapWithKeys(
            fn ($c) => [$c->id => $perPasangan->get($c->iku_id.'-'.$c->periode_id, collect())]
        );
    }

    /**
     * Kendala &amp; Solusi per Capaian yang tampil di halaman ini, SATU query untuk
     * seluruh daftar — pola sama persis dengan kegiatanPerCapaian() di atas, dipakai
     * SEKALIGUS untuk turunkan total "item" MAUPUN rincian statusnya (lihat
     * KendalaSolusi::rincianStatusVerifikasi()). Sebelumnya kolom "Kegiatan" &amp;
     * rincian statusnya di tabel ini hanya menghitung Kegiatan — Kendala &amp; Solusi
     * yang ditolak Tim SAKIP tidak kelihatan sama sekali di sini, cuma lewat badge
     * besar Capaian::status.
     *
     * @param  Collection<int, Capaian>  $daftarCapaian
     * @return Collection<int, Collection<int, KendalaSolusi>>
     */
    protected function kendalaSolusiPerCapaian($daftarCapaian)
    {
        if ($daftarCapaian->isEmpty()) {
            return collect();
        }

        $perPasangan = KendalaSolusi::whereIn('iku_id', $daftarCapaian->pluck('iku_id'))
            ->whereIn('periode_id', $daftarCapaian->pluck('periode_id'))
            ->get(['iku_id', 'periode_id', 'status_verifikasi'])
            ->groupBy(fn ($k) => $k->iku_id.'-'.$k->periode_id);

        return $daftarCapaian->mapWithKeys(
            fn ($c) => [$c->id => $perPasangan->get($c->iku_id.'-'.$c->periode_id, collect())]
        );
    }

    /**
     * Poin RTL evaluasi triwulan berjalan per Capaian, SATU query untuk seluruh
     * daftar (sama polanya dengan kegiatanPerCapaian() di atas) — dipakai supaya
     * badge status besar Capaian (mis. "Dikembalikan") tetap bisa ditelusuri sampai
     * ke bukti RTL yang jadi penyebabnya, bukan hanya bukti Kegiatan (lihat
     * RtlEvaluasi::rincianStatusVerifikasi()).
     *
     * Dicocokkan lewat (iku_id, tahun, triwulan) — BUKAN periode_id — karena poin RTL
     * dievaluasi pada triwulan yang sama dengan Capaian tapi bisa saja tersimpan di
     * baris Periode (bulan) yang berbeda dalam triwulan itu (sama seperti
     * App\Livewire\VerifikasiCapaian::rtlEvaluasiSebelumnya()). Hanya poin yang
     * realisasinya sudah dilaporkan Ketua Tim yang disertakan — poin yang belum
     * dilaporkan tidak relevan dengan status verifikasi apa pun.
     *
     * @param  Collection<int, Capaian>  $daftarCapaian
     * @return Collection<int, Collection<int, RtlEvaluasi>>
     */
    protected function rtlPerCapaian($daftarCapaian)
    {
        if ($daftarCapaian->isEmpty()) {
            return collect();
        }

        $kunciPasangan = fn ($ikuId, $tahun, $triwulan) => "{$ikuId}-{$tahun}-{$triwulan}";

        $pasanganDicari = $daftarCapaian->map(
            fn ($c) => $kunciPasangan($c->iku_id, $c->periode->tahun, $c->periode->triwulan)
        )->unique();

        $perPasangan = RtlEvaluasi::whereIn('iku_id', $daftarCapaian->pluck('iku_id'))
            ->whereNotNull('realisasi')
            ->where('realisasi', '!=', '')
            ->with('periode:id,tahun,triwulan')
            ->get(['id', 'iku_id', 'periode_id', 'realisasi', 'status_verifikasi'])
            ->filter(fn ($r) => $pasanganDicari->contains($kunciPasangan($r->iku_id, $r->periode->tahun, $r->periode->triwulan)))
            ->groupBy(fn ($r) => $kunciPasangan($r->iku_id, $r->periode->tahun, $r->periode->triwulan));

        return $daftarCapaian->mapWithKeys(
            fn ($c) => [$c->id => $perPasangan->get($kunciPasangan($c->iku_id, $c->periode->tahun, $c->periode->triwulan), collect())]
        );
    }

    /**
     * Poin RTL BARU yang ditetapkan Ketua Tim PADA triwulan Capaian ini untuk
     * dilaksanakan pada triwulan BERIKUTNYA (Bagian 5 "Rencana Tindak Lanjut", lihat
     * PengisianKegiatan::ajukanIsian() langkah 4 &amp; VerifikasiCapaian::
     * rtlBerikutnyaBaruDitetapkan()) — BEDA dari rtlPerCapaian() di atas yang
     * mengambil evaluasi/realisasi RTL yang ditetapkan triwulan SEBELUMNYA. Poin
     * BARU ini tersimpan di baris Periode triwulan berikutnya (bukan periode_id
     * Capaian ini), jadi harus dicocokkan lewat (iku_id, tahun, triwulan) sasaran —
     * sebelumnya sama sekali tidak ikut terhitung di kolom "Item"/rincian dasbor,
     * padahal sudah bisa diverifikasi Tim SAKIP (status_verifikasi) sejak diajukan.
     *
     * Tidak difilter status_dokumen — sama seperti kegiatanPerCapaian() &amp;
     * kendalaSolusiPerCapaian(), baris draft Ketua Tim sendiri tetap ikut terhitung
     * (Tim SAKIP tidak akan melihatnya karena Capaian berstatus draft sudah disaring
     * lebih dulu di daftarCapaian()).
     *
     * @param  Collection<int, Capaian>  $daftarCapaian
     * @return Collection<int, Collection<int, RtlEvaluasi>>
     */
    protected function rtlBerikutnyaPerCapaian($daftarCapaian)
    {
        if ($daftarCapaian->isEmpty()) {
            return collect();
        }

        $kunciPasangan = fn ($ikuId, $tahun, $triwulan) => "{$ikuId}-{$tahun}-{$triwulan}";

        $sasaran = $daftarCapaian->mapWithKeys(function ($c) use ($kunciPasangan) {
            $triwulanBerikutnya = $c->periode->triwulan === 4 ? 1 : $c->periode->triwulan + 1;
            $tahunBerikutnya = $c->periode->triwulan === 4 ? $c->periode->tahun + 1 : $c->periode->tahun;

            return [$c->id => $kunciPasangan($c->iku_id, $tahunBerikutnya, $triwulanBerikutnya)];
        });

        $perPasangan = RtlEvaluasi::whereIn('iku_id', $daftarCapaian->pluck('iku_id'))
            ->with('periode:id,tahun,triwulan')
            ->get(['id', 'iku_id', 'periode_id', 'status_verifikasi'])
            ->filter(fn ($r) => $sasaran->contains($kunciPasangan($r->iku_id, $r->periode->tahun, $r->periode->triwulan)))
            ->groupBy(fn ($r) => $kunciPasangan($r->iku_id, $r->periode->tahun, $r->periode->triwulan));

        return $daftarCapaian->mapWithKeys(
            fn ($c) => [$c->id => $perPasangan->get($sasaran->get($c->id), collect())]
        );
    }

    /**
     * Tautan baris sesuai peran yang login — Tim SAKIP menuju detail verifikasi
     * langsung (baris SUDAH berupa Capaian, tidak perlu lagi dicocokkan lewat query
     * terpisah seperti sebelumnya), Ketua Tim &amp; Kepala menuju halaman kerja utama
     * mereka (tidak ada halaman detail per Capaian untuk kedua peran ini).
     *
     * @param  Collection<int, Capaian>  $daftarCapaian
     * @return Collection<int, ?string>
     */
    protected function tautanSemuaBaris($daftarCapaian, string $role)
    {
        if ($role === 'Ketua Tim') {
            return $daftarCapaian->mapWithKeys(fn ($c) => [$c->id => route('pengisian.index', [
                'iku_id' => $c->iku_id,
                'tahun' => $c->periode->tahun,
                'bulan' => $c->periode->bulan,
            ])]);
        }

        if ($role === 'Kepala') {
            return $daftarCapaian->mapWithKeys(fn ($c) => [$c->id => route('persetujuan.index')]);
        }

        if ($role === 'Tim SAKIP') {
            return $daftarCapaian->mapWithKeys(fn ($c) => [$c->id => route('verifikasi.show', $c)]);
        }

        return $daftarCapaian->mapWithKeys(fn ($c) => [$c->id => null]);
    }

    /**
     * Peringatan storage Drive untuk akun AKTIF, ditampilkan di dasbor supaya Tim SAKIP
     * segera tahu tanpa harus membuka halaman Akun & Storage lebih dulu — sebelumnya
     * status ini (sesi rusak/belum terhubung/kuota hampir penuh) hanya kelihatan di
     * halaman itu sendiri, jadi gampang tidak disadari sampai unggahan mulai gagal.
     */
    protected function peringatanStorage(): ?string
    {
        $akunAktif = StorageAccount::aktif();

        if (! $akunAktif) {
            return 'Belum ada akun storage yang dijadikan aktif — unggahan bukti dukung akan gagal. Buka menu Akun & Storage untuk menambah/menetapkannya.';
        }

        if ($akunAktif->googlePerluHubungUlang()) {
            return "Sesi Google Drive akun aktif ({$akunAktif->email_gmail_institusi}) rusak/kedaluwarsa — unggahan bukti dukung sedang MATI. Hubungkan ulang lewat menu Akun & Storage.";
        }

        if ($akunAktif->mendekatiPenuh()) {
            return "Kuota Drive akun aktif ({$akunAktif->email_gmail_institusi}) sudah {$akunAktif->persentaseTerpakai()}% terpakai — siapkan akun institusi berikutnya lewat menu Akun & Storage.";
        }

        return null;
    }

    public function render()
    {
        $role = auth()->user()->namaRole();
        $daftarCapaian = $this->daftarCapaian($role);
        $kegiatanPerCapaian = $this->kegiatanPerCapaian($daftarCapaian);
        $kendalaSolusiPerCapaian = $this->kendalaSolusiPerCapaian($daftarCapaian);
        $rtlPerCapaian = $this->rtlPerCapaian($daftarCapaian);
        $rtlBerikutnyaPerCapaian = $this->rtlBerikutnyaPerCapaian($daftarCapaian);

        // "Item" = seluruh jenis isian pendukung satu Capaian (Kegiatan + Kendala &
        // Solusi + evaluasi RTL triwulan sebelumnya + RTL BARU triwulan berikutnya),
        // BUKAN cuma Kegiatan seperti sebelumnya — supaya "8 item diajukan" (mis.
        // 2 kegiatan + 3 kendala&solusi + 3 RTL) tetap tertotal utuh di kolom ini
        // walau salah satu jenisnya nanti ditolak Tim SAKIP.
        $jumlahItem = $daftarCapaian->mapWithKeys(fn ($c) => [$c->id => (
            $kegiatanPerCapaian->get($c->id, collect())->count()
            + $kendalaSolusiPerCapaian->get($c->id, collect())->count()
            + $rtlPerCapaian->get($c->id, collect())->count()
            + $rtlBerikutnyaPerCapaian->get($c->id, collect())->count()
        )]);

        return view('livewire.dasbor-utama', [
            'role' => $role,
            'ringkasan' => $this->ringkasan(),
            'daftarCapaian' => $daftarCapaian,
            'timPerCapaian' => Capaian::timTampilBanyak($daftarCapaian),
            'jumlahItem' => $jumlahItem,
            'rincianStatusKegiatan' => $kegiatanPerCapaian->map(fn ($g) => Kegiatan::rincianStatus($g)),
            'rincianStatusKendala' => $kendalaSolusiPerCapaian->map(fn ($g) => KendalaSolusi::rincianStatusVerifikasi($g)),
            'rincianStatusRtl' => $rtlPerCapaian->map(fn ($g) => RtlEvaluasi::rincianStatusVerifikasi($g)),
            'rincianStatusRtlBerikutnya' => $rtlBerikutnyaPerCapaian->map(fn ($g) => RtlEvaluasi::rincianStatusVerifikasi($g)),
            'tautanBaris' => $this->tautanSemuaBaris($daftarCapaian, $role),
            'ikuBelumTerisiTriwulanIni' => $this->ikuBelumTerisiTriwulanIni(),
            'triwulanBerjalan' => (int) ceil(now()->month / 3),
            'peringatanStorage' => $role === 'Tim SAKIP' ? $this->peringatanStorage() : null,
        ]);
    }
}
