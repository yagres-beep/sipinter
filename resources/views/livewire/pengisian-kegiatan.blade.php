<div
    x-data="{
        bisaDiisiTw: {{ $bulan % 3 === 0 ? 'true' : 'false' }},
        modalBerkas: null,
        pendingHapus: null,
        // Menambah blok baru (Kegiatan/Kendala & Solusi/RTL/Bagian Kustom) memicu
        // request Livewire biasa (AJAX, bukan navigasi halaman) — tapi begitu isi
        // halaman bertambah tinggi, browser BISA menggeser posisi scroll saat DOM
        // diperbarui, terasa seperti 'reload ke atas' padahal bukan.

        // Dikoreksi di BEBERAPA frame berturut-turut (bukan cuma sekali) — DOM hasil
        // morph Livewire bisa masih bergeser tinggi setelah frame pertama (mis. blok
        // baru yang baru dirender belum selesai reflow), jadi satu
        // requestAnimationFrame saja kadang masih kebobolan sekejap sebelum posisi
        // scroll dikoreksi balik.
        koreksiScroll(y) {
            const kunci = function () {
                if (1 < Math.abs(window.scrollY - y)) window.scrollTo(0, y);
            };
            requestAnimationFrame(function () {
                kunci();
                requestAnimationFrame(kunci);
                setTimeout(kunci, 50);
                setTimeout(kunci, 150);
            });
        },

        // Dipakai tombol 'Tambah ...': bungkus lewat $wire (mengembalikan Promise)
        // supaya posisi scroll ditangkap SEBELUM request dikirim & dikoreksi SETELAH
        // DOM selesai diperbarui.
        jagaScroll(aksi) {
            const y = window.scrollY;

            // Lepas fokus dari tombol yang baru diklik SEBELUM DOM diperbarui — tombol
            // 'Tambah ...' ada di BAWAH daftar blok yang baru bertambah, jadi begitu blok
            // baru disisipkan DI ATASNYA, posisi tombol itu ikut bergeser ke bawah. Selama
            // tombolnya masih fokus, sebagian browser otomatis men-scroll halaman supaya
            // elemen yang fokus itu tetap terlihat — inilah lompatan yang terlihat SEBELUM
            // koreksi di bawah sempat jalan. Tanpa fokus, tidak ada yang mendorong browser
            // menggeser scroll sendiri sama sekali.
            if (document.activeElement && typeof document.activeElement.blur === 'function') {
                document.activeElement.blur();
            }

            aksi().then(() => this.koreksiScroll(y));
        },

        // Field lain (mis. textarea 'Rencana kegiatan', input 'Batas Waktu') pakai
        // wire:model.live.blur — otomatis mengirim request begitu fokus pindah ke
        // elemen lain, misalnya saat dropdown 'PIC Tindak Lanjut' di sebelahnya
        // dibuka. Request ini TIDAK lewat jagaScroll() (tidak dipicu klik tombol),
        // jadi lompatan scrollnya dijaga di sini: tangkap posisi scroll sebelum
        // SETIAP request komponen ini terkirim, lalu koreksi balik setelah DOM
        // selesai di-morph.
        init() {
            Livewire.hook('commit', ({ component, respond }) => {
                if (component !== this.$wire.__instance) return;
                const y = window.scrollY;
                respond(() => this.koreksiScroll(y));
            });
        },
    }"
    x-on:livewire-upload-start.window="$dispatch('notify', { type: 'info', message: 'Mengunggah berkas ke server…' })"
    x-on:livewire-upload-finish.window="$dispatch('notify', { type: 'success', message: 'Berkas dipilih & tersimpan sementara di server — akan disalin ke Google Drive saat diajukan.' })"
    x-on:livewire-upload-error.window="$dispatch('notify', { type: 'error', message: 'Gagal mengunggah berkas — periksa ukuran (maks 10MB) dan format (harus PDF), lalu coba lagi.' })"
