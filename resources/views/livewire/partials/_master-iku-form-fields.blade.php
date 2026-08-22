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
