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
        <label>Penanggung Jawab (Tim) <span class="req">*</span></label>
        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px">
            @forelse ($timTerpilih as $t)
                <span class="chip chip-tim" wire:key="tim-terpilih-{{ $loop->index }}">
                    {{ $t }}
                    <span class="chip-x" wire:click="hapusTimTerpilih('{{ $t }}')">✕</span>
                </span>
            @empty
                <span class="muted" style="font-size:11.5px">Belum ada tim dipilih.</span>
            @endforelse
        </div>
        <div style="display:flex;gap:6px">
            <input type="text" class="inp filled" list="daftar-tim" wire:model="timBaru"
                wire:keydown.enter.prevent="tambahTim" placeholder="mis. Produksi Statistik">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahTim">＋ Tambah</button>
        </div>
        <datalist id="daftar-tim">
            @foreach ($daftarTim as $opsi)
                <option value="{{ $opsi }}"></option>
            @endforeach
        </datalist>
        <div class="fhint">Pilih dari saran yang sudah ada, atau ketik tim baru lalu tekan Enter/"＋ Tambah". Boleh lebih dari satu tim — semuanya berlaku sebagai Penanggung Jawab IKU (ditampilkan di Daftar Master IKU) — tidak perlu isian nama orang terpisah.</div>
        @error('timTerpilih')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="field">
    <label>Sasaran</label>
    <input type="text" class="inp filled" list="daftar-sasaran" wire:model="sasaran" placeholder="mis. Statistik Kesejahteraan Rakyat">
    <datalist id="daftar-sasaran">
        @foreach ($daftarSasaran as $opsi)
            <option value="{{ $opsi }}"></option>
        @endforeach
    </datalist>
    <div class="fhint">Untuk mengelompokkan tabel "Kesiapan per Sasaran" di halaman Kompilasi Notula &amp; Dasbor Capaian. Boleh dikosongkan.</div>
    @error('sasaran')
        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
    @enderror
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
        <label>Dasar Hitung</label>
        <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="dasarHitung"
            placeholder="Rumus/cara menghitung realisasi IKU ini"></textarea>
        <div class="fhint">Ditampilkan pada baris "Dasar Hitung dan Basis Data Realisasi IKU" di Notula Bagian I. Untuk pecahan bersusun (mis. rumus persentase), ketik <code>[[pembilang|penyebut]]</code> — contoh: <code>y = [[n|N]] x 100%</code>. Tercetak sebagai pecahan bergaris di PDF; di unduhan .docx otomatis diratakan jadi "n/N".</div>
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
    <div class="field">
        <label>Satuan</label>
        {{-- Satuan SELALU mengikuti Metode Perhitungan (Rasio->Persen, Langsung->Poin
             -- dipaksakan di App\Models\MasterIku::booted()), jadi di sini murni
             TAMPILAN, bukan pilihan bebas -- supaya tidak bisa lagi terjadi
             kombinasi ganjil (mis. "Langsung" tapi berlabel "Persen"). --}}
        <div class="inp filled" style="background:var(--ro-bg);display:flex;align-items:center">
            {{ $metodeCapaian === 'rasio' ? 'Persen' : 'Poin' }}
        </div>
        <div class="fhint">Otomatis mengikuti Metode Perhitungan.</div>
    </div>
</div>

<div class="field" style="max-width:340px">
    <label>Jenis Periode <span class="req">*</span></label>
    <select class="inp filled" wire:model="jenisPeriode">
        <option value="tahunan">Tahunan</option>
        <option value="triwulanan">Triwulanan</option>
    </select>
    <div class="fhint">Sesuai kolom "Jenis (Triwulanan atau Tahunan)" Kertas Kerja resmi — murni informasional, tidak mengubah rumus Capaian Kinerja (Capaian Terhadap Target Triwulanan &amp; Setahun tetap dihitung untuk semua IKU apa pun jenis periodenya).</div>
    @error('jenisPeriode')
        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
    @enderror
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
    <div class="info" style="max-width:520px">ℹ️ Alokasi Y &amp; Realisasi X diisi lewat daftar Rincian Item (N) — otomatis aktif untuk semua IKU Rasio X÷Y, tidak perlu diatur di sini. Daftar item dikelola di tab 🎯 Target Tahunan (dibuat sekali di awal tahun); tiap triwulan tinggal memilih item mana yang sudah direalisasikan di Verifikasi Capaian.</div>
@elseif ($metodeCapaian === 'langsung' && $this->isTimSakip())
    <div class="field">
        <label>Rumus Capaian Kustom (lanjutan) <span class="muted" style="font-weight:400">— opsional, khusus Tim SAKIP</span></label>
        <textarea class="inp filled" rows="2" wire:model="formulaCapaian" placeholder="mis. min(realisasi / alokasi * 100, batas) — kosongkan untuk memakai rumus baku"></textarea>
        <div class="fhint">Menggantikan rumus baku (Realisasi ÷ Alokasi × 100, dibatasi sesuai <a wire:navigate href="{{ route('master-iku.index') }}#rumus">🧮 Pengaturan Rumus Capaian</a>) untuk IKU Non % ini SAJA. Variabel yang tersedia: <code>alokasi</code>, <code>realisasi</code>, <code>batas</code>. Kosongkan untuk tetap memakai rumus baku.</div>
        @error('formulaCapaian')
            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
        @enderror
    </div>
@endif
