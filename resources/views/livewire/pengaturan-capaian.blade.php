<div>
    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="sec"><span>Rumus Capaian Kinerja Triwulanan</span></div>
        <div class="info teal">
            ℹ️ Rumus resmi (Kertas Kerja Pengukuran Kinerja Triwulanan, perubahan Triwulan II 2026) yang dipakai otomatis di Verifikasi Capaian &amp; Dasbor Kinerja:
        </div>
        <table style="width:100%;font-size:12.5px;margin-top:10px;border-collapse:collapse">
            <tbody>
                <tr style="border-bottom:1px solid var(--line2)">
                    <td style="padding:8px 6px;color:var(--muted)">Target &gt; 0, Realisasi &gt; 0, rasio ≤ batas</td>
                    <td style="padding:8px 6px"><b>Capaian = Realisasi ÷ Target × 100</b></td>
                </tr>
                <tr style="border-bottom:1px solid var(--line2)">
                    <td style="padding:8px 6px;color:var(--muted)">Target &gt; 0, Realisasi &gt; 0, rasio &gt; batas</td>
                    <td style="padding:8px 6px"><b>Capaian = batas</b> (dibatasi)</td>
                </tr>
                <tr style="border-bottom:1px solid var(--line2)">
                    <td style="padding:8px 6px;color:var(--muted)">Target = 0, Realisasi &gt; 0</td>
                    <td style="padding:8px 6px"><b>Capaian = batas</b></td>
                </tr>
                <tr style="border-bottom:1px solid var(--line2)">
                    <td style="padding:8px 6px;color:var(--muted)">Target = 0, Realisasi = 0</td>
                    <td style="padding:8px 6px"><b>Capaian = "-"</b> (belum ada capaian)</td>
                </tr>
                <tr>
                    <td style="padding:8px 6px;color:var(--muted)">Target &gt; 0, Realisasi = 0</td>
                    <td style="padding:8px 6px"><b>Capaian = "-"</b> (belum ada capaian)</td>
                </tr>
            </tbody>
        </table>
        <div class="fhint" style="margin-top:10px">Satuan tiap IKU (Persen, Poin, dst. — diatur di tab Master IKU) tidak mengubah rumus ini; target &amp; realisasi selalu dibandingkan sebagai rasio apa pun satuannya. Target &amp; Realisasi itu sendiri diisi di tab <a wire:navigate href="{{ route('master-iku.index') }}#target">🎯 Target Tahunan</a> (per IKU, sekali per tahun) dan halaman Verifikasi (Alokasi/Realisasi per triwulan) — bukan di sini.</div>
    </div>

    <div class="card">
        <div class="sec"><span>Batas Maksimal Capaian</span> <span class="badge b-tunggu">bisa diubah</span></div>
        <div class="info">ℹ️ Angka ini dipakai untuk kedua baris "dibatasi" pada tabel di atas. Ubah di sini kalau rumus resmi berubah lagi nanti (mis. dari 120% ke angka lain) — tidak perlu ubah kode aplikasi.</div>

        <div class="field" style="max-width:220px">
            <label>Batas Maksimal Capaian (%) <span class="req">*</span></label>
            <input type="number" step="0.01" class="inp filled" style="width:100%" wire:model="batasMaksimalPersen">
            @error('batasMaksimalPersen')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
                <span wire:loading.remove wire:target="simpan">Simpan Pengaturan</span>
                <span wire:loading wire:target="simpan"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="sec"><span>Format Angka Capaian</span> <span class="badge b-tunggu">bisa diubah</span></div>
        <div class="info">ℹ️ Berlaku untuk kolom Target/Realisasi/Capaian % di seluruh aplikasi yang menampilkan angka capaian: tabel &amp; Excel Rekap Kinerja Tahunan, Detail Verifikasi (Alokasi/Realisasi Kumulatif &amp; Capaian %), Dasbor Kinerja, dan isi otomatis Notula Bagian I. Nilai kosong (belum ada data) selalu ditulis "-"; pengaturan ini hanya menentukan tulisan untuk nilai yang benar-benar 0.</div>

        <div class="field" style="max-width:420px">
            <label class="fl-row" style="cursor:pointer;gap:8px">
                <input type="checkbox" wire:model="tampilkanNolSebagaiStrip">
                <span>Tulis nilai 0 sebagai "-" <span class="muted" style="font-weight:400">(dianggap belum ada data, bukan capaian nol)</span></span>
            </label>
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
                <span wire:loading.remove wire:target="simpan">Simpan Pengaturan</span>
                <span wire:loading wire:target="simpan"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="sec"><span>Penilaian Kinerja Organisasi (PKO)</span> <span class="badge b-tunggu">bisa diubah</span></div>
        <div class="info">ℹ️ Normalisasi Capaian PK (di Dasbor Capaian) = min(Capaian % Setahun TW IV tiap IKU, batas ini). Batas ini TERPISAH dari "Batas Maksimal Capaian" di atas — ubah di sini kalau rumus PKO resmi berubah (saat ini 110%).</div>

        <div class="field" style="max-width:220px">
            <label>Batas Normalisasi Capaian PK (%) <span class="req">*</span></label>
            <input type="number" step="0.01" class="inp filled" style="width:100%" wire:model="batasNormalisasiPko">
            @error('batasNormalisasiPko')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">
                <span wire:loading.remove wire:target="simpan">Simpan Pengaturan</span>
                <span wire:loading wire:target="simpan"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
    </div>
</div>
