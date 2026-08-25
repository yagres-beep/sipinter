<div class="row2">
    <div class="field">
        <label>Kode <span class="req">*</span></label>
        <input type="text" class="inp filled" list="daftar-kode" wire:model="kode" placeholder="mis. 1131">
        <datalist id="daftar-kode">
            @foreach ($daftarKode as $opsi)
                <option value="{{ $opsi }}"></option>
            @endforeach
        </datalist>
        <div class="fhint">Pilih dari saran kode yang sudah ada, atau ketik kode baru. Cukup angka/kodenya saja, tanpa awalan "IKU-".</div>
        @error('kode')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
    <div class="field">
        <label>Tim <span class="req">*</span></label>
        <input type="text" class="inp filled" list="daftar-tim" wire:model="tim" placeholder="mis. Produksi Statistik">
        <datalist id="daftar-tim">
            @foreach ($daftarTim as $opsi)
                <option value="{{ $opsi }}"></option>
            @endforeach
        </datalist>
        <div class="fhint">Pilih dari saran yang sudah ada, atau ketik tim baru.</div>
        @error('tim')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="field">
    <label>Indikator <span class="req">*</span></label>
    <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="indikator"
        placeholder="mis. Persentase publikasi tepat waktu"></textarea>
    @error('indikator')
        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
    @enderror
</div>

<div class="row2">
    <div class="field">
        <label>Penanggung Jawab <span class="req">*</span></label>
        <input type="text" class="inp filled" list="daftar-pj" wire:model="penanggungJawab" placeholder="Nama petugas/pejabat">
        <datalist id="daftar-pj">
            @foreach ($daftarPenanggungJawab as $opsi)
                <option value="{{ $opsi }}"></option>
            @endforeach
        </datalist>
        <div class="fhint">Saran diambil dari pengguna terverifikasi &amp; isian sebelumnya, atau ketik nama lain.</div>
        @error('penanggungJawab')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
    <div class="field">
        <label>Sasaran</label>
        <input type="text" class="inp filled" list="daftar-sasaran" wire:model="sasaran" placeholder="mis. Statistik Kesejahteraan Rakyat">
        <datalist id="daftar-sasaran">
            @foreach ($daftarSasaran as $opsi)
                <option value="{{ $opsi }}"></option>
            @endforeach
        </datalist>
        <div class="fhint">Untuk mengelompokkan tabel "Kesiapan per Sasaran" di halaman Kompilasi Notula. Boleh dikosongkan.</div>
        @error('sasaran')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="row2">
    <div class="field">
        <label>Dasar Hitung</label>
        <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="dasarHitung"
            placeholder="Rumus/cara menghitung realisasi IKU ini"></textarea>
        <div class="fhint">Ditampilkan pada baris "Dasar Hitung dan Basis Data Realisasi IKU" di Notula Bagian I.</div>
        @error('dasarHitung')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
    <div class="field">
        <label>Basis Data</label>
        <input type="text" class="inp filled" wire:model="basisData" placeholder="mis. Data internal BPS, hasil survei">
        @error('basisData')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="row2">
    <div class="field">
        <label>Satuan <span class="req">*</span></label>
        <select class="inp filled" wire:model="satuan">
            <option value="Persen">Persen</option>
            <option value="Poin">Poin</option>
        </select>
        <div class="fhint">Satuan target/realisasi IKU ini — hanya label tampilan di form Verifikasi Capaian, tidak mengubah rumus capaian.</div>
        @error('satuan')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
    <div class="field">
        <label>Metode Perhitungan <span class="req">*</span></label>
        <select class="inp filled" wire:model.live="metodeCapaian">
            <option value="langsung">Langsung (nilai apa adanya)</option>
            <option value="rasio">Rasio X÷Y (persentase dari dua angka)</option>
        </select>
        <div class="fhint">"Rasio X÷Y" untuk IKU bertipe % sesuai Kertas Kerja Pengukuran Kinerja Triwulanan — Alokasi Target/Realisasi diisi lewat Pembilang (X)/Penyebut (Y), persentasenya dihitung otomatis. "Langsung" untuk IKU bertipe Non % (diisi apa adanya).</div>
        @error('metodeCapaian')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="row2">
    <div class="field">
        <label>Jenis IKU <span class="req">*</span></label>
        <select class="inp filled" wire:model="jenisIku">
            <option value="iku">IKU</option>
            <option value="proksi">Proksi</option>
        </select>
        <div class="fhint">Sesuai kolom "Jenis (IKU atau Proksi)" Kertas Kerja resmi. "Proksi" DIKECUALIKAN dari Total/Rata-rata Capaian PK di Penilaian Kinerja Organisasi (PKO) — indikator pengganti/pendekatan sementara, bukan indikator inti yang dinilai penuh.</div>
        @error('jenisIku')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
    <div class="field">
        <label>Jenis Periode <span class="req">*</span></label>
        <select class="inp filled" wire:model="jenisPeriode">
            <option value="tahunan">Tahunan</option>
            <option value="triwulanan">Triwulanan</option>
        </select>
        <div class="fhint">"Triwulanan" untuk IKU yang targetnya ditetapkan per-triwulan — basis Normalisasi Capaian PK di PKO memakai Capaian Terhadap Target Triwulanan pada triwulan berjalan (TW IV tetap memakai Capaian Setahun). "Tahunan" (default) selalu memakai Capaian Setahun TW IV.</div>
        @error('jenisPeriode')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
</div>

@if ($metodeCapaian === 'rasio')
    <div class="row2">
        <div class="field">
            <label>Label Pembilang (X)</label>
            <input type="text" class="inp filled" wire:model="deskripsiX" placeholder="mis. Jumlah Publikasi berkualitas">
            @error('deskripsiX')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>
        <div class="field">
            <label>Label Penyebut (Y)</label>
            <input type="text" class="inp filled" wire:model="deskripsiY" placeholder="mis. Jumlah seluruh Publikasi">
            @error('deskripsiY')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>
    </div>
@endif
