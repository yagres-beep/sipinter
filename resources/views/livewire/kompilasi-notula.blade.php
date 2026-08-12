@php
    $bagian1Siap = filled($notula->bagian1_html);
    $bagian2Siap = filled($notula->bagian2_pdf);
    $bagian3Siap = filled($notula->bagian3_pdf);
    $sudahDigabung = filled($notula->pdf_gabungan);
@endphp

<div>
    <div class="page-title">Kompilasi Notula Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulan - 1] }} {{ $tahun }}</div>
    <div class="page-sub">
        Gabungkan tiga bagian menjadi satu PDF notula utuh.
        <x-badge-status :status="$notula->status" />
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @if ($notula->status === \App\Models\Notula::STATUS_DIKEMBALIKAN && $notula->catatan_pengembalian)
        <div class="card" style="border-color:#fca5a5;background:var(--red-soft);margin-bottom:16px">
            <div class="sec" style="color:var(--red);border-color:#fca5a5"><span>↩ Dikembalikan Kepala</span></div>
            <p style="color:var(--red);font-size:13px;margin:0">{{ $notula->catatan_pengembalian }}</p>
        </div>
    @endif

    <div class="period-banner">
        <span class="pb-ico">📅</span>
        <div>
            <div class="pb-lbl">Triwulan</div>
            <div class="pb-val">
                <select wire:model.live="triwulan" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                    @foreach (['I', 'II', 'III', 'IV'] as $idx => $label)
                        <option value="{{ $idx + 1 }}">Triwulan {{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="tahun" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                    @foreach (range(now()->year - 1, now()->year + 1) as $tahunOpsi)
                        <option value="{{ $tahunOpsi }}">{{ $tahunOpsi }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @if ($kesiapanSasaran->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Kesiapan per Sasaran</span></div>
            <table>
                <thead><tr><th>Sasaran</th><th>IKU Siap</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($kesiapanSasaran as $baris)
                        <tr>
                            <td>{{ $baris['sasaran'] }}</td>
                            <td>{{ $baris['iku_siap'] }}/{{ $baris['iku_total'] }}</td>
                            <td>
                                @if ($baris['iku_siap'] === $baris['iku_total'])
                                    <x-badge-status status="diverifikasi" />
                                @else
                                    <x-badge-status status="menunggu" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card">
        <div class="sec"><span>Pratinjau &amp; Sunting Notula — Bagian I, II, III</span></div>
        <p style="color:var(--muted);font-size:12.5px;margin-bottom:16px">
            Satu dokumen utuh dari atas ke bawah, sama seperti membuka file Word yang sudah lengkap. Bagian I bisa
            disunting langsung; Bagian II &amp; III tinggal unggah berkasnya dan langsung tampil di bawahnya.
        </p>

        {{-- BAGIAN I — disunting langsung, WYSIWYG --}}
        <div class="doc-bagian-head">
            <span class="doc-bagian-badge">Bagian I</span> Capaian Kinerja
            <span class="st {{ $bagian1Siap ? 'ok' : 'no' }}" style="margin-left:auto">{{ $bagian1Siap ? '✓ Siap' : '✕ Belum lengkap' }}</span>
        </div>

        <div x-data="{
            aktifBold: false, aktifItalic: false, aktifUnderline: false,
            perbaruiStatus() {
                this.aktifBold = document.queryCommandState('bold');
                this.aktifItalic = document.queryCommandState('italic');
                this.aktifUnderline = document.queryCommandState('underline');
            },
            jalankan(perintah, nilai = null) {
                // Pastikan area edit BENAR-BENAR fokus dulu (bukan cuma 'tidak kehilangan
                // fokus' lewat @mousedown.prevent) — formatBlock/insertList butuh selection
                // aktif di dalam elemen, tidak seperti bold/italic yang tetap jalan tanpa itu.
                this.$refs.editor.focus();
                document.execCommand(perintah, false, nilai);
                this.perbaruiStatus();
                this.$wire.set('bagian1EditText', this.$refs.editor.innerHTML);
            }
        }" x-init="document.addEventListener('selectionchange', () => perbaruiStatus())">
            <div class="doc-toolbar">
                <button type="button" :class="{ active: aktifBold }" @mousedown.prevent @click="jalankan('bold')" title="Tebal"><b>B</b></button>
                <button type="button" :class="{ active: aktifItalic }" @mousedown.prevent @click="jalankan('italic')" title="Miring"><i>I</i></button>
                <button type="button" :class="{ active: aktifUnderline }" @mousedown.prevent @click="jalankan('underline')" title="Garis bawah"><u>U</u></button>
                <span class="doc-toolbar-sep"></span>
                <button type="button" @mousedown.prevent @click="jalankan('formatBlock', '<h3>')" title="Judul bagian">H3</button>
                <button type="button" @mousedown.prevent @click="jalankan('formatBlock', '<p>')" title="Paragraf biasa">¶</button>
                <span class="doc-toolbar-sep"></span>
                <button type="button" @mousedown.prevent @click="jalankan('insertUnorderedList')" title="Daftar bertitik">• Daftar</button>
                <button type="button" @mousedown.prevent @click="jalankan('insertOrderedList')" title="Daftar bernomor">1. Daftar</button>
            </div>

            <div class="word-canvas">
            <div class="notula" contenteditable="true" wire:ignore spellcheck="false" x-ref="editor"
                style="min-height:520px;max-height:680px;overflow-y:auto"
                x-on:bagian1-diperbarui.window="$el.innerHTML = $event.detail.html"
                @keyup="perbaruiStatus()" @mouseup="perbaruiStatus()"
                @blur="$wire.set('bagian1EditText', $el.innerHTML)">
                @if (trim($bagian1EditText) === '')
                    <p style="color:var(--faint);font-style:italic">Belum ada konten. Tekan "Susun Ulang Otomatis" atau mulai mengetik di sini.</p>
                @else
                    {!! $bagian1EditText !!}
                @endif
            </div>
            </div>
        </div>

        <div class="btn-row" style="margin-top:10px">
            <button type="button" class="btn btn-ghost btn-sm" wire:click="susunUlangOtomatis">↻ Susun Ulang Otomatis</button>
            <button type="button" class="btn btn-primary btn-sm" wire:click="simpanSuntinganBagian1">💾 Simpan Bagian I</button>
        </div>

        {{-- BAGIAN II — berkas unggahan, tampil sebagai kelanjutan dokumen yang sama --}}
        <div class="doc-bagian-head" style="margin-top:28px">
            <span class="doc-bagian-badge">Bagian II</span> Peran BPS dalam Prioritas Nasional &amp; Isu Strategis
            <span class="st {{ $bagian2Siap ? 'ok' : 'no' }}" style="margin-left:auto">{{ $bagian2Siap ? '✓ Siap' : '✕ Belum lengkap' }}</span>
        </div>

        @if ($bagian2Siap)
            <div class="doc-preview-frame">
                <iframe src="{{ route('notula.pratinjau-bagian2', $notula) }}" style="height:500px" title="Pratinjau Bagian II"></iframe>
            </div>
            <div class="btn-row" style="margin-top:8px">
                <label class="btn btn-ghost btn-sm" style="cursor:pointer">
                    ⟲ Ganti Berkas
                    <input type="file" wire:model="bagian2File" accept=".docx" style="display:none">
                </label>
            </div>
        @else
            <label class="upload upload-tinggi need" style="cursor:pointer;display:flex">
                <div><div class="big">📤</div>Klik untuk unggah Bagian II (.docx saja)</div>
                <input type="file" wire:model="bagian2File" accept=".docx" style="display:none">
            </label>
        @endif
        <div wire:loading wire:target="bagian2File" style="font-size:11.5px;color:var(--muted);margin-top:6px">Mengunggah…</div>
        @error('bagian2File')
            <div style="color:var(--red);font-size:11.5px;margin-top:6px">{{ $message }}</div>
        @enderror
        @if ($bagian2File)
            <div class="btn-row" style="margin-top:8px">
                <button type="button" class="btn btn-teal btn-sm" wire:click="unggahBagian(2)">Proses →</button>
            </div>
        @endif

        {{-- BAGIAN III — sama seperti Bagian II --}}
        <div class="doc-bagian-head" style="margin-top:28px">
            <span class="doc-bagian-badge">Bagian III</span> Realisasi Anggaran &amp; Upaya Efisiensi
            <span class="st {{ $bagian3Siap ? 'ok' : 'no' }}" style="margin-left:auto">{{ $bagian3Siap ? '✓ Siap' : '✕ Belum lengkap' }}</span>
        </div>

        @if ($bagian3Siap)
            <div class="doc-preview-frame">
                <iframe src="{{ route('notula.pratinjau-bagian3', $notula) }}" style="height:500px" title="Pratinjau Bagian III"></iframe>
            </div>
            <div class="btn-row" style="margin-top:8px">
                <label class="btn btn-ghost btn-sm" style="cursor:pointer">
                    ⟲ Ganti Berkas
                    <input type="file" wire:model="bagian3File" accept=".docx" style="display:none">
                </label>
            </div>
        @else
            <label class="upload upload-tinggi need" style="cursor:pointer;display:flex">
                <div><div class="big">📤</div>Klik untuk unggah Bagian III (.docx saja)</div>
                <input type="file" wire:model="bagian3File" accept=".docx" style="display:none">
            </label>
        @endif
        <div wire:loading wire:target="bagian3File" style="font-size:11.5px;color:var(--muted);margin-top:6px">Mengunggah…</div>
        @error('bagian3File')
            <div style="color:var(--red);font-size:11.5px;margin-top:6px">{{ $message }}</div>
        @enderror
        @if ($bagian3File)
            <div class="btn-row" style="margin-top:8px">
                <button type="button" class="btn btn-teal btn-sm" wire:click="unggahBagian(3)">Proses →</button>
            </div>
        @endif

        <div class="merge-bar" style="margin-top:26px">
            <span class="merge-stat">
                Status: Bagian I {{ $bagian1Siap ? '✓' : '✕' }} · II {{ $bagian2Siap ? '✓' : '✕' }} · III {{ $bagian3Siap ? '✓' : '✕' }}
                @if (! ($bagian1Siap && $bagian2Siap && $bagian3Siap))
                    — lengkapi seluruh bagian untuk menggabungkan
                @endif
            </span>
            <button type="button" class="btn btn-primary btn-sm" wire:click="gabungkan" @disabled(! ($bagian1Siap && $bagian2Siap && $bagian3Siap))>🔗 Gabungkan → PDF</button>

            @if ($sudahDigabung)
                <a href="{{ route('notula.unduh-draf', $notula) }}" class="btn btn-ghost btn-sm {{ ! $semuaTerverifikasi ? 'disabled' : '' }}"
                    @if (! $semuaTerverifikasi) onclick="return false" style="opacity:.5;cursor:not-allowed" title="Seluruh bukti kegiatan triwulan ini harus terverifikasi dahulu" @endif>
                    ⬇ Unduh draf
                </a>

                @if ($notula->status === \App\Models\Notula::STATUS_DISETUJUI && $notula->pdf_final)
                    <a href="{{ route('notula.unduh-final', $notula) }}" class="btn btn-teal btn-sm">⬇ Unduh final</a>
                @else
                    <span class="btn btn-teal btn-sm" style="opacity:.5;cursor:not-allowed" title="Menunggu persetujuan Kepala">⬇ Unduh final</span>
                @endif
            @endif
        </div>

        @error('gabung')
            <div style="color:var(--red);font-size:11.5px;margin-top:10px">{{ $message }}</div>
        @enderror

        @if ($sudahDigabung)
            <div class="doc-preview-label">📄 Pratinjau resmi — hasil akhir PDF gabungan (I + II + III)</div>
            <div class="doc-preview-frame">
                <iframe src="{{ route('notula.pratinjau', $notula) }}" style="height:700px" title="Pratinjau notula gabungan"></iframe>
            </div>
        @endif
    </div>

    @if ($riwayatDisetujui->isNotEmpty())
        <div class="card" style="margin-top:16px">
            <div class="sec"><span>Riwayat Versi Ber-TTD</span></div>
            @foreach ($riwayatDisetujui as $n)
                <div class="filechip ok" wire:key="riwayat-{{ $n->id }}">
                    <span class="nm">📄 Notula TW {{ ['I', 'II', 'III', 'IV'][$n->periode->triwulan - 1] }} {{ $n->periode->tahun }}
                        <span class="sub">Disetujui {{ $n->disetujui_pada?->translatedFormat('d F Y') }}</span>
                    </span>
                    <a href="{{ route('notula.unduh-final', $n) }}" class="btn btn-ghost btn-sm">⬇ Unduh</a>
                </div>
            @endforeach
        </div>
    @endif
</div>
