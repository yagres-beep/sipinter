{{--
    Badge nomor versi notula — ditampilkan berdampingan dengan <x-badge-status /> di
    layar Kompilasi Notula, Persetujuan Notula, dan daftar Notula.

    Notula yang sudah disetujui Kepala bisa ditarik lagi ke draft sebagai VERSI BARU
    begitu ada isian/bagian yang berubah (lihat App\Models\Notula::bukaVersiBaru()) —
    badge ini yang membuat "ini dokumen versi ke berapa" terbaca langsung di layar,
    sama dengan akhiran "-v2" pada nama berkas unduhannya (Notula::namaUnduhan()).

    Pemakaian: <x-badge-versi :versi="$notula->versiSaatIni()" />
--}}
@props(['versi'])

<span {{ $attributes->merge(['class' => 'badge '.((int) $versi > 1 ? 'b-ajukan' : 'b-draft')]) }}
    title="Versi dokumen notula — naik satu setiap kali notula yang sudah disetujui dibuka kembali karena ada data yang berubah">Versi {{ (int) $versi }}</span>
