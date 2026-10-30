<div>
    <div class="page-head">
        <div class="page-title">Template Notula</div>
        <div class="page-sub">Unggah template mesin (.docx) yang DIPAKAI LANGSUNG untuk menyusun Bagian I otomatis.</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @if (session('peringatan'))
        <div class="badge b-tunggu" style="display:block;margin-bottom:14px;white-space:normal">⚠ {{ session('peringatan') }}</div>
    @endif

    @if (session('error'))
        <div class="badge b-tolak" style="display:block;margin-bottom:14px">{{ session('error') }}</div>
    @endif

    <div class="info">
        ⚠️ Berkas ini BUKAN sekadar arsip — begitu diunggah, berkas ini langsung dipakai untuk menyusun
        Bagian I (pratinjau web maupun unduhan .docx). Pastikan berkasnya adalah template MESIN dengan
        struktur macro <code>@{{iku_blok}}</code> ... <code>@{{/iku_blok}}</code> yang benar (lihat panduan
        <code>PANDUAN_Template_Bagian_I_Mesin.md</code>), bukan sekadar dokumen contoh format resmi —
        berkas tanpa macro yang sesuai akan membuat "Susun Ulang Otomatis" gagal.
    </div>

    <div class="card">
        <div class="sec"><span>Unggah Template (.docx)</span></div>

        <div class="field">
            @if ($templateFile)
                <div class="filechip">
                    <span class="nm">📄 {{ $templateFile->getClientOriginalName() }}</span>
                    <span class="x" style="cursor:pointer" wire:click="$set('templateFile', null)">✕</span>
                </div>
            @else
                <label class="upload" style="cursor:pointer;display:block">
                    <div class="big">📤</div>
                    Klik untuk memilih berkas template (.docx)
                    <input type="file" wire:model="templateFile" accept=".docx" style="display:none">
                </label>
            @endif

            <div wire:loading wire:target="templateFile" style="font-size:11.5px;color:var(--muted);margin-top:6px">Mengunggah berkas…</div>

            @error('templateFile')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="unggah" wire:loading.attr="disabled" wire:target="unggah">
                <span wire:loading.remove wire:target="unggah">Unggah &amp; Simpan</span>
                <span wire:loading wire:target="unggah"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="sec"><span>Template Tersimpan</span></div>

        @if ($config->template_notula_path)
            <div class="filechip ok">
                <span class="nm">📄 {{ $config->template_notula_nama_asli }} <span class="sub">Diperbarui {{ $config->updated_at->wita()->translatedFormat('d F Y, H:i') }} WITA</span></span>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="unduh" wire:loading.attr="disabled" wire:target="unduh">⬇ Unduh</button>
                <button type="button" class="btn btn-red btn-sm" wire:click="confirmHapus" wire:loading.attr="disabled" wire:loading.class="btn-busy" wire:target="confirmHapus">Hapus</button>
            </div>
        @else
            <p style="color:var(--muted);font-size:13px">Belum ada template yang diunggah — notula memakai template bawaan.</p>
        @endif

        <div class="btn-row">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="periksaTemplate" wire:loading.attr="disabled" wire:target="periksaTemplate">
                <span wire:loading.remove wire:target="periksaTemplate">🔎 Periksa placeholder template aktif</span>
                <span wire:loading wire:target="periksaTemplate"><i class="spin"></i> Memeriksa…</span>
            </button>
        </div>

        @if ($hasilPeriksa)
            <div style="margin-top:10px;font-size:12.5px" data-uji="hasil-periksa-template" role="status">
                @if ($hasilPeriksa['galat'])
                    <div class="info warn">{{ $hasilPeriksa['galat'] }}</div>
                @else
                    <div style="margin-bottom:6px;color:var(--muted)">
                        {{ $hasilPeriksa['bawaan'] ? 'Template bawaan' : 'Template unggahan' }}: {{ count($hasilPeriksa['dipakai']) }} placeholder dikenali.
                    </div>
                    @if ($hasilPeriksa['tidak_dikenal'] === [] && $hasilPeriksa['usang'] === [] && $hasilPeriksa['salah_tempat'] === [])
                        <div class="badge b-approve" style="display:block;white-space:normal">✓ Semua placeholder dikenali dan diletakkan pada lingkup yang benar.</div>
                    @endif
                    @if ($hasilPeriksa['tidak_dikenal'] !== [])
                        <div class="info warn" style="margin-bottom:6px">⚠ Tidak dikenali (tercetak apa adanya, tidak terisi):
                            @foreach ($hasilPeriksa['tidak_dikenal'] as $kode)<code>{{ \App\Support\RegistriVariabel::tulisan($kode, 'notula_docx') }}</code>@if (! $loop->last), @endif @endforeach
                        </div>
                    @endif
                    @foreach ($hasilPeriksa['usang'] as $kode => $pengganti)
                        <div class="info warn" style="margin-bottom:6px">⚠ <code>{{ \App\Support\RegistriVariabel::tulisan($kode, 'notula_docx') }}</code> sudah tidak disarankan @if ($pengganti) — gunakan <code>{{ \App\Support\RegistriVariabel::tulisan($pengganti, 'notula_docx') }}</code>@endif.</div>
                    @endforeach
                    @foreach ($hasilPeriksa['salah_tempat'] as $kode => $lingkup)
                        <div class="info warn" style="margin-bottom:6px">⚠ <code>{{ \App\Support\RegistriVariabel::tulisan($kode, 'notula_docx') }}</code> berada di luar lingkupnya sehingga tidak akan terisi. Letak yang benar: {{ $lingkup }}.</div>
                    @endforeach
                @endif
            </div>
        @endif
    </div>

    <div class="card" x-data="{ disalin: null }">
        <div class="sec">
            <span>Variabel yang bisa dipakai di template</span>
            <span style="margin-left:auto;font-size:12px;color:var(--muted);font-weight:500">{{ $jumlahTampil === $jumlahVariabel ? $jumlahVariabel.' variabel' : 'Menampilkan '.$jumlahTampil.' dari '.$jumlahVariabel }}</span>
        </div>
        <div class="fhint" style="margin:0 0 10px">Ketik placeholder persis seperti tertulis (mis. <code>@{{kode}}</code>) di berkas Word, atau klik "Salin" lalu tempel. Variabel "per IKU" hanya terisi di dalam <code>@{{iku_blok}}</code> … <code>@{{/iku_blok}}</code>. Dokumentasi lengkap ada di Data Master → Katalog Variabel. Hasil akhirnya terlihat di Kompilasi Notula → pratinjau Bagian I.</div>
        <div class="row2">
            <div class="field" style="margin-bottom:8px">
                <label for="cari-var-template">Cari variabel</label>
                <input id="cari-var-template" type="search" class="inp filled" wire:model.live.debounce.300ms="cariVariabel" placeholder="Kode, nama, atau deskripsi">
            </div>
            <div class="field" style="margin-bottom:8px">
                <label for="kat-var-template">Kategori</label>
                <select id="kat-var-template" class="inp filled" wire:model.live="kategoriVariabel">
                    <option value="">Semua kategori</option>
                    @foreach (\App\Support\RegistriVariabel::KATEGORI as $nilai => $label)
                        <option value="{{ $nilai }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="max-height:420px;overflow-y:auto">
            @forelse ($kelompokVariabel as $kategori => $isi)
                <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;padding:8px 0 4px">{{ \App\Support\RegistriVariabel::KATEGORI[$kategori] }}</div>
                <table style="width:100%;border-collapse:collapse;font-size:12px">
                    @foreach ($isi as $v)
                        @php $tulisan = \App\Support\RegistriVariabel::tulisan($v['kode'], 'notula_docx'); @endphp
                        <tr wire:key="tv-{{ $v['kode'] }}" style="{{ $v['status'] === 'usang' ? 'opacity:.7' : '' }}">
                            <td style="padding:5px 6px 5px 0;border-bottom:1px solid var(--line2);vertical-align:top;white-space:nowrap">
                                <code>{{ $tulisan }}</code>
                                @if ($v['status'] === 'usang')<div><span class="badge b-tunggu">Historis{{ $v['pengganti'] ? ' → '.$v['pengganti'] : '' }}</span></div>@endif
                            </td>
                            <td style="padding:5px 6px;border-bottom:1px solid var(--line2);vertical-align:top">
                                <b>{{ $v['nama'] }}</b>@if ($v['lingkup'] && $v['lingkup'] !== 'global') <span class="badge b-draft">per {{ $v['lingkup'] === 'iku_blok' ? 'IKU' : ($v['lingkup'] === 'ro_row' ? 'rincian output' : 'proksi') }}</span>@endif
                                <div style="color:var(--muted)">{{ $v['deskripsi'] }}</div>
                                <div style="color:var(--muted)">Contoh: {{ $v['contoh'] }}</div>
                            </td>
                            <td style="padding:5px 0;border-bottom:1px solid var(--line2);vertical-align:top;text-align:right">
                                <button type="button" class="btn btn-ghost btn-sm"
                                    x-on:click="navigator.clipboard?.writeText(@js($tulisan)); disalin = @js($v['kode']); setTimeout(() => disalin = null, 1500)"
                                    x-text="disalin === @js($v['kode']) ? '✓ Disalin' : 'Salin'">Salin</button>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @empty
                <p style="color:var(--muted);font-size:13px">Tidak ada variabel yang cocok.</p>
            @endforelse
        </div>
    </div>

    <x-confirm-modal :show="$konfirmasiHapus" title="Hapus Template Notula?" message="Hapus template notula ini? Tindakan ini tidak dapat dibatalkan.">
        <button type="button" class="btn btn-ghost" wire:click="cancelHapus" wire:loading.attr="disabled" wire:target="hapus">Batal</button>
        <button type="button" class="btn btn-red" wire:click="hapus" wire:loading.attr="disabled" wire:target="hapus">
            <span wire:loading.remove wire:target="hapus">Hapus</span>
            <span wire:loading wire:target="hapus"><i class="spin"></i> Menghapus…</span>
        </button>
    </x-confirm-modal>
</div>
