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

    @if ($formTerkunciSedangDitangani)
        <div class="info" style="margin-bottom:14px">
            🔒 <b>Isian ini sedang ditangani Tim SAKIP</b> — tidak bisa ditambah/diubah dari sini sampai Tim SAKIP menyelesaikan pemeriksaan (Verifikasi Selesai atau Kembalikan ke Ketua Tim).
        </div>
    @endif

    @if ($adaDikembalikan)
        @php $olehKepala = $pengembalianTerakhir?->user?->namaRole() === 'Kepala'; @endphp
        <div class="info red" style="margin-bottom:14px">
            🔴 <b>Isian ini dikembalikan oleh {{ $olehKepala ? 'Kepala' : 'Tim SAKIP' }} untuk periode ini</b> — perbaiki bagian yang ditandai merah di bawah, lalu ajukan ulang.
            @if ($pengembalianTerakhir?->catatan)
                <p style="margin:6px 0 0">Catatan {{ $olehKepala ? 'Kepala' : 'Tim SAKIP' }}: {{ $pengembalianTerakhir->catatan }}</p>
            @endif
            @if ($catatanPenolakan->isNotEmpty())
                <ul style="margin:6px 0 0 18px;padding:0">
                    @foreach ($catatanPenolakan as $catatan)
                        <li>{{ $catatan }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="period-banner">
        <span class="pb-ico">📅</span>
        <div>
            <div class="pb-lbl">Periode Pengisian</div>
            <div class="pb-val">
                <select wire:model.live="bulan" @change="bisaDiisiTw = (Number($event.target.value) % 3 === 0)" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                    @foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $idx => $namaBulan)
                        <option value="{{ $idx + 1 }}">{{ $namaBulan }}</option>
                    @endforeach
                </select>
                <select wire:model.live="tahun" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                    @foreach (range(now()->year - 1, now()->year + 1) as $tahunOpsi)
                        <option value="{{ $tahunOpsi }}">{{ $tahunOpsi }}</option>
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
            <select class="inp filled" wire:model.live="iku_id" @change="ikuDipilih = ($event.target.value !== '')">
                <option value="">— Pilih IKU —</option>
                @foreach ($ikuList as $iku)
                    <option value="{{ $iku->id }}">{{ $iku->kode }} — {{ $iku->indikator }}</option>
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
        <div class="info">ℹ️ Isi tiap kolom berurutan dari atas — uraian kegiatan dulu, baru jenis kegiatan bisa dipilih. Nama folder Drive dibuat otomatis dari tahapan dan uraian kegiatan. Bukti capaian wajib diunggah tiap kegiatan.</div>

        @foreach ($blocks as $i => $block)
            @php $terkunci = in_array($block['status_dokumen'], $statusKegiatanTerkunci, true); @endphp
            @if ($terkunci)
                <div class="keg" wire:key="block-{{ $i }}" style="opacity:.8">
                    <div class="keg-head">
                        <span class="t">Kegiatan {{ $i + 1 }}</span>
                        <x-badge-status :status="$block['status_dokumen']" />
                    </div>
                    <div style="font-size:11.5px;color:var(--muted);margin-bottom:10px">🔒 Sudah diajukan ke Tim SAKIP — tidak bisa diedit dari sini.</div>

                    <div class="field">
                        <label>Uraian Kegiatan</label>
                        <div class="inp filled" style="background:var(--ro-bg)">{{ $block['uraian_kegiatan'] }}</div>
                    </div>
                    <div class="field">
                        <label>Jenis Kegiatan</label>
                        <div class="inp filled" style="background:var(--ro-bg)">{{ $block['jenis'] === 'survei_sensus' ? 'Survei/Sensus' : 'Bukan Survei/Sensus' }}@if ($block['tahapan_survei']) — {{ ucfirst($block['tahapan_survei']) }}@endif</div>
                    </div>

                    <div class="field" style="margin-bottom:0">
                        <label>Bukti Capaian (PDF)</label>
                        <div class="filechip-grid">
                            @forelse ($block['existing_bukti'] as $file)
                                <div class="filechip {{ $file['status_verifikasi'] === 'terverifikasi' ? 'ok' : ($file['status_verifikasi'] === 'ditolak' ? 'no' : '') }}">
                                    <span class="nm">
                                        📄 {{ $file['nama_file'] }}
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
                    <input type="text" class="inp filled" list="dl-uraian-{{ $i }}" wire:model.live.blur="blocks.{{ $i }}.uraian_kegiatan"
                        @input="uraianTerisi = ($event.target.value.trim() !== ''); uraianTeks = $event.target.value"
                        placeholder="mis. Pencacahan rumah tangga Sakernas {{ $periodeLabel }}">
                    @if ($rtlBerjalanOptions->isNotEmpty())
                        <datalist id="dl-uraian-{{ $i }}">
                            @foreach ($rtlBerjalanOptions as $opsi)
                                <option value="{{ $opsi['poin']->rtl_teks }}">{{ $opsi['poin']->rtl_teks }}@if ($opsi['terpakai']) — sudah pernah dipilih @endif</option>
                            @endforeach
                        </datalist>
                        <div class="fhint">💡 Ketik bebas atau pilih dari rencana RTL triwulan ini (muncul sebagai saran ketikan).</div>
                    @endif
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
                    <label>Tahapan Survei <span class="req">*</span></label>
                    <select class="inp filled" wire:model.live="blocks.{{ $i }}.tahapan_survei"
                        @change="tahapanTerisi = ($event.target.value !== ''); tahapanNilai = $event.target.value">
                        <option value="">— Pilih Tahapan —</option>
                        <option value="persiapan">Persiapan</option>
                        <option value="pelaksanaan">Pelaksanaan</option>
                        <option value="pengolahan">Pengolahan</option>
                        <option value="diseminasi">Diseminasi</option>
                    </select>
                    @error("blocks.{$i}.tahapan_survei")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div x-show="folderPreview !== ''" x-cloak class="foldername">📁 <span x-text="folderPreview"></span></div>

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
                                        📄 {{ $file['nama_file'] }}
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
                            <div class="filechip-grid">
                                @foreach ($block['bukti'] as $fi => $file)
                                    {{-- TANPA kelas "ok" (hijau) — berkas ini baru dipilih, belum
                                         pernah diperiksa Tim SAKIP sama sekali, jadi belum punya
                                         status apa-apa (bukan "Sesuai"). Hijau baru muncul begitu
                                         benar-benar ditandai "Sesuai" (lihat existing_bukti di atas). --}}
                                    <div class="filechip">
                                        <span class="nm">📄 {{ $file->getClientOriginalName() }}</span>
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
                    <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="kendalaBlocks.{{ $i }}.kendala"
                        placeholder="mis. Keterlambatan pengumpulan dokumen dari mitra"></textarea>
                    @error("kendalaBlocks.{$i}.kendala")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field" style="margin-bottom:0">
                    <label>Solusi</label>
                    <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="kendalaBlocks.{{ $i }}.solusi"
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
            <div class="info teal">✅ Poin di bawah adalah RTL yang ditetapkan pada triwulan sebelumnya untuk dilaksanakan triwulan ini — sama dengan yang muncul sebagai saran uraian kegiatan di Bagian 2. Lampirkan bukti realisasinya (boleh lebih dari satu berkas) — {{ $bulanKe === 3 ? 'WAJIB semua poin sudah punya bukti sebelum bisa diajukan pada bulan terakhir triwulan ini.' : 'opsional untuk bulan ini, tapi wajib sudah lengkap semua sebelum bulan terakhir triwulan.' }}</div>

            @if ($rtlSebelumnya->isEmpty())
                <p style="color:var(--muted);font-size:13px">Tidak ada poin RTL triwulan sebelumnya untuk IKU ini.</p>
            @endif

            @if ($rtlBerjalanBelumTerlaksana->isNotEmpty())
                <div class="info red" style="margin-bottom:8px">
                    ⚠️ {{ $rtlBerjalanBelumTerlaksana->count() }} poin RTL di bawah belum pernah dipilih sebagai uraian kegiatan pada triwulan ini.
                    Semua wajib terlaksana sebelum bisa diajukan ke Tim SAKIP di bulan terakhir triwulan ({{ $this->labelBulanTerakhirTriwulanIni() }}).
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
                                <span class="badge b-tunggu" style="margin-left:6px">Belum terlaksana sbg kegiatan</span>
                            @else
                                <span class="badge b-approve" style="margin-left:6px">Terlaksana sbg kegiatan</span>
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

                        <div class="filechip-grid">
                            @foreach ($poin->berkas as $file)
                                <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}">
                                    <span class="nm">
                                        📄 {{ $file->nama_file }}
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
                                <div class="filechip">
                                    <span class="nm">📄 {{ $file->getClientOriginalName() }}</span>
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
                    <div class="field"><label>PIC Tindak Lanjut</label>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px">
                            @forelse ($rtlBaruPicTerpilih as $tim)
                                <span class="chip chip-tim" wire:key="rtl-pic-{{ $loop->index }}">
                                    {{ $tim }}
                                    <span class="chip-x" wire:click="hapusRtlBaruPic('{{ $tim }}')">✕</span>
                                </span>
                            @empty
                                <span class="muted" style="font-size:11.5px">— Belum dipilih (diisi Tim SAKIP saat verifikasi) —</span>
                            @endforelse
                        </div>
                        <div style="display:flex;gap:6px">
                            <input type="text" class="inp filled" list="daftar-tim-pic" wire:model="rtlBaruPicBaru"
                                wire:keydown.enter.prevent="tambahRtlBaruPic" placeholder="Pilih dari saran atau ketik tim baru…">
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahRtlBaruPic">＋ Tambah</button>
                        </div>
                        <datalist id="daftar-tim-pic">
                            @foreach ($daftarTimPic as $tim)
                                <option value="{{ $tim }}"></option>
                            @endforeach
                        </datalist>
                        <div class="fhint">
                            Nama tim (bukan perorangan) yang bertanggung jawab menindaklanjuti — boleh lebih dari satu. Bawaan diambil dari tim penanggung jawab IKU ini, tapi boleh ditambah/dihapus bebas: pilih dari saran yang sudah ada di database tim, atau ketik nama tim baru lalu tekan Enter/"＋ Tambah". Opsional di sini; wajib diisi/dikonfirmasi Tim SAKIP saat verifikasi. Berlaku untuk seluruh poin RTL {{ $labelBerikutnya }} di atas.
                        </div>
                        @error('rtlBaruPicTerpilih')
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
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
                                                            📄 {{ $file->nama_file }}
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
                            <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="bagianKustomBlocks.{{ $bagian->id }}.{{ $i }}.teks"
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
                                                📄 {{ $file['nama_file'] }}
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
                                <div class="filechip-grid">
                                    @foreach ($blok['bukti'] as $fi => $file)
                                        {{-- TANPA kelas "ok" (hijau) — sama seperti bukti Kegiatan di
                                             atas, belum pernah diperiksa Tim SAKIP sama sekali. --}}
                                        <div class="filechip">
                                            <span class="nm">📄 {{ $file->getClientOriginalName() }}</span>
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
    @if (! $this->formLengkap())
        <div style="color:var(--muted);font-size:11.5px;margin-top:8px">🔒 Lengkapi seluruh isian wajib di atas untuk mengaktifkan tombol "Ajukan ke Tim SAKIP".</div>
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
                            x-bind:src="modalBerkas === {{ $file['id'] }} ? @js(route('berkas.show', $file['id'])) : null"
                            class="pdf-frame" title="Pratinjau {{ $file['nama_file'] }}"
                        ></iframe>
                        <a href="{{ route('berkas.show', $file['id']) }}" target="_blank" class="btn btn-ghost btn-sm" style="margin-top:10px;align-self:flex-start">🔗 Buka di tab baru / layar penuh</a>
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

</div>
