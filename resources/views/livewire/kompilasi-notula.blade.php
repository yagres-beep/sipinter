@php
    $bagian1Siap = filled($notula->bagian1_html);
    $bagian2Siap = filled($notula->bagian2_html);
    $bagian3Siap = filled($notula->bagian3_html);
    $sudahDigabung = filled($notula->pdf_gabungan);
@endphp

<div>
    <div class="page-head">
        <div class="page-title">Kompilasi Notula Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulan - 1] }} {{ $tahun }}</div>
        <div class="page-sub">
            Gabungkan tiga bagian menjadi satu PDF notula utuh.
            <x-badge-status :status="$notula->status" />
        </div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @if ($notula->status === \App\Models\Notula::STATUS_DIKEMBALIKAN && $notula->catatan_pengembalian)
        <div class="card card-red" style="margin-bottom:16px">
            <div class="sec"><span>↩ Dikembalikan Kepala</span></div>
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
        <div class="sec"><span>Detail Rapat</span></div>
        <div class="fl-row" style="gap:16px;flex-wrap:wrap">
            <div class="field" style="flex:1;min-width:200px">
                <label>Hari/Tanggal</label>
                <input type="text" class="inp filled" style="width:100%" wire:model="hariTanggal">
                @error('hariTanggal')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="field" style="flex:1;min-width:160px">
                <label>Waktu</label>
                <input type="text" class="inp filled" style="width:100%" wire:model="waktu">
                @error('waktu')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="field" style="flex:1;min-width:200px">
                <label>Tempat</label>
                <input type="text" class="inp filled" style="width:100%" wire:model="tempat">
                @error('tempat')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="field" style="flex:1;min-width:200px">
                <label>Pimpinan Rapat</label>
                <input type="text" class="inp filled" style="width:100%" wire:model="pimpinanRapat">
                @error('pimpinanRapat')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="field" style="flex:1;min-width:200px">
                <label>Notulis</label>
                <input type="text" class="inp filled" style="width:100%" wire:model="notulis" placeholder="Nama penulis notula">
                @error('notulis')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
        </div>
        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="simpanDetailRapat" wire:loading.attr="disabled" wire:target="simpanDetailRapat">
                <span wire:loading.remove wire:target="simpanDetailRapat">Simpan Detail Rapat</span>
                <span wire:loading wire:target="simpanDetailRapat"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
    </div>

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
            aktifKiri: false, aktifTengah: false, aktifKanan: false, aktifRata: false,
            seleksiTersimpan: null,
            // <select> (beda dari <button>) TIDAK BISA dicegah default mousedown-nya —
            // itu justru yang membuka dropdown native-nya. Klik ke situ otomatis
            // memindah fokus dari area edit dan membatalkan seleksi teks yang sedang
            // disorot, jadi seleksinya disimpan dulu di sini (sebelum fokus berpindah)
            // lalu dipulihkan begitu pilihan di dropdown selesai dipilih (pulihkanSeleksi).
            simpanSeleksi() {
                const sel = window.getSelection();
                this.seleksiTersimpan = sel.rangeCount > 0 ? sel.getRangeAt(0).cloneRange() : null;
            },
            pulihkanSeleksi() {
                if (this.seleksiTersimpan) {
                    const sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(this.seleksiTersimpan);
                }
                this.$refs.editor.focus();
            },
            blokTags: ['P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'LI', 'TD', 'TH', 'BLOCKQUOTE'],
            // Cari blok (paragraf/judul/dst.) tempat kursor berada SEKARANG — dipakai
            // cuma untuk menyalakan tombol Kiri/Tengah/Kanan/Rata yang aktif, bukan
            // untuk menentukan apa yang diubah (lihat blokTerpilih di bawah).
            blokDiKursor() {
                const sel = window.getSelection();
                if (!sel.rangeCount) return null;
                let node = sel.getRangeAt(0).startContainer;
                if (node.nodeType === 3) node = node.parentElement;
                while (node && node !== this.$refs.editor) {
                    if (this.blokTags.includes(node.tagName)) return node;
                    node = node.parentElement;
                }
                return null;
            },
            // Seluruh blok yang TERSENTUH oleh seleksi teks yang sedang disorot —
            // dipakai Rata/Spasi Baris supaya HANYA bagian yang diblok yang berubah,
            // bukan seluruh dokumen. Kosong (tidak ada yang diubah) kalau tidak ada
            // teks yang sedang diseleksi (kursor cuma 'berkedip' di satu titik).
            blokTerpilih() {
                const sel = window.getSelection();
                if (!sel.rangeCount || sel.isCollapsed) return [];
                const range = sel.getRangeAt(0);
                const semuaBlok = Array.from(this.$refs.editor.querySelectorAll(this.blokTags.join(',')));
                const kena = semuaBlok.filter(el => range.intersectsNode(el));
                // Cuma ambil blok PALING DALAM (yang tidak membungkus blok lain yang
                // juga kena) — supaya style diterapkan ke elemen yang benar-benar
                // membungkus teksnya, bukan ke div pembungkus luar yang cuma 'ikut
                // kena' karena seleksinya lewat di dalamnya.
                return kena.filter(el => !kena.some(lain => lain !== el && el.contains(lain)));
            },
            perbaruiStatus() {
                this.aktifBold = document.queryCommandState('bold');
                this.aktifItalic = document.queryCommandState('italic');
                this.aktifUnderline = document.queryCommandState('underline');
                const blok = this.blokDiKursor();
                const rata = blok ? (blok.style.textAlign || getComputedStyle(blok).textAlign) : 'left';
                this.aktifKiri = rata === 'left' || rata === 'start' || rata === '';
                this.aktifTengah = rata === 'center';
                this.aktifKanan = rata === 'right' || rata === 'end';
                this.aktifRata = rata === 'justify';
            },
            jalankan(perintah, nilai = null) {
                // Pastikan area edit BENAR-BENAR fokus dulu (bukan cuma 'tidak kehilangan
                // fokus' lewat @mousedown.prevent) — formatBlock/insertList butuh selection
                // aktif di dalam elemen, tidak seperti bold/italic yang tetap jalan tanpa itu.
                this.$refs.editor.focus();
                document.execCommand(perintah, false, nilai);
                this.perbaruiStatus();
                this.$wire.set('bagian1EditText', this.$refs.editor.innerHTML);
            },
            // Perataan (Kiri/Tengah/Kanan/Rata) diterapkan lewat style.textAlign
            // langsung ke blok yang diblok, BUKAN document.execCommand('justify...')
            // — execCommand itu tidak konsisten kalau strukturnya bertingkat (mis.
            // paragraf di dalam div pembungkus seperti hasil 'Susun Ulang Otomatis'),
            // jadi kliknya kadang terasa tidak berpengaruh sama sekali.
            aturRata(nilai) {
                const blok = this.blokTerpilih();
                if (blok.length === 0) return;
                blok.forEach(el => { el.style.textAlign = nilai; });
                this.perbaruiStatus();
                this.$wire.set('bagian1EditText', this.$refs.editor.innerHTML);
            },
            aturFont(nilai) {
                if (!nilai) return;
                this.pulihkanSeleksi();
                document.execCommand('fontName', false, nilai);
                this.$wire.set('bagian1EditText', this.$refs.editor.innerHTML);
            },
            aturUkuranFont(px) {
                // execCommand('fontSize') cuma punya skala lama 1-7 (bukan px asli) —
                // trik umum: terapkan skala 7 dulu lalu timpa <font size=7> yang baru
                // dibuat dengan style.fontSize sesuai pilihan pengguna.
                if (!px) return;
                this.pulihkanSeleksi();
                document.execCommand('fontSize', false, '7');
                Array.from(this.$refs.editor.getElementsByTagName('font')).forEach(el => {
                    if (el.getAttribute('size') === '7') {
                        el.removeAttribute('size');
                        el.style.fontSize = px + 'px';
                    }
                });
                this.$wire.set('bagian1EditText', this.$refs.editor.innerHTML);
            },
            aturSpasiBaris(nilai) {
                // Sama seperti aturRata: HANYA blok yang sedang diblok/diseleksi yang
                // berubah. Kalau tidak ada teks yang diseleksi, tidak ada yang diubah
                // sama sekali (bukan diterapkan ke seluruh dokumen seperti sebelumnya).
                if (!nilai) return;
                this.pulihkanSeleksi();
                const blok = this.blokTerpilih();
                if (blok.length === 0) return;
                blok.forEach(el => { el.style.lineHeight = nilai; });
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
                <span class="doc-toolbar-sep"></span>
                <button type="button" :class="{ active: aktifKiri }" @mousedown.prevent @click="aturRata('left')" title="Rata kiri (blok teks dulu)">Kiri</button>
                <button type="button" :class="{ active: aktifTengah }" @mousedown.prevent @click="aturRata('center')" title="Rata tengah (blok teks dulu)">Tengah</button>
                <button type="button" :class="{ active: aktifKanan }" @mousedown.prevent @click="aturRata('right')" title="Rata kanan (blok teks dulu)">Kanan</button>
                <button type="button" :class="{ active: aktifRata }" @mousedown.prevent @click="aturRata('justify')" title="Rata kiri-kanan (blok teks dulu)">Rata</button>
                <span class="doc-toolbar-sep"></span>
                <select @mousedown="simpanSeleksi()" @change="aturFont($event.target.value); $event.target.selectedIndex = 0" title="Jenis huruf">
                    <option value="">Font…</option>
                    <option value="'Times New Roman',Times,serif">Times New Roman</option>
                    <option value="Arial,sans-serif">Arial</option>
                    <option value="Calibri,'Segoe UI',sans-serif">Calibri</option>
                    <option value="Georgia,serif">Georgia</option>
                    <option value="'Courier New',Courier,monospace">Courier New</option>
                </select>
                <select @mousedown="simpanSeleksi()" @change="aturUkuranFont($event.target.value); $event.target.selectedIndex = 0" title="Ukuran huruf">
                    <option value="">Ukuran…</option>
                    <option value="10">10</option>
                    <option value="12">12</option>
                    <option value="14">14</option>
                    <option value="16">16</option>
                    <option value="18">18</option>
                    <option value="20">20</option>
                    <option value="24">24</option>
                    <option value="28">28</option>
                    <option value="32">32</option>
                </select>
                <select @mousedown="simpanSeleksi()" @change="aturSpasiBaris($event.target.value); $event.target.selectedIndex = 0" title="Jarak antar baris">
                    <option value="">Spasi…</option>
                    <option value="1">1.0</option>
                    <option value="1.15">1.15</option>
                    <option value="1.5">1.5</option>
                    <option value="1.9">1.9</option>
                    <option value="2">2.0</option>
                    <option value="2.5">2.5</option>
                </select>
                <span class="doc-toolbar-sep"></span>
                <button type="button" @mousedown.prevent @click="jalankan('removeFormat')" title="Hapus format">⌫ Format</button>
                <button type="button" @mousedown.prevent @click="jalankan('undo')" title="Urungkan (Ctrl+Z)">↶</button>
                <button type="button" @mousedown.prevent @click="jalankan('redo')" title="Ulangi (Ctrl+Y)">↷</button>
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
            <button type="button" class="btn btn-ghost btn-sm" wire:click="susunUlangOtomatis" wire:loading.attr="disabled" wire:target="susunUlangOtomatis,simpanSuntinganBagian1">
                <span wire:loading.remove wire:target="susunUlangOtomatis">↻ Susun Ulang Otomatis</span>
                <span wire:loading wire:target="susunUlangOtomatis"><i class="spin"></i> Menyusun…</span>
            </button>
            <button type="button" class="btn btn-primary btn-sm" wire:click="simpanSuntinganBagian1" wire:loading.attr="disabled" wire:target="susunUlangOtomatis,simpanSuntinganBagian1">
                <span wire:loading.remove wire:target="simpanSuntinganBagian1">💾 Simpan Bagian I</span>
                <span wire:loading wire:target="simpanSuntinganBagian1"><i class="spin"></i> Menyimpan…</span>
            </button>
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
                    <input type="file" wire:model="bagian2File" accept=".docx,.doc,.xlsx,.xls,.odt,.ods,.jpg,.jpeg,.png,.pdf" style="display:none">
                </label>
            </div>
        @else
            <label class="upload upload-tinggi need" style="cursor:pointer;display:flex">
                <div><div class="big">📤</div>Klik untuk unggah Bagian II (docx/xlsx/odt/ods, gambar, atau PDF)</div>
                <input type="file" wire:model="bagian2File" accept=".docx,.doc,.xlsx,.xls,.odt,.ods,.jpg,.jpeg,.png,.pdf" style="display:none">
            </label>
        @endif
        <div wire:loading wire:target="bagian2File" style="font-size:11.5px;color:var(--muted);margin-top:6px">Mengunggah…</div>
        @error('bagian2File')
            <div style="color:var(--red);font-size:11.5px;margin-top:6px">{{ $message }}</div>
        @enderror
        @if ($bagian2File)
            <div class="btn-row" style="margin-top:8px">
                <button type="button" class="btn btn-teal btn-sm" wire:click="unggahBagian(2)" wire:loading.attr="disabled" wire:target="unggahBagian(2)">
                    <span wire:loading.remove wire:target="unggahBagian(2)">Proses →</span>
                    <span wire:loading wire:target="unggahBagian(2)"><i class="spin"></i> Memproses…</span>
                </button>
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
                    <input type="file" wire:model="bagian3File" accept=".docx,.doc,.xlsx,.xls,.odt,.ods,.jpg,.jpeg,.png,.pdf" style="display:none">
                </label>
            </div>
        @else
            <label class="upload upload-tinggi need" style="cursor:pointer;display:flex">
                <div><div class="big">📤</div>Klik untuk unggah Bagian III (docx/xlsx/odt/ods, gambar, atau PDF)</div>
                <input type="file" wire:model="bagian3File" accept=".docx,.doc,.xlsx,.xls,.odt,.ods,.jpg,.jpeg,.png,.pdf" style="display:none">
            </label>
        @endif
        <div wire:loading wire:target="bagian3File" style="font-size:11.5px;color:var(--muted);margin-top:6px">Mengunggah…</div>
        @error('bagian3File')
            <div style="color:var(--red);font-size:11.5px;margin-top:6px">{{ $message }}</div>
        @enderror
        @if ($bagian3File)
            <div class="btn-row" style="margin-top:8px">
                <button type="button" class="btn btn-teal btn-sm" wire:click="unggahBagian(3)" wire:loading.attr="disabled" wire:target="unggahBagian(3)">
                    <span wire:loading.remove wire:target="unggahBagian(3)">Proses →</span>
                    <span wire:loading wire:target="unggahBagian(3)"><i class="spin"></i> Memproses…</span>
                </button>
            </div>
        @endif

        <div class="merge-bar" style="margin-top:26px">
            <span class="merge-stat">
                Status: Bagian I {{ $bagian1Siap ? '✓' : '✕' }} · II {{ $bagian2Siap ? '✓' : '✕' }} · III {{ $bagian3Siap ? '✓' : '✕' }}
                @if (! ($bagian1Siap && $bagian2Siap && $bagian3Siap))
                    — lengkapi seluruh bagian untuk menggabungkan
                @endif
            </span>
            <button type="button" class="btn btn-primary btn-sm" wire:click="gabungkan" wire:loading.attr="disabled" wire:target="gabungkan" @disabled(! ($bagian1Siap && $bagian2Siap && $bagian3Siap))>
                <span wire:loading.remove wire:target="gabungkan">🔗 Gabungkan → PDF</span>
                <span wire:loading wire:target="gabungkan"><i class="spin"></i> Menggabungkan…</span>
            </button>

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