>
    <div class="page-head">
        <div class="page-title">Isian Kegiatan</div>
        <div class="page-sub">Kegiatan, kendala &amp; solusi, evaluasi RTL, dan rencana tindak lanjut — diajukan sekaligus ke Tim SAKIP.</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @if (session('driveGagal'))
        <div class="info red" style="margin-bottom:14px">
            ⚠️ {{ count(session('driveGagal')) }} berkas gagal disalin ke Google Drive (tetap tersimpan aman di server, tapi belum tersalin) — kemungkinan sambungan akun Google Drive sedang bermasalah. Cek menu <b>Akun &amp; Storage</b>, sambungkan ulang bila perlu, lalu hubungi Tim SAKIP untuk mengunggah ulang berkas berikut:
            <ul style="margin:6px 0 0 18px;padding:0">
                @foreach (session('driveGagal') as $namaBerkas)
                    <li>{{ $namaBerkas }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($errors->any())
        <div class="info red">
            ⚠️ Belum bisa diajukan — {{ $errors->count() }} hal perlu dilengkapi:
            <ul style="margin:6px 0 0 18px;padding:0">
                @foreach ($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($formTerkunciDisetujui)
        <div class="info" style="margin-bottom:14px">
            🔒 <b>IKU ini pada periode ini sudah disetujui</b> dan masuk notula final — tidak bisa ditambah/diubah lagi dari sini. Hubungi Tim SAKIP bila perlu revisi (mereka bisa membuka kembali lewat halaman Verifikasi).
        </div>
    @endif

    @if ($pengajuanBisaDiedit)
        <div class="info teal" style="margin-bottom:14px">
            ✏️ <b>Isian ini sudah diajukan dan belum mulai diperiksa Tim SAKIP</b> — Anda masih bisa mengubahnya. Klik "Ajukan ke Tim SAKIP" lagi untuk mengirim perubahan (atau "Simpan Draft" untuk menyimpan tanpa mengajukan ulang). Isian terkunci begitu Tim SAKIP mulai menangani.
        </div>
    @endif

    @if ($formTerkunciSedangDitangani)
        <div class="info" style="margin-bottom:14px">
            🔒 <b>Isian ini sedang ditangani Tim SAKIP</b> — tidak bisa ditambah/diubah dari sini sampai Tim SAKIP menyelesaikan pemeriksaan (Verifikasi Selesai atau Kembalikan ke Ketua Tim).
        </div>
    @endif

    @if ($adaDikembalikan)
        @php $olehKepala = $pengembalianTerakhir?->user?->namaRole() === 'Kepala'; @endphp
        <div class="info red" style="margin-bottom:14px">
            <span>🔴</span>
            <div>
                <b>Isian ini dikembalikan oleh {{ $olehKepala ? 'Kepala' : 'Tim SAKIP' }} untuk periode ini</b> — perbaiki bagian yang ditandai merah di bawah, lalu ajukan ulang.
                @if ($pengembalianTerakhir?->catatan)
                    <div class="catatan-pengembalian">
                        <b>Catatan {{ $olehKepala ? 'Kepala' : 'Tim SAKIP' }}:</b> {{ $pengembalianTerakhir->catatan }}
                    </div>
                @endif
                @if ($catatanPenolakan->isNotEmpty())
                    <ul style="margin:6px 0 0 18px;padding:0">
                        @foreach ($catatanPenolakan as $catatan)
                            <li>{{ $catatan }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    <div class="period-banner">
        <span class="pb-ico">📅</span>
        <div>
            <div class="pb-lbl">Periode Pengisian</div>
            <div class="pb-val">
                {{-- Bukan wire:model: ganti periode memuat ulang seluruh form dari database,
                     jadi bila masih ada perubahan yang belum disimpan, minta konfirmasi dulu
                     (lihat <x-status-simpan>) — batal = pilihan dikembalikan seperti semula. --}}
                <select data-abaikan-perubahan
                    x-on:change="if ($wire.adaPerubahan && ! confirm('Masih ada perubahan yang BELUM disimpan untuk periode ini. Ganti periode dan buang perubahan tersebut?')) { $event.target.value = $wire.bulan; return; } bisaDiisiTw = (Number($event.target.value) % 3 === 0); $wire.set('bulan', Number($event.target.value))"
                    style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                    @foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $idx => $namaBulan)
                        <option value="{{ $idx + 1 }}" @selected($bulan === $idx + 1)>{{ $namaBulan }}</option>
                    @endforeach
                </select>
                <select data-abaikan-perubahan
                    x-on:change="if ($wire.adaPerubahan && ! confirm('Masih ada perubahan yang BELUM disimpan untuk periode ini. Ganti tahun dan buang perubahan tersebut?')) { $event.target.value = $wire.tahun; return; } $wire.set('tahun', Number($event.target.value))"
                    style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                    @foreach (range(now()->year - 1, now()->year + 1) as $tahunOpsi)
                        <option value="{{ $tahunOpsi }}" @selected($tahun === $tahunOpsi)>{{ $tahunOpsi }}</option>
                    @endforeach
                </select>
                · Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulan - 1] }} · Bulan ke-{{ $bulanKe }}
            </div>
        </div>
        @if ($flagTerlewat)
            <span class="badge b-tunggu" style="margin-left:auto">⚠ Bulan Terlewat</span>
        @else
            <span class="pb-tag">TW {{ ['I', 'II', 'III', 'IV'][$triwulan - 1] }}</span>
        @endif
    </div>

    <div class="card" x-data="{ ikuDipilih: {{ $iku_id ? 'true' : 'false' }} }">
        <div class="sec"><span class="n">1</span><span>IKU</span></div>
        <div class="field">
            <label>Indikator Kinerja (IKU) <span class="req">*</span></label>
            <select class="inp filled" data-abaikan-perubahan
                x-on:change="if ($wire.adaPerubahan && ! confirm('Masih ada perubahan yang BELUM disimpan untuk IKU ini. Pindah IKU dan buang perubahan tersebut?')) { $event.target.value = $wire.iku_id ?? ''; return; } ikuDipilih = ($event.target.value !== ''); $wire.set('iku_id', $event.target.value === '' ? null : Number($event.target.value))">
                <option value="" @selected(! $iku_id)>— Pilih IKU —</option>
                @foreach ($ikuList as $iku)
                    <option value="{{ $iku->id }}" @selected($iku_id === $iku->id)>{{ $iku->kode }} — {{ $iku->indikator }}</option>
                @endforeach
            </select>
            @error('iku_id')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
            @if ($ikuList->isEmpty())
                <div style="color:var(--muted);font-size:11.5px;margin-top:5px">Belum ada data master IKU. Hubungi Tim SAKIP untuk mengunggah data IKU.</div>
            @endif
        </div>

        <div class="sec" style="margin-top:20px"><span class="n">2</span><span>Kegiatan (bisa lebih dari satu)</span></div>

        {{-- Tampil/sembunyi INSTAN di sisi klien (Alpine) begitu IKU dipilih — tidak
             menunggu balasan server, karena isi bagian ini (form kegiatan kosong)
             sudah terkirim ke browser sejak awal, cuma disembunyikan lewat CSS. --}}
        <div x-show="!ikuDipilih" class="info warn">🔒 Pilih IKU terlebih dahulu (Bagian 1) untuk mengisi kegiatan.</div>

        <div x-show="ikuDipilih" x-cloak>
        <div wire:loading wire:target="iku_id,bulan,tahun" class="info">⏳ Memuat kegiatan yang sudah pernah diisi untuk IKU &amp; periode ini, kalau ada…</div>

        <div wire:loading.remove wire:target="iku_id,bulan,tahun">
        <div class="info">ℹ️ Isi tiap kolom berurutan dari atas — uraian kegiatan dulu, baru jenis kegiatan bisa dipilih. Nama folder Drive direkomendasikan otomatis dari tahapan dan uraian kegiatan (bisa diganti lewat ✏️). Bukti capaian wajib diunggah tiap kegiatan; bila lebih dari satu berkas, nama bawaannya diberi nomor urut di belakang dan tetap bisa diganti lewat ✏️.</div>

        @foreach ($blocks as $i => $block)
            @php $terkunci = $this->blokTerkunci($block['status_dokumen']); @endphp
            @if ($terkunci)
                <div class="keg" wire:key="block-{{ $i }}" style="opacity:.8">
                    <div class="keg-head">
                        <span class="t">Kegiatan {{ $i + 1 }}</span>
                        <x-badge-status :status="$block['status_dokumen']" />
                    </div>
                    <div style="font-size:11.5px;color:var(--muted);margin-bottom:10px">🔒 Sudah ditangani/diverifikasi Tim SAKIP — tidak bisa diedit dari sini.</div>

                    <div class="field">
                        <label>Uraian Kegiatan</label>
                        <div class="inp filled" style="background:var(--ro-bg)">{{ $block['uraian_kegiatan'] }}</div>
                    </div>
                    <div class="field">
                        <label>Jenis Kegiatan</label>
                        <div class="inp filled" style="background:var(--ro-bg)">{{ $block['jenis'] === 'survei_sensus' ? 'Survei/Sensus' : 'Bukan Survei/Sensus' }}@if ($block['tahapan_survei']) — {{ ucfirst($block['tahapan_survei']) }}@endif</div>
                    </div>
                    <div class="foldername" style="margin-bottom:10px">📁 {{ $this->namaFolderBerlaku($i) }}</div>

                    <div class="field" style="margin-bottom:0">
                        <label>Bukti Capaian (PDF)</label>
                        <div class="filechip-grid">
                            @forelse ($block['existing_bukti'] as $file)
                                <div class="filechip {{ $file['status_verifikasi'] === 'terverifikasi' ? 'ok' : ($file['status_verifikasi'] === 'ditolak' ? 'no' : '') }}">
                                    <span class="nm">
                                        <x-nama-berkas :id="$file['id']" :nama="$file['nama_file']" :diunggah-oleh="$file['diunggah_oleh'] ?? null" />
                                        @if (filled($file['subfolder'] ?? null))
                                            <span class="sub">📁 {{ $file['subfolder'] }}</span>
                                        @endif
                                        @if ($file['status_verifikasi'] === 'ditolak' && $file['catatan'])
                                            <span class="sub" style="color:var(--red)">{{ $file['catatan'] }}</span>
                                        @endif
                                    </span>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file['id'] }}">🔍 Lihat</button>
                                </div>
                            @empty
                                <p style="color:var(--muted);font-size:12.5px">Belum ada bukti diunggah.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @else
            {{--
                wire:key SENGAJA menyertakan status terisi/tidaknya uraian, jenis, dan
                tahapan survei (bukan cuma id/index blok saja) — semua nilai inilah yang
                dipakai x-data di bawah untuk menentukan Jenis Kegiatan/Tahapan
                Survei/Bukti Capaian terkunci atau tidak. x-data hanya dievaluasi SEKALI
                saat elemen pertama kali dibuat; begitu Livewire memuat ulang blok ini
                lewat request AJAX biasa (mis. updatedIkuId() saat memilih IKU yang sudah
                pernah diisi, BUKAN saat mount/reload halaman penuh) tapi wire:key-nya
                tidak berubah, Alpine hanya me-morph DOM dan MEMPERTAHANKAN nilai x-data
                lama — uraianTerisi/jenisTerpilih/tahapanTerisi tetap kosong walau data
                sebenarnya (lewat wire:model) sudah terisi dari database, sehingga Jenis
                Kegiatan/Bukti Capaian tampak terkunci padahal sudah ada isinya (baru
                "kebuka" setelah pengguna mengetik ulang Uraian Kegiatan). Dengan key yang
                ikut berubah, Livewire memperlakukan blok ini sebagai elemen BARU begitu
                status kunci sebenarnya berubah — Alpine terpaksa membuat ulang x-data
                dari nilai server yang sudah benar.

                (Sempat dicoba pakai $wire.entangle() supaya tidak perlu trik wire:key
                sama sekali, tapi ternyata bikin SELURUH tombol "Tambah ..." di halaman
                ini berhenti berfungsi di Livewire 4 — dikembalikan ke pendekatan
                wire:key ini yang sudah terbukti aman.)
            --}}
            <div class="keg" wire:key="block-{{ $i }}-{{ $block['id'] ?? 'baru' }}-{{ trim($block['uraian_kegiatan']) !== '' ? 1 : 0 }}-{{ $block['jenis'] }}-{{ $block['tahapan_survei'] ?? '' }}"
                x-data="{
                    uraianTerisi: {{ trim($block['uraian_kegiatan']) !== '' ? 'true' : 'false' }},
                    uraianTeks: @js($block['uraian_kegiatan']),
                    jenisTerpilih: '{{ $block['jenis'] }}',
                    tahapanTerisi: {{ filled($block['tahapan_survei'] ?? null) ? 'true' : 'false' }},
                    tahapanNilai: '{{ $block['tahapan_survei'] ?? '' }}',
                    namaFolder: @js($block['nama_folder'] ?? ''),
                    ubahFolder: {{ filled($block['nama_folder'] ?? '') ? 'true' : 'false' }},
                    pendingBuktiNames: [],
                    get folderPreview() {
                        if (! this.uraianTeks.trim()) return '';
                        const prefix = (this.jenisTerpilih === 'survei_sensus' && this.tahapanNilai)
                            ? '[' + this.tahapanNilai.charAt(0).toUpperCase() + this.tahapanNilai.slice(1) + '] '
                            : '';
                        const full = prefix + this.uraianTeks;
                        return full.length > 100 ? full.slice(0, 100) : full;
                    }
                }">
                <div class="keg-head">
                    <span class="t">Kegiatan {{ $i + 1 }}</span>
                    @if ($block['status_dokumen'])
                        <x-badge-status :status="$block['status_dokumen']" />
                    @endif
                    @if (count($blocks) > 1)
                        @if ($block['id'])
                            <button type="button" class="btn btn-red btn-sm" @click="pendingHapus = { method: 'removeBlock', args: [{{ $i }}] }" wire:loading.class="btn-busy" wire:target="removeBlock({{ $i }})">🗑 Hapus</button>
                        @else
                            <button type="button" class="btn btn-red btn-sm" wire:click="removeBlock({{ $i }})" wire:loading.attr="disabled" wire:loading.class="btn-busy" wire:target="removeBlock({{ $i }})">🗑 Hapus</button>
                        @endif
                    @endif
                </div>

                <div class="field">
                    <label>Uraian Kegiatan <span class="req">*</span></label>

                    @if ($rtlBerjalanOptions->isNotEmpty())
                        <select class="inp filled" style="margin-bottom:8px"
                            wire:change="pilihRtlUntukBlock({{ $i }}, $event.target.value)"
                            @change="
                                if ($event.target.value !== '') {
                                    uraianTerisi = true;
                                    uraianTeks = $event.target.selectedOptions[0].dataset.teks;
                                } else {
                                    uraianTerisi = false;
                                    uraianTeks = '';
                                    $nextTick(() => $refs.uraianInput.focus());
                                }
                            ">
                            <option value="">— Ketik bebas (di luar rencana RTL) —</option>
                            @foreach ($rtlBerjalanOptions as $opsi)
                                @php
                                    $dipilihIni = (int) ($block['rtl_evaluasi_id'] ?? 0) === $opsi['poin']->id;
                                    // Jumlah pemakaian DI LUAR blok ini sendiri.
                                    $dipilihLain = $opsi['dipilih'] - ($dipilihIni ? 1 : 0);
                                @endphp
                                <option value="{{ $opsi['poin']->id }}" data-teks="{{ $opsi['poin']->rtl_teks }}" @selected($dipilihIni)>{{ $dipilihLain > 0 ? '✓ ' : '' }}{{ $opsi['poin']->rtl_teks }}@if ($dipilihLain > 0) — sudah dipilih {{ $dipilihLain }}×@if ($opsi['tahapan']) ({{ implode(', ', $opsi['tahapan']) }})@endif @endif</option>
                            @endforeach
                        </select>
                        <div class="fhint">💡 Pilih dari rencana RTL triwulan ini untuk menaut otomatis, atau pilih "Ketik bebas" lalu ketik sendiri uraiannya di kolom di bawah ini. RTL yang sudah dipilih ditandai ✓ dan tetap bisa dipilih lagi — mis. survei/sensus dengan beberapa tahapan dalam triwulan ini (tahapan yang sudah dipilih untuk kegiatan yang sama tidak bisa dipilih lagi).</div>
                    @endif

                    <input type="text" class="inp filled" x-ref="uraianInput" wire:model.live.blur="blocks.{{ $i }}.uraian_kegiatan"
                        @input="uraianTerisi = ($event.target.value.trim() !== ''); uraianTeks = $event.target.value"
                        placeholder="mis. Pencacahan rumah tangga Sakernas {{ $periodeLabel }}">
                    @error("blocks.{$i}.uraian_kegiatan")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field">
                    <label>Jenis Kegiatan <span class="req">*</span></label>

                    {{-- Terkunci/terbuka INSTAN di sisi klien begitu uraian diketik —
                         tidak menunggu balasan server (wire:model.blur baru sinkron
                         saat blur, tapi keterbukaan pill sudah lebih dulu terlihat). --}}
                    <div x-show="!uraianTerisi" x-cloak>
                        <div class="pills">
                            <span class="pill locked">Bukan Survei/Sensus</span>
                            <span class="pill locked">Survei/Sensus</span>
                        </div>
                        <div class="locked-note">🔒 Isi uraian kegiatan terlebih dahulu.</div>
                    </div>

                    <div x-show="uraianTerisi" x-cloak class="pills">
                        <span class="pill" :class="{ on: jenisTerpilih === 'bukan_survei_sensus' }"
                            @click="jenisTerpilih = 'bukan_survei_sensus'"
                            wire:click="$set('blocks.{{ $i }}.jenis', 'bukan_survei_sensus')">Bukan Survei/Sensus</span>
                        <span class="pill" :class="{ on: jenisTerpilih === 'survei_sensus' }"
                            @click="jenisTerpilih = 'survei_sensus'"
                            wire:click="$set('blocks.{{ $i }}.jenis', 'survei_sensus')">Survei/Sensus</span>
                    </div>
                    @error("blocks.{$i}.jenis")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field" x-show="jenisTerpilih === 'survei_sensus'" x-cloak>
                    <label>Tahapan Survei <span class="req">*</span> <span style="color:var(--muted);font-weight:500">— boleh pilih lebih dari satu</span></label>
                    {{-- Tahapan yang sudah dipilih untuk kegiatan yang SAMA (poin RTL sama
                         atau uraian sama) di blok lain/bulan lain triwulan ini tidak bisa
                         dipilih lagi — lihat PengisianKegiatan::tahapanTerpakai(). --}}
                    @php
                        $tahapanTerpakai = $this->tahapanTerpakai($i);
                        $tahapanBlok = $this->tahapanDipilih($block);
                    @endphp
                    <div class="pills">
                        @foreach (\App\Livewire\PengisianKegiatan::DAFTAR_TAHAPAN as $nilaiTahapan => $labelTahapan)
                            @php
                                $tahapanAktif = in_array($nilaiTahapan, $tahapanBlok, true);
                                $sudahDipakai = ! $tahapanAktif && in_array($nilaiTahapan, $tahapanTerpakai, true);
                            @endphp
                            <span class="pill {{ $tahapanAktif ? 'on' : '' }} {{ $sudahDipakai ? 'locked' : '' }}"
                                @if ($sudahDipakai) title="Sudah dipilih untuk kegiatan ini pada triwulan ini"
                                @else wire:click="toggleTahapan({{ $i }}, '{{ $nilaiTahapan }}')" wire:loading.class="btn-busy" wire:target="toggleTahapan({{ $i }}, '{{ $nilaiTahapan }}')" @endif
                            >{{ $tahapanAktif ? '☑' : '☐' }} {{ $labelTahapan }}</span>
                        @endforeach
                    </div>
                    @if (count($tahapanBlok) > 1)
                        <div class="fhint">🗂️ {{ count($tahapanBlok) }} tahapan dipilih — saat disimpan, tiap tahapan tercatat sebagai kegiatan sendiri (diverifikasi terpisah oleh Tim SAKIP) dengan folder masing-masing di dalam satu folder kegiatan. Pilih tahapan tujuan pada tiap berkas bukti di bawah.</div>
                    @endif
                    @if ($tahapanTerpakai)
                        <div class="fhint">🔁 Kegiatan ini sudah tercatat pada tahapan {{ collect($tahapanTerpakai)->map(fn ($t) => ucfirst($t))->join(', ', ' dan ') }} di triwulan ini — pilih tahapan lain.</div>
                    @endif
                    @error("blocks.{$i}.tahapan_survei")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                    @foreach ($errors->getMessages() as $kunciGalat => $pesanGalat)
                        @if (str_starts_with($kunciGalat, "blocks.{$i}.tahapan_tambahan"))
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $pesanGalat[0] }}</div>
                        @endif
                    @endforeach
                </div>

                {{-- Rekomendasi nama folder dari sistem, bisa diganti Ketua Tim (kosongkan
                     untuk kembali ke rekomendasi). Disimpan saat Simpan Draft/Ajukan; bila
                     folder sudah ada di Drive, namanya ikut diganti di sana. --}}
                @php $tahapanBlok = $block['jenis'] === 'survei_sensus' ? $this->tahapanDipilih($block) : []; @endphp
                @if (count($tahapanBlok) > 1)
                    {{-- Beberapa tahapan: satu folder induk kegiatan, tiap tahapan jadi
                         subfolder di dalamnya (nama tetap diikuti nama kegiatan). --}}
                    <div class="field">
                        <div class="foldername">📁 {{ \App\Services\FolderStructureService::namaOtomatis($block['uraian_kegiatan'], 150) }}</div>
                        @foreach ($tahapanBlok as $ti => $nilaiTahapan)
                            <div class="foldername" style="margin-left:18px;margin-top:4px">
                                ↳ 📁 {{ $ti === 0 && filled($block['nama_folder'] ?? '') ? $this->namaFolderBerlaku($i) : $this->namaFolderOtomatis(array_merge($block, ['tahapan_survei' => $nilaiTahapan])) }}
                            </div>
                        @endforeach
                        <div class="fhint">Nama folder tiap tahapan bisa diganti lewat ✏️ setelah disimpan.</div>
                    </div>
                @else
                <div x-show="folderPreview !== ''" x-cloak class="field">
                    {{-- Kegiatan survei/sensus dikelompokkan dalam folder induk bernama
                         uraian kegiatan (lihat FolderStructureService::resolveKegiatanFolder()). --}}
                    <div class="foldername" x-show="jenisTerpilih === 'survei_sensus'">📁 <span x-text="uraianTeks.trim()"></span></div>
                    <div class="foldername" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap" :style="jenisTerpilih === 'survei_sensus' ? 'margin-left:18px;margin-top:4px' : ''">
                        <span x-show="jenisTerpilih === 'survei_sensus'">↳</span> 📁 <span x-text="namaFolder.trim() !== '' ? namaFolder.trim() : folderPreview"></span>
                        <span x-show="namaFolder.trim() === ''" style="color:var(--muted);font-size:11px">(rekomendasi sistem)</span>
                        <button type="button" class="btn-ubah-nama" title="Ganti nama folder" x-show="!ubahFolder" @click="ubahFolder = true; $nextTick(() => $refs.namaFolderInput.focus())">✏️</button>
                    </div>
                    <div x-show="ubahFolder" x-cloak style="margin-top:6px">
                        <input type="text" class="inp filled" maxlength="150" x-ref="namaFolderInput"
                            wire:model.live.blur="blocks.{{ $i }}.nama_folder"
                            @input="namaFolder = $event.target.value"
                            @keydown.enter.prevent="$event.target.blur(); ubahFolder = false"
                            :placeholder="folderPreview">
                        <div class="fhint">Kosongkan untuk memakai rekomendasi sistem. Nama folder tersimpan saat Simpan Draft/Ajukan.</div>
                    </div>
                    @error("blocks.{$i}.nama_folder")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
                @endif

                {{-- Bawaan semua bukti masuk SATU folder kegiatan; bila perlu dipisah,
                     Ketua Tim menambah subfolder khusus di dalam folder kegiatan dan
                     memilih subfolder tujuan pada tiap berkas di bawah. --}}
                @php $pakaiSubfolder = ! empty($block['pakai_subfolder']); @endphp
                <div class="field" x-show="jenisTerpilih !== ''" x-cloak>
                    <label>Susunan Folder Bukti</label>
                    <div class="pills">
                        <span class="pill {{ $pakaiSubfolder ? '' : 'on' }}" wire:click="$set('blocks.{{ $i }}.pakai_subfolder', false)">1 folder (bawaan)</span>
                        <span class="pill {{ $pakaiSubfolder ? 'on' : '' }}" wire:click="$set('blocks.{{ $i }}.pakai_subfolder', true)">Pisah ke beberapa subfolder</span>
                    </div>
                    @if ($pakaiSubfolder)
                        <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                            @foreach ($block['subfolders'] ?? [] as $si => $namaSub)
                                <span class="pill on" wire:key="subfolder-{{ $i }}-{{ md5($namaSub) }}">📁 {{ $namaSub }}
                                    <span style="cursor:pointer;margin-left:4px" title="Hapus subfolder" wire:click="hapusSubfolder({{ $i }}, {{ $si }})">✕</span>
                                </span>
                            @endforeach
                            @foreach (\App\Livewire\PengisianKegiatan::SARAN_SUBFOLDER as $saran)
                                @if (! in_array(mb_strtolower($saran), array_map('mb_strtolower', $block['subfolders'] ?? []), true))
                                    <span class="pill" style="border-style:dashed" wire:click="tambahSubfolder({{ $i }}, @js($saran))">＋ {{ $saran }}</span>
                                @endif
                            @endforeach
                        </div>
                        <div x-data="{ namaSub: '' }" style="margin-top:8px;display:flex;gap:6px">
                            <input type="text" class="inp" maxlength="100" x-model="namaSub" placeholder="Nama subfolder lain…" data-abaikan-perubahan
                                @keydown.enter.prevent="if (namaSub.trim()) { $wire.tambahSubfolder({{ $i }}, namaSub); namaSub = '' }">
                            <button type="button" class="btn btn-ghost btn-sm" @click="if (namaSub.trim()) { $wire.tambahSubfolder({{ $i }}, namaSub); namaSub = '' }">＋ Tambah</button>
                        </div>
                        <div class="fhint">Subfolder dibuat di dalam folder kegiatan{{ count($tahapanBlok) > 1 ? ' (di setiap folder tahapan)' : '' }}. Pilih subfolder tujuan pada tiap berkas di bawah — berkas tanpa pilihan masuk langsung ke folder kegiatan.</div>
                    @endif
                </div>

                <div class="field" style="margin-bottom:0">
                    <label>Bukti Capaian (PDF) @if (empty($block['existing_bukti']))<span class="req">wajib</span>@endif</label>

                    @if ($block['catatan_bukti_dihapus'] ?? null)
                        <div class="info red" style="margin-bottom:8px;font-size:11.5px">
                            🗑️ <b>Bukti sebelumnya dihapus karena:</b> "{{ $block['catatan_bukti_dihapus'] }}" — pastikan bukti pengganti sudah memperbaiki hal ini.
                        </div>
                    @endif

                    @if (! empty($block['existing_bukti']))
                        <div class="filechip-grid">
                            @foreach ($block['existing_bukti'] as $file)
                                <div class="filechip {{ $file['status_verifikasi'] === 'terverifikasi' ? 'ok' : ($file['status_verifikasi'] === 'ditolak' ? 'no' : '') }}">
                                    <span class="nm">
                                        <x-nama-berkas :id="$file['id']" :nama="$file['nama_file']" :diunggah-oleh="$file['diunggah_oleh'] ?? null" />
                                        @if (filled($file['subfolder'] ?? null))
                                            <span class="sub">📁 {{ $file['subfolder'] }}</span>
                                        @endif
                                        @if ($file['status_verifikasi'] === 'ditolak' && $file['catatan'])
                                            <span class="sub" style="color:var(--red)">{{ $file['catatan'] }}</span>
                                        @endif
                                    </span>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file['id'] }}">🔍 Lihat</button>
                                    @if ($file['status_verifikasi'] !== 'terverifikasi')
                                        <span class="x" style="cursor:pointer" title="Hapus bukti ini" @click="pendingHapus = { method: 'hapusBuktiLama', args: [{{ $i }}, {{ $file['id'] }}] }" wire:loading.class="btn-busy" wire:target="hapusBuktiLama({{ $i }}, {{ $file['id'] }})">🗑️</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div x-show="jenisTerpilih === '' || (jenisTerpilih === 'survei_sensus' && !tahapanTerisi)" x-cloak
                        class="locked-note" style="padding:14px;border:1.5px dashed var(--line);border-radius:10px;text-align:center">
                        🔒 Lengkapi jenis kegiatan<span x-show="jenisTerpilih === 'survei_sensus'"> dan tahapan survei</span> terlebih dahulu untuk mengunggah bukti.
                    </div>

                    <div x-show="jenisTerpilih !== '' && (jenisTerpilih !== 'survei_sensus' || tahapanTerisi)" x-cloak>
                        @if (empty($block['bukti']))
                            <label class="upload need" style="cursor:pointer;display:block">
                                <div class="big">📤</div>
                                Klik untuk unggah bukti capaian (PDF) — boleh lebih dari satu berkas
                                <input type="file" wire:model="blocks.{{ $i }}.bukti" multiple accept="application/pdf" style="display:none"
                                    @change="pendingBuktiNames = Array.from($event.target.files).map(f => f.name)">
                            </label>
                        @else
                            <x-mode-nama-berkas :mode="$modeNamaBerkas" />
                            <div class="filechip-grid">
                                @foreach ($block['bukti'] as $fi => $file)
                                    {{-- TANPA kelas "ok" (hijau) — berkas ini baru dipilih, belum
                                         pernah diperiksa Tim SAKIP sama sekali, jadi belum punya
                                         status apa-apa (bukan "Sesuai"). Hijau baru muncul begitu
                                         benar-benar ditandai "Sesuai" (lihat existing_bukti di atas). --}}
                                    @php
                                        $kunciBaru = $this->kunciBuktiBaru($file);
                                        $namaTampil = $this->namaBuktiBaruKegiatan($block, $file);
                                        $tahapanBerkas = $this->tahapanBerkasBaru($block, $file);
                                        $subfolderBerkas = $this->subfolderBerkasBaru($block, $file);
                                    @endphp
                                    <div class="filechip" wire:key="bukti-baru-{{ $kunciBaru }}">
                                        <span class="nm">
                                            <x-nama-berkas :id="$kunciBaru" :nama="$namaTampil.'.pdf'" aksi="aturNamaBuktiBaru" :bisa-ubah="true" />
                                            <span class="sub">Berkas asli: {{ $file->getClientOriginalName() }}</span>
                                            @if (count($tahapanBlok) > 1 || ($pakaiSubfolder && ! empty($block['subfolders'])))
                                                <span style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
                                                    @if (count($tahapanBlok) > 1)
                                                        <select class="inp" style="width:auto;padding:3px 6px;font-size:11.5px" title="Tahapan tujuan berkas ini"
                                                            wire:change="$set('tahapanBuktiBaru.{{ $kunciBaru }}', $event.target.value)">
                                                            @foreach ($tahapanBlok as $nilaiTahapan)
                                                                <option value="{{ $nilaiTahapan }}" @selected($tahapanBerkas === $nilaiTahapan)>Tahapan: {{ \App\Livewire\PengisianKegiatan::DAFTAR_TAHAPAN[$nilaiTahapan] }}</option>
                                                            @endforeach
                                                        </select>
                                                    @endif
                                                    @if ($pakaiSubfolder && ! empty($block['subfolders']))
                                                        <select class="inp" style="width:auto;padding:3px 6px;font-size:11.5px" title="Subfolder tujuan berkas ini"
                                                            wire:change="$set('subfolderBuktiBaru.{{ $kunciBaru }}', $event.target.value)">
                                                            <option value="" @selected($subfolderBerkas === '')>📁 Langsung di folder kegiatan</option>
                                                            @foreach ($block['subfolders'] as $namaSub)
                                                                <option value="{{ $namaSub }}" @selected($subfolderBerkas === $namaSub)>📁 {{ $namaSub }}</option>
                                                            @endforeach
                                                        </select>
                                                    @endif
                                                </span>
                                            @endif
                                        </span>
                                        <span class="x" style="cursor:pointer" title="Hapus bukti" wire:click="removeBuktiKegiatan({{ $i }}, {{ $fi }})" wire:loading.class="btn-busy" wire:target="removeBuktiKegiatan({{ $i }}, {{ $fi }})">🗑️</span>
                                    </div>
                                @endforeach
                            </div>
                            <label class="btn btn-ghost btn-sm" style="margin-top:8px;cursor:pointer">
                                ＋ Tambah Bukti
                                <input type="file" wire:model="blocks.{{ $i }}.bukti" multiple accept="application/pdf" style="display:none"
                                    @change="pendingBuktiNames = Array.from($event.target.files).map(f => f.name)">
                            </label>
                        @endif
                    </div>

                    {{-- Nama berkas dipilih langsung tampil di sisi klien (dari File API
                         browser) begitu dipilih — tidak menunggu unggahan ke server
                         selesai dulu baru terlihat namanya. --}}
                    <div wire:loading wire:target="blocks.{{ $i }}.bukti" style="font-size:11.5px;color:var(--muted);margin-top:6px">
                        <template x-for="nama in pendingBuktiNames" :key="nama">
                            <div>📄 <span x-text="nama"></span> — mengunggah…</div>
                        </template>
                        <div x-show="pendingBuktiNames.length === 0">Mengunggah berkas…</div>
                    </div>

                    @error("blocks.{$i}.bukti")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                    @error("blocks.{$i}.bukti.*")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            @endif
        @endforeach

        <button type="button" class="btn btn-ghost btn-sm" x-on:click="jagaScroll(() => $wire.addBlock())" wire:loading.attr="disabled" wire:target="addBlock" @disabled($formTerkunciDisetujui || $formTerkunciSedangDitangani)>
            <span wire:loading.remove wire:target="addBlock">＋ Tambah Kegiatan</span>
            <span wire:loading wire:target="addBlock">Menambahkan…</span>
        </button>
        </div>
        </div>

        <div class="sec" style="margin-top:20px"><span class="n">3</span><span>Kendala &amp; Solusi</span></div>

        <div x-show="!ikuDipilih" class="info warn">🔒 Pilih IKU terlebih dahulu (Bagian 1) untuk mengisi kendala &amp; solusi.</div>

        <div x-show="ikuDipilih" x-cloak>
        <div wire:loading wire:target="iku_id,bulan,tahun" class="info">⏳ Memuat kendala &amp; solusi yang sudah pernah diisi untuk IKU &amp; periode ini, kalau ada…</div>

        <div wire:loading.remove wire:target="iku_id,bulan,tahun">
        <div class="info">ℹ️ {{ $bulanKe === 3 ? 'Wajib minimal satu pasangan Kendala & Solusi untuk triwulan ini sebelum diajukan (boleh sudah diisi pada bulan sebelumnya di triwulan yang sama).' : 'Boleh dikosongkan bulan ini bila tidak ada kendala — tapi wajib minimal satu pasangan sudah tercatat sebelum diajukan pada bulan terakhir triwulan.' }}</div>

        @error('kendalaBlocks')
            <div class="info red">⚠️ {{ $message }}</div>
        @enderror

        @if ($iku_id && $riwayatKendala->isNotEmpty())
            <div style="margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">📜 Riwayat Kumulatif Triwulan Berjalan</div>
                @foreach ($riwayatKendala as $triwulanKe => $entriTriwulan)
                    <div style="margin-bottom:10px">
                        <div style="font-size:11px;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">
                            Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulanKe - 1] }}
                        </div>
                        <div style="display:grid;gap:8px">
                            @foreach ($entriTriwulan as $entri)
                                <div style="padding:12px 14px;border:1.5px solid var(--line);border-radius:11px;background:var(--bg);font-size:12.5px">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                                        <span style="font-size:10.5px;color:var(--muted)">🔒 Terkunci — sudah diterima Tim SAKIP</span>
                                    </div>
                                    <div class="row2">
                                        <div>
                                            <div style="font-size:10.5px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px">Kendala</div>
                                            <div>{{ $entri->kendala }}</div>
                                        </div>
                                        <div>
                                            <div style="font-size:10.5px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px">Solusi</div>
                                            <div>{{ $entri->solusi ?: '—' }}</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($kendalaAktif->isNotEmpty())
            <div style="margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">📤 Sudah Diajukan — Menunggu Tim SAKIP</div>
                @foreach ($kendalaAktif as $ks)
                    <div class="poin-row" wire:key="kendala-aktif-{{ $ks->id }}">
                        <span class="k-num">Pasangan {{ $loop->iteration }}</span>
                        <div style="position:absolute;top:-9px;right:12px">
                            @if ($ks->status_verifikasi === 'terverifikasi')
                                <x-badge-status status="disetujui" label="Terverifikasi Tim SAKIP" />
                            @else
                                <x-badge-status status="diajukan" label="Menunggu Verifikasi Tim SAKIP" />
                            @endif
                        </div>

                        <div class="field" style="margin-bottom:0">
                            <label>Kendala</label>
                            <p style="margin:0;font-size:13px">{{ $ks->kendala }}</p>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>Solusi</label>
                            <p style="margin:0;font-size:13px">{{ $ks->solusi ?: '—' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @foreach ($kendalaBlocks as $i => $block)
            <div class="poin-row" wire:key="kendala-{{ $i }}">
                <span class="k-num">Pasangan {{ $i + 1 }}{{ $kendalaAktif->isNotEmpty() && ! ($block['id'] ?? null) ? ' (Baru)' : '' }}</span>
                @if ($block['status_verifikasi'] === 'ditolak')
                    <span class="badge b-kembali" style="position:absolute;top:-9px;right:12px">✕ Tidak Sesuai (Tim SAKIP)</span>
                @endif
                @if (count($kendalaBlocks) > 1 && ! ($block['id'] ?? null))
                    <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="removeKendalaBlock({{ $i }})" wire:loading.attr="disabled" wire:loading.class="btn-busy" wire:target="removeKendalaBlock({{ $i }})">🗑</button>
                @endif

                @if ($block['status_verifikasi'] === 'ditolak' && $block['catatan'])
                    <div style="grid-column:1/-1;font-size:11.5px;color:var(--red);margin-bottom:2px">📝 {{ $block['catatan'] }} — perbaiki lalu ajukan ulang.</div>
                @endif

                <div class="field" style="margin-bottom:0">
                    <label>Kendala</label>
                    <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live.blur="kendalaBlocks.{{ $i }}.kendala"
                        placeholder="mis. Keterlambatan pengumpulan dokumen dari mitra"></textarea>
                    @error("kendalaBlocks.{$i}.kendala")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field" style="margin-bottom:0">
                    <label>Solusi</label>
                    <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live.blur="kendalaBlocks.{{ $i }}.solusi"
                        placeholder="mis. Percepatan koordinasi dengan mitra terkait"></textarea>
                    @error("kendalaBlocks.{$i}.solusi")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        @endforeach

        <button type="button" class="btn btn-ghost btn-sm" x-on:click="jagaScroll(() => $wire.addKendalaBlock())" wire:loading.attr="disabled" wire:target="addKendalaBlock" @disabled($formTerkunciSedangDitangani)>
            <span wire:loading.remove wire:target="addKendalaBlock">＋ Tambah Pasangan Kendala &amp; Solusi</span>
            <span wire:loading wire:target="addKendalaBlock">Menambahkan…</span>
        </button>
        </div>
        </div>

        <div class="sec" style="margin-top:20px"><span class="n">4</span><span>Evaluasi RTL Triwulan Sebelumnya</span></div>

        <div x-show="!ikuDipilih" class="info warn">🔒 Pilih IKU terlebih dahulu (Bagian 1) untuk melihat evaluasi RTL.</div>

        <div x-show="ikuDipilih" x-cloak>
            <div wire:loading wire:target="iku_id,bulan,tahun" class="info">⏳ Memuat data RTL &amp; evaluasi untuk IKU ini…</div>

            <div wire:loading.remove wire:target="iku_id,bulan,tahun">
            <div class="info teal">✅ Poin di bawah adalah RTL yang ditetapkan pada triwulan sebelumnya untuk dilaksanakan triwulan ini — sama dengan yang muncul sebagai pilihan dropdown uraian kegiatan di Bagian 2. Lampirkan bukti realisasinya (boleh lebih dari satu berkas) — {{ $bulanKe === 3 ? 'WAJIB semua poin sudah punya bukti sebelum bisa diajukan pada bulan terakhir triwulan ini.' : 'opsional untuk bulan ini, tapi wajib sudah lengkap semua sebelum bulan terakhir triwulan.' }}</div>

            @if ($rtlSebelumnya->isEmpty())
                <p style="color:var(--muted);font-size:13px">Tidak ada poin RTL triwulan sebelumnya untuk IKU ini.</p>
            @endif

            @if ($rtlBerjalanBelumTerlaksana->isNotEmpty())
                <div class="info red" style="margin-bottom:8px">
                    ⚠️ {{ $rtlBerjalanBelumTerlaksana->count() }} poin RTL di bawah belum pernah dipilih lewat dropdown uraian kegiatan pada triwulan ini.
                    Boleh diganti kegiatan lain di luar rencana RTL — asal jumlah kegiatan yang diisi triwulan ini minimal sama banyak dengan jumlah poin RTL ({{ $rtlSebelumnya->count() }}) dan bukti realisasi semua poin di bawah sudah lengkap, sebelum bisa diajukan ke Tim SAKIP di bulan terakhir triwulan ({{ $this->labelBulanTerakhirTriwulanIni() }}).
                </div>
            @endif

            @foreach ($rtlSebelumnya as $poin)
                <div class="poin-single" wire:key="rtl-{{ $poin->id }}" x-data="{ pendingBuktiRealisasiNames: [] }">
                    <span class="k-num stat-in">Poin {{ $loop->iteration }}</span>
                    <div class="rtl-planned">
                        <div>
                            <span class="pl-lbl">Direncanakan ({{ $poin->berlaku_bulan }})</span>
                            {{ $poin->rtl_teks }}
                            @if ($rtlBerjalanBelumTerlaksana->contains('id', $poin->id))
                                <span class="badge b-tunggu" style="margin-left:6px">Belum dipilih sbg kegiatan</span>
                            @else
                                <span class="badge b-approve" style="margin-left:6px">Sudah dipilih sbg kegiatan</span>
                            @endif
                            <div style="color:var(--muted);font-size:11px;margin-top:4px">PIC: {{ $poin->pic }} · Batas waktu: {{ $poin->batas_waktu?->translatedFormat('d F Y') }}</div>
                        </div>
                    </div>

                    <div class="field" style="margin:12px 0 0">
                        <label>Bukti Realisasi (PDF)
                            @if ($bulanKe === 3)
                                <span class="req">wajib minimal 1 berkas</span>
                            @else
                                <span style="color:var(--muted);font-weight:500">opsional bulan ini</span>
                            @endif
                        </label>

                        @if ($poin->catatan_bukti_dihapus)
                            <div class="info red" style="margin-bottom:8px;font-size:11.5px">
                                🗑️ <b>Bukti sebelumnya dihapus karena:</b> "{{ $poin->catatan_bukti_dihapus }}" — pastikan bukti pengganti sudah memperbaiki hal ini.
                            </div>
                        @endif

                        @if (! empty($evaluasi[$poin->id]['bukti'] ?? []))
                            <x-mode-nama-berkas :mode="$modeNamaBerkas" />
                        @endif

                        <div class="filechip-grid">
                            @foreach ($poin->berkas as $file)
                                <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}">
                                    <span class="nm">
                                        <x-nama-berkas :id="$file->id" :nama="$file->nama_file" :diunggah-oleh="$file->diunggah_oleh" />
                                        @if ($file->status_verifikasi === 'ditolak' && $file->catatan)
                                            <span class="sub" style="color:var(--red)">{{ $file->catatan }}</span>
                                        @endif
                                    </span>
                                    @if (! $evaluasiTerkunci && $file->status_verifikasi !== 'terverifikasi')
                                        <span class="x" style="cursor:pointer" title="Hapus bukti ini" @click="pendingHapus = { method: 'hapusBuktiLamaEvaluasi', args: [{{ $poin->id }}, {{ $file->id }}] }" wire:loading.class="btn-busy" wire:target="hapusBuktiLamaEvaluasi({{ $poin->id }}, {{ $file->id }})">🗑️</span>
                                    @endif
                                </div>
                            @endforeach

                            @foreach ($evaluasi[$poin->id]['bukti'] ?? [] as $fi => $file)
                                @php
                                    $kunciBaru = $this->kunciBuktiBaru($file);
                                    $namaTampil = $this->namaBuktiBaruAtauDefault($file, $this->namaDefaultBuktiBaru('Evaluasi RTL '.$poin->rtl_teks, $poin->berkas->count(), $loop->index, count($evaluasi[$poin->id]['bukti'])));
                                @endphp
                                <div class="filechip" wire:key="bukti-baru-{{ $kunciBaru }}">
                                    <span class="nm">
                                        <x-nama-berkas :id="$kunciBaru" :nama="$namaTampil.'.pdf'" aksi="aturNamaBuktiBaru" :bisa-ubah="true" />
                                        <span class="sub">Berkas asli: {{ $file->getClientOriginalName() }}</span>
                                    </span>
                                    <span class="x" style="cursor:pointer" title="Hapus bukti" wire:click="removeBuktiEvaluasi({{ $poin->id }}, {{ $fi }})" wire:loading.class="btn-busy" wire:target="removeBuktiEvaluasi({{ $poin->id }}, {{ $fi }})">🗑️</span>
                                </div>
                            @endforeach
                        </div>

                        <label class="upload" style="cursor:pointer;display:block;padding:10px;margin-top:8px">
                            <div style="font-weight:600;font-size:11.5px;color:var(--blue-600)">📤 Tambah bukti realisasi (PDF) — boleh lebih dari satu</div>
                            <input type="file" wire:model="evaluasi.{{ $poin->id }}.bukti" multiple accept="application/pdf" style="display:none"
                                @change="pendingBuktiRealisasiNames = Array.from($event.target.files).map(f => f.name)">
                        </label>

                        <div wire:loading wire:target="evaluasi.{{ $poin->id }}.bukti" style="font-size:11.5px;color:var(--muted);margin-top:6px">
                            <template x-for="nama in pendingBuktiRealisasiNames" :key="nama">
                                <div>📄 <span x-text="nama"></span> — mengunggah…</div>
                            </template>
                        </div>

                        @error("evaluasi.{$poin->id}.bukti.*")
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            @endforeach
            </div>
        </div>

        <div class="sec" style="margin-top:20px"><span class="n">5</span><span>Rencana Tindak Lanjut ({{ $labelBerikutnya }})</span></div>

        <div x-show="!ikuDipilih" class="info warn">🔒 Pilih IKU terlebih dahulu (Bagian 1) untuk mengisi RTL.</div>

        <div x-show="ikuDipilih" x-cloak>
            <div wire:loading wire:target="iku_id,bulan,tahun" class="info">⏳ Memuat…</div>

            <div wire:loading.remove wire:target="iku_id,bulan,tahun">
            <div style="font-size:11.5px;color:var(--muted);margin:-8px 0 12px">
                {{ $labelBerikutnya }} berarti ({{ $bulanTargetBerikutnya->values()->join(', ', ', dan ') }}).
            </div>

            @if ($sudahAdaRtlBerikutnya)
                <div class="badge b-approve" style="display:inline-block;margin-bottom:10px">Sudah ditetapkan</div>
                <p style="color:var(--muted);font-size:12.5px;margin-bottom:12px">
                    RTL untuk {{ $labelBerikutnya }} sudah ditetapkan dan tampil hanya-baca — tidak bisa diubah lagi dari sini, tapi Anda masih bisa menambahkan poin baru di bawah selagi isian ini belum selesai diverifikasi Tim SAKIP.
                </p>

                @foreach ($rtlBerikutnyaAktif as $poin)
                    <div class="poin-single" wire:key="rtlberikutnya-aktif-{{ $poin->id }}">
                        <span class="k-num stat-in">Poin RTL {{ $loop->iteration }}</span>
                        <div class="field" style="margin-bottom:8px">
                            <label>Rencana kegiatan</label>
                            <p style="margin:0;font-size:13px">{{ $poin->rtl_teks }}</p>
                        </div>
                        <div>
                            @if ($poin->status_verifikasi === 'terverifikasi')
                                <x-badge-status status="disetujui" label="Terverifikasi Tim SAKIP" />
                            @else
                                <x-badge-status status="diajukan" label="Menunggu Verifikasi Tim SAKIP" />
                            @endif
                        </div>
                    </div>
                @endforeach

                {{-- PIC & Batas Waktu berlaku untuk SELURUH poin di atas (satu nilai per
                     batch, bukan per poin — sama seperti form pengisiannya, lihat baris
                     "Berlaku untuk seluruh poin RTL ... di atas" pada form edit) — cukup
                     ditampilkan sekali di sini, tidak diulang di tiap kartu poin. Poin
                     BARU yang ditambahkan di bawah otomatis ikut PIC & Batas Waktu yang
                     sama ini (lihat PengisianKegiatan::simpanBagianIsian()). --}}
                @if ($rtlBerikutnyaAktif->isNotEmpty())
                    <div class="row2" style="margin-top:4px">
                        <div class="field" style="margin-bottom:0"><label>PIC Tindak Lanjut</label><p style="margin:0;font-size:13px">{{ $rtlBerikutnyaAktif->first()->pic }}</p></div>
                        <div class="field" style="margin-bottom:0"><label>Batas Waktu</label><p style="margin:0;font-size:13px">{{ $rtlBerikutnyaAktif->first()->batas_waktu?->translatedFormat('d F Y') }}</p></div>
                    </div>
                @endif
            @endif

            @if ($rtlBerikutnyaDitolak->isNotEmpty())
                <div class="info red" style="margin-bottom:10px">
                    ❌ <b>Tim SAKIP mengembalikan rencana {{ $labelBerikutnya }} ini untuk diperbaiki:</b>
                    <ul style="margin:6px 0 0;padding-left:18px">
                        @foreach ($rtlBerikutnyaDitolak as $poin)
                            @if ($poin->catatan)
                                <li>{{ $poin->catatan }}</li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Sisi klien menghitung sendiri "bulan terakhir triwulan?" (murni dari
                 $bulan, tidak butuh query) lewat bisaDiisiTw — jadi bagian ini langsung
                 berubah begitu bulan dipilih, tidak menunggu balasan server. --}}
            <div x-show="!bisaDiisiTw" x-cloak class="info warn">
                ⏳ RTL untuk {{ $labelBerikutnya }} baru bisa diisi pada bulan terakhir triwulan berjalan
                (<b>{{ $this->labelBulanTerakhirTriwulanIni() }}</b>).
            </div>

            <div x-show="bisaDiisiTw" x-cloak>
            <div class="info warn">
                @if ($sudahAdaRtlBerikutnya)
                    ➕ Tambahkan poin RTL baru untuk {{ $labelBerikutnya }} di bawah bila ada (opsional) — poin yang sudah ditetapkan di atas tidak bisa diubah lagi dari sini.
                @else
                    ⚠️ Tulis RTL per poin untuk {{ $labelBerikutnya }} secara keseluruhan. Poin ini dicek realisasinya pada triwulan berikutnya.
                @endif
            </div>

            @error('rtlBaru')
                <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
            @enderror

            @foreach ($rtlBaru as $i => $blok)
                <div class="poin-single" wire:key="rtlbaru-{{ $i }}">
                    <span class="k-num stat-in">Poin RTL {{ $i + 1 }}{{ $sudahAdaRtlBerikutnya && ! ($blok['id'] ?? null) ? ' (Baru)' : '' }}</span>
                    @if (count($rtlBaru) > 1 && ! ($blok['id'] ?? null))
                        <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="removeRtlBlock({{ $i }})" wire:loading.attr="disabled" wire:loading.class="btn-busy" wire:target="removeRtlBlock({{ $i }})">🗑</button>
                    @endif

                    <div class="field" style="margin-bottom:0">
                        <label>Rencana kegiatan @unless ($sudahAdaRtlBerikutnya)<span class="req">*</span>@endunless</label>
                        <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live.blur="rtlBaru.{{ $i }}.rtl_teks"
                            placeholder="mis. Pelatihan innas dan persiapan Susenas September 2026"></textarea>
                        @error("rtlBaru.{$i}.rtl_teks")
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            @endforeach

            <button type="button" class="btn btn-ghost btn-sm" x-on:click="jagaScroll(() => $wire.addRtlBlock())" wire:loading.attr="disabled" wire:target="addRtlBlock" @disabled($formTerkunciSedangDitangani)>
                <span wire:loading.remove wire:target="addRtlBlock">＋ Tambah Poin RTL</span>
                <span wire:loading wire:target="addRtlBlock">Menambahkan…</span>
            </button>

            @unless ($sudahAdaRtlBerikutnya)
                <div class="row2" style="margin-top:14px">
                    {{-- PIC hanya-baca: otomatis = tim penanggung jawab IKU (Penugasan IKU /
                         Master IKU), tidak diisi Ketua Tim -- lihat
                         PengisianKegiatan::pilihPicOtomatis() & MasterIku::sinkronkanTim(). --}}
                    <div class="field"><label>PIC Tindak Lanjut <span class="muted" style="font-weight:400;font-size:11px">(otomatis)</span></label>
                        <div class="inp" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;height:auto;min-height:42px;background:var(--line2, #f3f5f9)">
                            @forelse ($rtlBaruPicTerpilih as $tim)
                                <span class="chip chip-tim" wire:key="rtl-pic-{{ $loop->index }}">{{ $tim }}</span>
                            @empty
                                <span class="muted" style="font-size:11.5px">— Tim IKU ini belum diatur (akan diisi Tim SAKIP) —</span>
                            @endforelse
                        </div>
                        <div class="fhint">Otomatis dari tim penanggung jawab IKU (menu Penugasan IKU / Master IKU) — tidak perlu diisi. Ikut berubah bila tim IKU diperbarui.</div>
                    </div>
                    <div class="field"><label>Batas Waktu <span class="req">*</span></label>
                        <input type="date" class="inp filled" wire:model.live.blur="rtlBaruBatasWaktu">
                        <div class="fhint">Bawaan: akhir {{ $labelBerikutnya }}.</div>
                        @error('rtlBaruBatasWaktu')
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            @endunless
            </div>

            @foreach ($bagianKustomAktif as $bagian)
                <div class="sec" style="margin-top:20px"><span class="n">🧩</span><span>{{ $bagian->nama }}</span></div>
                @if ($bagian->deskripsi)
                    <div class="info">ℹ️ {{ $bagian->deskripsi }}</div>
                @endif
                @php $labelBukti = $bagian->bukti_wajib ? 'Tiap poin yang diisi wajib dilampiri bukti dukung (PDF).' : 'Bukti dukung (PDF) opsional untuk bagian ini.'; @endphp
                @if ($bagian->frekuensi_wajib === 'setiap_bulan')
                    <div class="info warn">⚠️ Minimal satu poin wajib diisi setiap bulan. {{ $labelBukti }}</div>
                @elseif ($bagian->frekuensi_wajib === 'akhir_triwulan')
                    <div class="info warn">⚠️ Minimal satu poin wajib diisi sebelum diajukan pada bulan terakhir triwulan ini ({{ $bulanKe === 3 ? 'berlaku sekarang' : 'berlaku mulai bulan ke-3 triwulan' }}). {{ $labelBukti }}</div>
                @else
                    <div class="info">ℹ️ Bagian ini opsional. {{ $labelBukti }}</div>
                @endif

                @if (($riwayatBagianKustom[$bagian->id] ?? collect())->isNotEmpty())
                    <div style="margin-bottom:14px">
                        <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Riwayat Kumulatif Triwulan Berjalan</div>
                        @foreach ($riwayatBagianKustom[$bagian->id] as $triwulanKe => $entriTriwulan)
                            <div style="margin-bottom:10px">
                                <div style="font-size:12px;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">
                                    Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulanKe - 1] }}
                                </div>
                                @foreach ($entriTriwulan as $entri)
                                    <div style="padding:8px 0;border-bottom:1px solid var(--line2);font-size:13px">
                                        <div>{{ $entri->teks }}</div>
                                        @if ($entri->berkas->isNotEmpty())
                                            <div class="filechip-grid">
                                                @foreach ($entri->berkas as $file)
                                                    {{-- Riwayat di sini hanya berisi poin yang SUDAH TERKUNCI (diajukan/diverifikasi/
                                                         disetujui) atau milik triwulan sebelumnya — lihat pengecualian di
                                                         riwayatBagianKustom(). Poin periode berjalan yang masih bisa diedit (termasuk
                                                         menghapus bukti ditolak) tampil di form bawah lewat muatBagianKustomBlocks(),
                                                         jadi tidak perlu tombol hapus di sini. --}}
                                                    <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}">
                                                        <span class="nm">
                                                            <x-nama-berkas :id="$file->id" :nama="$file->nama_file" :diunggah-oleh="$file->diunggah_oleh" />
                                                            @if ($file->status_verifikasi === 'ditolak' && $file->catatan)
                                                                <span class="sub" style="color:var(--red)">{{ $file->catatan }}</span>
                                                            @endif
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif

                @error("bagianKustomBlocks.{$bagian->id}")
                    <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
                @enderror

                @foreach ($bagianKustomBlocks[$bagian->id] ?? [] as $i => $blok)
                    <div class="poin-single" wire:key="bagian-{{ $bagian->id }}-{{ $i }}" x-data="{ pendingBuktiBagianNames: [] }">
                        <span class="k-num stat-in">Poin {{ $i + 1 }}</span>
                        @if (count($bagianKustomBlocks[$bagian->id]) > 1 && ! $blok['id'])
                            <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="removeBagianKustomBlock({{ $bagian->id }}, {{ $i }})" wire:loading.attr="disabled" wire:loading.class="btn-busy" wire:target="removeBagianKustomBlock({{ $bagian->id }}, {{ $i }})">🗑</button>
                        @endif

                        <div class="field">
                            <label>Uraian <span class="req">*</span></label>
                            <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live.blur="bagianKustomBlocks.{{ $bagian->id }}.{{ $i }}.teks"
                                placeholder="Uraikan poin {{ $bagian->nama }} ini..."></textarea>
                            @error("bagianKustomBlocks.{$bagian->id}.{$i}.teks")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="field" style="margin-bottom:0">
                            <label>Bukti Dukung (PDF)
                                @if ($bagian->bukti_wajib)
                                    <span class="req">wajib bila poin diisi</span>
                                @else
                                    <span style="color:var(--muted);font-weight:500">opsional</span>
                                @endif
                            </label>

                            @if ($blok['catatan_bukti_dihapus'] ?? null)
                                <div class="info red" style="margin-bottom:8px;font-size:11.5px">
                                    🗑️ <b>Bukti sebelumnya dihapus karena:</b> "{{ $blok['catatan_bukti_dihapus'] }}" — pastikan bukti pengganti sudah memperbaiki hal ini.
                                </div>
                            @endif

                            @if (! empty($blok['existing_bukti']))
                                <div class="filechip-grid">
                                    @foreach ($blok['existing_bukti'] as $file)
                                        <div class="filechip {{ $file['status_verifikasi'] === 'terverifikasi' ? 'ok' : ($file['status_verifikasi'] === 'ditolak' ? 'no' : '') }}">
                                            <span class="nm">
                                                <x-nama-berkas :id="$file['id']" :nama="$file['nama_file']" :diunggah-oleh="$file['diunggah_oleh'] ?? null" />
                                                @if ($file['status_verifikasi'] === 'ditolak' && $file['catatan'])
                                                    <span class="sub" style="color:var(--red)">{{ $file['catatan'] }}</span>
                                                @endif
                                            </span>
                                            @if ($file['status_verifikasi'] !== 'terverifikasi')
                                                <span class="x" style="cursor:pointer" title="Hapus bukti ini" @click="pendingHapus = { method: 'hapusBuktiLamaBagianKustom', args: [{{ $blok['id'] }}, {{ $file['id'] }}] }" wire:loading.class="btn-busy" wire:target="hapusBuktiLamaBagianKustom({{ $blok['id'] }}, {{ $file['id'] }})">🗑️</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if (empty($blok['bukti']))
                                <label class="upload" style="cursor:pointer;display:block">
                                    <div class="big">📤</div>
                                    Klik untuk unggah bukti dukung (PDF) — boleh lebih dari satu berkas
                                    <input type="file" wire:model="bagianKustomBlocks.{{ $bagian->id }}.{{ $i }}.bukti" multiple accept="application/pdf" style="display:none"
                                        @change="pendingBuktiBagianNames = Array.from($event.target.files).map(f => f.name)">
                                </label>
                            @else
                                <x-mode-nama-berkas :mode="$modeNamaBerkas" />
                                <div class="filechip-grid">
                                    @foreach ($blok['bukti'] as $fi => $file)
                                        {{-- TANPA kelas "ok" (hijau) — sama seperti bukti Kegiatan di
                                             atas, belum pernah diperiksa Tim SAKIP sama sekali. --}}
                                        @php
                                            $kunciBaru = $this->kunciBuktiBaru($file);
                                            $namaTampil = $this->namaBuktiBaruAtauDefault($file, $this->namaDefaultBuktiBaru(trim($bagian->nama.' '.($blok['teks'] ?? '')), count($blok['existing_bukti'] ?? []), $loop->index, count($blok['bukti'])));
                                        @endphp
                                        <div class="filechip" wire:key="bukti-baru-{{ $kunciBaru }}">
                                            <span class="nm">
                                                <x-nama-berkas :id="$kunciBaru" :nama="$namaTampil.'.pdf'" aksi="aturNamaBuktiBaru" :bisa-ubah="true" />
                                                <span class="sub">Berkas asli: {{ $file->getClientOriginalName() }}</span>
                                            </span>
                                            <span class="x" style="cursor:pointer" title="Hapus bukti" wire:click="removeBuktiBagianKustom({{ $bagian->id }}, {{ $i }}, {{ $fi }})" wire:loading.class="btn-busy" wire:target="removeBuktiBagianKustom({{ $bagian->id }}, {{ $i }}, {{ $fi }})">🗑️</span>
                                        </div>
                                    @endforeach
                                </div>
                                <label class="btn btn-ghost btn-sm" style="margin-top:8px;cursor:pointer">
                                    ＋ Tambah Bukti
                                    <input type="file" wire:model="bagianKustomBlocks.{{ $bagian->id }}.{{ $i }}.bukti" multiple accept="application/pdf" style="display:none"
                                        @change="pendingBuktiBagianNames = Array.from($event.target.files).map(f => f.name)">
                                </label>
                            @endif
                            <div wire:loading wire:target="bagianKustomBlocks.{{ $bagian->id }}.{{ $i }}.bukti" style="font-size:11.5px;color:var(--muted);margin-top:6px">
                                <template x-for="nama in pendingBuktiBagianNames" :key="nama">
                                    <div>📄 <span x-text="nama"></span> — mengunggah…</div>
                                </template>
                                <div x-show="pendingBuktiBagianNames.length === 0">Mengunggah berkas…</div>
                            </div>
                            @error("bagianKustomBlocks.{$bagian->id}.{$i}.bukti")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                            @error("bagianKustomBlocks.{$bagian->id}.{$i}.bukti.*")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                @endforeach

                <button type="button" class="btn btn-ghost btn-sm" x-on:click="jagaScroll(() => $wire.addBagianKustomBlock({{ $bagian->id }}))" wire:loading.attr="disabled" wire:target="addBagianKustomBlock({{ $bagian->id }})" @disabled($formTerkunciSedangDitangani)>
                    <span wire:loading.remove wire:target="addBagianKustomBlock({{ $bagian->id }})">＋ Tambah Poin {{ $bagian->nama }}</span>
                    <span wire:loading wire:target="addBagianKustomBlock({{ $bagian->id }})">Menambahkan…</span>
                </button>
            @endforeach

            <div class="info teal" style="margin-top:12px">✅ Analisis capaian &amp; angka akan diisi oleh Tim SAKIP saat verifikasi.</div>
            </div>
        </div>
    </div>

    <div class="btn-row">
        <button type="button" class="btn btn-ghost" wire:click="simpanDraft" wire:loading.attr="disabled" wire:target="simpanDraft" @disabled($formTerkunciDisetujui || $formTerkunciSedangDitangani)>
            <span wire:loading.remove wire:target="simpanDraft">💾 Simpan Draft</span>
            <span wire:loading wire:target="simpanDraft">Menyimpan…</span>
        </button>
        <button type="button" class="btn btn-primary" wire:click="ajukanIsian" wire:loading.attr="disabled" wire:target="ajukanIsian" @disabled($formTerkunciDisetujui || $formTerkunciSedangDitangani || ! $this->formLengkap())>
            <span wire:loading.remove wire:target="ajukanIsian">Ajukan ke Tim SAKIP →</span>
            <span wire:loading wire:target="ajukanIsian">Mengirim…</span>
        </button>
    </div>
    @if ($formTerkunciDisetujui)
        <div style="color:var(--muted);font-size:11.5px;margin-top:8px">🔒 Isian ini sudah disetujui dan terkunci — hubungi Tim SAKIP untuk membuka kembali bila perlu revisi.</div>
    @elseif ($formTerkunciSedangDitangani)
        <div style="color:var(--muted);font-size:11.5px;margin-top:8px">🔒 Isian ini sedang diperiksa Tim SAKIP — belum bisa diubah/diajukan ulang sampai verifikasi selesai.</div>
    @elseif (! $this->formLengkap())
        @php $kekurangan = $this->kekuranganPengajuan(); @endphp
        <div class="info warn" style="margin-top:10px;display:block" wire:key="kekurangan-pengajuan">
            <strong>🔒 Belum bisa diajukan ke Tim SAKIP — lengkapi/perbaiki {{ count($kekurangan) }} hal berikut:</strong>
            <ul style="margin:6px 0 0;padding-left:18px">
                @foreach ($kekurangan as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
            <div style="margin-top:6px;opacity:.85">Daftar ini diperbarui otomatis setiap kali isian berubah; tombol "Ajukan ke Tim SAKIP" aktif begitu daftar ini kosong.</div>
        </div>
    @else
        <div style="color:var(--teal);font-size:11.5px;margin-top:8px">✅ Seluruh isian wajib sudah lengkap — siap diajukan ke Tim SAKIP.</div>
    @endif

    {{--
        Diisi live lewat $this->stream() SELAMA ajukanIsian() berjalan (lihat
        PengisianKegiatan::streamProgresUnggah()) — beda dari toast global (lihat
        x-toast-stack di layout) yang baru tampil setelah SELURUH proses (termasuk
        seluruh berkas & transaksi DB) selesai.
        Dibungkus wire:loading supaya otomatis hilang lagi begitu request selesai walau
        isinya sempat belum sempat dikosongkan oleh stream() terakhir.
    --}}
    <div wire:stream="progres-unggah" wire:loading wire:target="ajukanIsian" class="progres-unggah" style="margin-top:10px"></div>

    @php $semuaBerkasKegiatan = collect($blocks)->flatMap(fn ($b) => $b['existing_bukti'])->unique('id'); @endphp
    @foreach ($semuaBerkasKegiatan as $file)
        <div class="modal-overlay" x-show="modalBerkas === {{ $file['id'] }}" x-cloak style="display:none" @click.self="modalBerkas = null" wire:key="modal-berkas-{{ $file['id'] }}">
            <div class="modal">
                <div class="modal-top">
                    <div class="mt-t">📄 {{ $file['nama_file'] }}</div>
                    <button type="button" class="x" @click="modalBerkas = null">✕</button>
                </div>
                <div class="modal-body">
                    <div class="pdf-view">
                        <div class="pmeta">{{ $file['nama_file'] }}</div>
                        {{-- wire:ignore: tanpanya, wire:click APA PUN di komponen ini membuat
                            Livewire ikut me-morph iframe ini, dan menimpa iframe.src lewat JS
                            SELALU memicu browser memuat ulang PDF dari awal walau ke URL yang
                            sama persis — lihat catatan sama di verifikasi-capaian.blade.php. --}}
                        <iframe
                            wire:ignore
                            x-bind:src="modalBerkas === {{ $file['id'] }} ? @js(route('berkas.show', $file['kode'])) : null"
                            class="pdf-frame" title="Pratinjau {{ $file['nama_file'] }}"
                        ></iframe>
                        <a href="{{ route('berkas.show', $file['kode']) }}" target="_blank" class="btn btn-ghost btn-sm" style="margin-top:10px;align-self:flex-start">🔗 Buka di tab baru / layar penuh</a>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="modal-overlay" x-show="pendingHapus" x-cloak style="display:none" @click.self="pendingHapus = null" @keydown.escape.window="pendingHapus = null">
        <div class="modal" style="max-width:420px;height:auto">
            <div class="modal-top">
                <div class="mt-t" x-text="pendingHapus?.method === 'removeBlock' ? '🗑️ Hapus Kegiatan' : '🗑️ Hapus Bukti'"></div>
                <button type="button" class="x" @click="pendingHapus = null">✕</button>
            </div>
            <div class="modal-body" style="flex-direction:column;padding:18px;gap:16px">
                <p style="margin:0;font-size:13px;color:var(--ink);line-height:1.6" x-text="pendingHapus?.method === 'removeBlock'
                    ? 'Kegiatan ini beserta seluruh bukti yang sudah diunggah akan dihapus permanen dan tidak bisa dikembalikan.'
                    : 'Bukti ini akan dihapus dan tidak bisa dikembalikan. Pastikan untuk mengunggah bukti pengganti bila memang masih diperlukan.'"></p>
                <div style="display:flex;gap:10px;justify-content:flex-end">
                    <button type="button" class="btn btn-ghost btn-sm" @click="pendingHapus = null">Batal</button>
                    <button type="button" class="btn btn-red btn-sm" @click="$wire.call(pendingHapus.method, ...pendingHapus.args); pendingHapus = null">🗑️ Ya, Hapus</button>
                </div>
            </div>
        </div>
    </div>

    @if ($iku_id && ! $formTerkunciDisetujui && ! $formTerkunciSedangDitangani)
        <x-status-simpan aksi="simpanDraft" label-aksi="💾 Simpan Draft sekarang"
            pesan-belum="Ada perubahan yang belum disimpan — klik Simpan Draft (atau Ajukan ke Tim SAKIP) supaya tidak hilang saat halaman ditutup/berpindah." />
    @endif
</div>
