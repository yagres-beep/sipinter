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
        <div class="fhint" style="margin-top:10px">Satuan tiap IKU (Persen, Poin, dst. — diatur di tab Master IKU) tidak mengubah rumus ini; target &amp; realisasi selalu dibandingkan sebagai rasio apa pun satuannya.</div>
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
</div>
