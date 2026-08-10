<div>
    <div class="page-title">Isian Kegiatan</div>
    <div class="page-sub">Kegiatan, kendala &amp; solusi, evaluasi RTL, dan rencana tindak lanjut — diajukan sekaligus ke Tim SAKIP.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
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

    <div class="period-banner">
        <span class="pb-ico">📅</span>
        <div>
            <div class="pb-lbl">Periode Pengisian</div>
            <div class="pb-val">
                <select wire:model.live="bulan" style="border:none;background:transparent;font-weight:700;color:var(--navy);font-size:14px">
                    @foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $idx => $namaBulan)
                        <option value="{{ $idx + 1 }}">{{ $namaBulan }}</option>
                    @endforeach
                </select>
                <select wire:model.live="tahun" style="border:none;background:transparent;font-weight:700;color:var(--navy);font-size:14px">
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
        <div class="info">ℹ️ Isi tiap kolom berurutan dari atas — uraian kegiatan dulu, baru jenis kegiatan bisa dipilih. Nama folder Drive dibuat otomatis dari tahapan dan uraian kegiatan. Bukti capaian wajib diunggah tiap kegiatan.</div>

        @foreach ($blocks as $i => $block)
            <div class="keg" wire:key="block-{{ $i }}"
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
                    @if (count($blocks) > 1)
                        <button type="button" class="btn btn-red btn-sm" wire:click="removeBlock({{ $i }})">🗑 Hapus</button>
                    @endif
                </div>

                <div class="field">
                    <label>Uraian Kegiatan <span class="req">*</span></label>
                    <input type="text" class="inp filled" list="dl-uraian-{{ $i }}" wire:model.blur="blocks.{{ $i }}.uraian_kegiatan"
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
                    <label>Bukti Capaian (PDF) <span class="req">wajib</span></label>

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
                            @foreach ($block['bukti'] as $fi => $file)
                                <div class="filechip ok">
                                    <span class="nm">📄 {{ $file->getClientOriginalName() }}</span>
                                    <span class="x" style="cursor:pointer" wire:click="removeBuktiKegiatan({{ $i }}, {{ $fi }})">✕</span>
                                </div>
                            @endforeach
                            <label class="btn btn-ghost btn-sm" style="margin-top:8px;cursor:pointer">
                                ⟲ Ganti Berkas
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
        @endforeach

        <button type="button" class="btn btn-ghost btn-sm" wire:click="addBlock">＋ Tambah Kegiatan</button>
        </div>

        <div class="sec" style="margin-top:20px"><span class="n">3</span><span>Kendala &amp; Solusi</span></div>

        <div x-show="!ikuDipilih" class="info warn">🔒 Pilih IKU terlebih dahulu (Bagian 1) untuk mengisi kendala &amp; solusi.</div>

        <div x-show="ikuDipilih" x-cloak>
        <div class="info warn">⚠️ Jika mengisi solusi, wajib melampirkan bukti dukung solusinya. Boleh dikosongkan bila tidak ada kendala periode ini.</div>

        @if ($iku_id && $riwayatKendala->isNotEmpty())
            <div style="margin-bottom:14px">
                <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Riwayat Kumulatif Triwulan Berjalan</div>
                @foreach ($riwayatKendala as $triwulanKe => $entriTriwulan)
                    <div style="margin-bottom:10px">
                        <div style="font-size:12px;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">
                            Triwulan {{ ['I', 'II', 'III', 'IV'][$triwulanKe - 1] }}
                        </div>
                        @foreach ($entriTriwulan as $entri)
                            <div style="padding:8px 0;border-bottom:1px solid var(--line2);font-size:13px">
                                <div><b>Kendala:</b> {{ $entri->kendala }}</div>
                                @if ($entri->solusi)
                                    <div style="margin-top:4px"><b>Solusi:</b> {{ $entri->solusi }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        @foreach ($kendalaBlocks as $i => $block)
            <div class="poin-row" wire:key="kendala-{{ $i }}" x-data="{ pendingBuktiSolusiNames: [] }">
                <span class="k-num">Pasangan {{ $i + 1 }}</span>
                @if (count($kendalaBlocks) > 1)
                    <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="removeKendalaBlock({{ $i }})">🗑</button>
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
                    <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.live="kendalaBlocks.{{ $i }}.solusi"
                        placeholder="mis. Percepatan koordinasi dengan mitra terkait"></textarea>
                    @error("kendalaBlocks.{$i}.solusi")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field" style="grid-column:1 / -1;margin-bottom:0;margin-top:2px">
                    <label style="justify-content:center">
                        Bukti Dukung Solusi (PDF)
                        @if (filled($block['solusi']))
                            <span class="req">wajib</span>
                        @else
                            <span class="fhint" style="margin:0">(bila solusi diisi)</span>
                        @endif
                    </label>

                    @if (empty($block['bukti_solusi']))
                        <label class="upload upload-tinggi {{ filled($block['solusi']) ? 'need' : '' }}" style="cursor:pointer;display:flex;width:100%">
                            <div>
                                <div class="big">📤</div>
                                Klik untuk unggah bukti dukung solusi (PDF)
                            </div>
                            <input type="file" wire:model="kendalaBlocks.{{ $i }}.bukti_solusi" multiple accept="application/pdf" style="display:none"
                                @change="pendingBuktiSolusiNames = Array.from($event.target.files).map(f => f.name)">
                        </label>
                    @else
                        <div style="display:flex;flex-direction:column;align-items:center;gap:8px">
                            @foreach ($block['bukti_solusi'] as $fi => $file)
                                <div class="filechip ok" style="width:100%">
                                    <span class="nm">📄 {{ $file->getClientOriginalName() }}</span>
                                    <span class="x" style="cursor:pointer" wire:click="removeBuktiSolusi({{ $i }}, {{ $fi }})">✕</span>
                                </div>
                            @endforeach
                            <label class="btn btn-ghost btn-sm" style="cursor:pointer">
                                ⟲ Ganti Berkas
                                <input type="file" wire:model="kendalaBlocks.{{ $i }}.bukti_solusi" multiple accept="application/pdf" style="display:none"
                                    @change="pendingBuktiSolusiNames = Array.from($event.target.files).map(f => f.name)">
                            </label>
                        </div>
                    @endif

                    <div wire:loading wire:target="kendalaBlocks.{{ $i }}.bukti_solusi" style="font-size:11.5px;color:var(--muted);margin-top:6px">
                        <template x-for="nama in pendingBuktiSolusiNames" :key="nama">
                            <div>📄 <span x-text="nama"></span> — mengunggah…</div>
                        </template>
                        <div x-show="pendingBuktiSolusiNames.length === 0">Mengunggah berkas…</div>
                    </div>

                    @error("kendalaBlocks.{$i}.bukti_solusi")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                    @error("kendalaBlocks.{$i}.bukti_solusi.*")
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        @endforeach

        <button type="button" class="btn btn-ghost btn-sm" wire:click="addKendalaBlock">＋ Tambah Pasangan Kendala &amp; Solusi</button>
        </div>

        @if ($iku_id)
            <div class="sec" style="margin-top:20px"><span class="n">4</span><span>Evaluasi RTL Triwulan Sebelumnya</span></div>
            <div wire:loading wire:target="iku_id" class="info">⏳ Memuat data RTL &amp; evaluasi untuk IKU ini…</div>
            <div class="info teal">✅ Poin di bawah otomatis diambil dari RTL triwulan sebelumnya. Lampirkan bukti realisasinya (boleh lebih dari satu berkas) — {{ $bulanKe === 3 ? 'WAJIB semua poin sudah punya bukti sebelum bisa diajukan pada bulan terakhir triwulan ini.' : 'opsional untuk bulan ini, tapi wajib sudah lengkap semua sebelum bulan terakhir triwulan.' }}</div>

            @if ($rtlSebelumnya->isEmpty())
                <p style="color:var(--muted);font-size:13px">Tidak ada poin RTL triwulan sebelumnya untuk IKU ini.</p>
            @endif

            @foreach ($rtlSebelumnya as $poin)
                <div class="poin-single" wire:key="rtl-{{ $poin->id }}" x-data="{ pendingBuktiRealisasiNames: [] }">
                    <span class="k-num stat-in">Poin {{ $loop->iteration }}</span>
                    <div class="rtl-planned">
                        <div>
                            <span class="pl-lbl">Direncanakan ({{ $poin->berlaku_bulan }})</span>
                            {{ $poin->rtl_teks }}
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

                        @foreach ($poin->berkas as $file)
                            <div class="filechip ok" style="max-width:320px;margin-top:6px">
                                <span class="nm">📄 {{ $file->nama_file }}</span>
                            </div>
                        @endforeach

                        @foreach ($evaluasi[$poin->id]['bukti'] ?? [] as $fi => $file)
                            <div class="filechip" style="max-width:320px;margin-top:6px">
                                <span class="nm">📄 {{ $file->getClientOriginalName() }}</span>
                                <span class="x" style="cursor:pointer" wire:click="removeBuktiEvaluasi({{ $poin->id }}, {{ $fi }})">✕</span>
                            </div>
                        @endforeach

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

            @if ($rtlBerjalan->isNotEmpty())
                <div style="margin:14px 0">
                    <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">RTL Triwulan Berjalan — hanya-baca</div>

                    @if ($rtlBerjalanBelumTerlaksana->isNotEmpty())
                        <div class="info red" style="margin-bottom:8px">
                            ⚠️ {{ $rtlBerjalanBelumTerlaksana->count() }} poin RTL di bawah belum pernah dipilih sebagai uraian kegiatan pada triwulan ini.
                            Semua wajib terlaksana sebelum bisa diajukan ke Tim SAKIP di bulan terakhir triwulan ({{ $this->labelBulanTerakhirTriwulanIni() }}).
                        </div>
                    @endif

                    @foreach ($rtlBerjalan as $poin)
                        <div style="padding:8px 0;border-bottom:1px solid var(--line2);font-size:13px">
                            <div>
                                {{ $poin->rtl_teks }}
                                @if ($rtlBerjalanBelumTerlaksana->contains('id', $poin->id))
                                    <span class="badge b-tunggu" style="margin-left:6px">Belum terlaksana</span>
                                @else
                                    <span class="badge b-approve" style="margin-left:6px">Terlaksana</span>
                                @endif
                            </div>
                            <div style="color:var(--muted);font-size:12px;margin-top:4px">
                                {{ $poin->berlaku_bulan }} · PIC: {{ $poin->pic }} · Batas waktu: {{ $poin->batas_waktu?->translatedFormat('d F Y') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="sec" style="margin-top:20px"><span class="n">5</span><span>Rencana Tindak Lanjut ({{ $labelBerikutnya }})</span></div>
            <div style="font-size:11.5px;color:var(--muted);margin:-8px 0 12px">
                {{ $labelBerikutnya }} berarti ({{ $bulanTargetBerikutnya->values()->join(', ', ', dan ') }}).
            </div>

            @if ($sudahAdaRtlBerikutnya)
                <div class="badge b-approve" style="display:inline-block;margin-bottom:10px">Sudah ditetapkan</div>
                <p style="color:var(--muted);font-size:12.5px">
                    RTL untuk {{ $labelBerikutnya }} sudah ditetapkan dan tampil hanya-baca sampai triwulan tersebut berjalan.
                </p>
            @elseif (! $this->rtlBaruBisaDiisi())
                <div class="info warn">
                    ⏳ RTL untuk {{ $labelBerikutnya }} baru bisa diisi pada bulan terakhir triwulan berjalan
                    (<b>{{ $this->labelBulanTerakhirTriwulanIni() }}</b>).
                </div>
            @else
                <div class="info warn">⚠️ Tulis RTL per poin untuk {{ $labelBerikutnya }} secara keseluruhan. Poin ini dicek realisasinya pada triwulan berikutnya.</div>

                @error('rtlBaru')
                    <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
                @enderror

                @foreach ($rtlBaru as $i => $blok)
                    <div class="poin-single" wire:key="rtlbaru-{{ $i }}">
                        <span class="k-num stat-in">Poin RTL {{ $i + 1 }}</span>
                        @if (count($rtlBaru) > 1)
                            <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="removeRtlBlock({{ $i }})">🗑</button>
                        @endif

                        <div class="field" style="margin-bottom:0">
                            <label>Rencana kegiatan <span class="req">*</span></label>
                            <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model.blur="rtlBaru.{{ $i }}.rtl_teks"
                                placeholder="mis. Pelatihan innas dan persiapan Susenas September 2026"></textarea>
                            @error("rtlBaru.{$i}.rtl_teks")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                @endforeach

                <button type="button" class="btn btn-ghost btn-sm" wire:click="addRtlBlock">＋ Tambah Poin RTL</button>

                <div class="row2" style="margin-top:14px">
                    <div class="field"><label>PIC Tindak Lanjut <span class="req">*</span></label>
                        @if ($namaTimIku)
                            <div class="fhint" style="margin-bottom:6px">Tim: <b>{{ $namaTimIku }}</b></div>
                        @endif
                        <select class="inp filled" wire:model.live="rtlBaruPic">
                            <option value="">— Pilih PIC —</option>
                            @foreach ($picOptions as $nama)
                                <option value="{{ $nama }}">{{ $nama }}</option>
                            @endforeach
                            <option value="__lainnya__">Lainnya (ketik manual)</option>
                        </select>
                        @if ($rtlBaruPic === '__lainnya__')
                            <input type="text" class="inp filled" style="margin-top:8px" wire:model.blur="rtlBaruPicManual" placeholder="Ketik nama PIC">
                            @error('rtlBaruPicManual')
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        @endif
                        <div class="fhint">Berlaku untuk seluruh poin RTL {{ $labelBerikutnya }} di atas.</div>
                        @error('rtlBaruPic')
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="field"><label>Batas Waktu <span class="req">*</span></label>
                        <input type="date" class="inp filled" wire:model.blur="rtlBaruBatasWaktu">
                        <div class="fhint">Bawaan: akhir {{ $labelBerikutnya }}.</div>
                        @error('rtlBaruBatasWaktu')
                            <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            @endif

            @foreach ($bagianKustomAktif as $bagian)
                <div class="sec" style="margin-top:20px"><span class="n">🧩</span><span>{{ $bagian->nama }}</span></div>
                @if ($bagian->deskripsi)
                    <div class="info">ℹ️ {{ $bagian->deskripsi }}</div>
                @endif
                @if ($bagian->wajib_akhir_triwulan)
                    <div class="info warn">⚠️ Minimal satu poin wajib diisi sebelum diajukan pada bulan terakhir triwulan ini ({{ $bulanKe === 3 ? 'berlaku sekarang' : 'berlaku mulai bulan ke-3 triwulan' }}). Tiap poin wajib dilampiri bukti dukung (PDF).</div>
                @else
                    <div class="info">ℹ️ Bagian ini opsional. Tiap poin yang diisi wajib dilampiri bukti dukung (PDF).</div>
                @endif

                @error("bagianKustomBlocks.{$bagian->id}")
                    <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
                @enderror

                @foreach ($bagianKustomBlocks[$bagian->id] ?? [] as $i => $blok)
                    <div class="poin-single" wire:key="bagian-{{ $bagian->id }}-{{ $i }}">
                        <span class="k-num stat-in">Poin {{ $i + 1 }}</span>
                        @if (count($bagianKustomBlocks[$bagian->id]) > 1)
                            <button type="button" class="btn btn-red btn-sm" style="position:absolute;top:8px;right:8px" wire:click="removeBagianKustomBlock({{ $bagian->id }}, {{ $i }})">🗑</button>
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
                            <label>Bukti Dukung (PDF) <span class="req">wajib bila poin diisi</span></label>
                            @if (empty($blok['bukti']))
                                <label class="upload" style="cursor:pointer;display:block">
                                    <div class="big">📤</div>
                                    Klik untuk unggah bukti dukung (PDF) — boleh lebih dari satu berkas
                                    <input type="file" wire:model="bagianKustomBlocks.{{ $bagian->id }}.{{ $i }}.bukti" multiple accept="application/pdf" style="display:none">
                                </label>
                            @else
                                @foreach ($blok['bukti'] as $fi => $file)
                                    <div class="filechip ok">
                                        <span class="nm">📄 {{ $file->getClientOriginalName() }}</span>
                                        <span class="x" style="cursor:pointer" wire:click="removeBuktiBagianKustom({{ $bagian->id }}, {{ $i }}, {{ $fi }})">✕</span>
                                    </div>
                                @endforeach
                                <label class="btn btn-ghost btn-sm" style="margin-top:8px;cursor:pointer">
                                    ⟲ Ganti Berkas
                                    <input type="file" wire:model="bagianKustomBlocks.{{ $bagian->id }}.{{ $i }}.bukti" multiple accept="application/pdf" style="display:none">
                                </label>
                            @endif
                            <div wire:loading wire:target="bagianKustomBlocks.{{ $bagian->id }}.{{ $i }}.bukti" style="font-size:11.5px;color:var(--muted);margin-top:6px">Mengunggah berkas…</div>
                            @error("bagianKustomBlocks.{$bagian->id}.{$i}.bukti")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                            @error("bagianKustomBlocks.{$bagian->id}.{$i}.bukti.*")
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                @endforeach

                <button type="button" class="btn btn-ghost btn-sm" wire:click="addBagianKustomBlock({{ $bagian->id }})">＋ Tambah Poin {{ $bagian->nama }}</button>
            @endforeach

            <div class="info teal" style="margin-top:12px">✅ Analisis capaian &amp; angka akan diisi oleh Tim SAKIP saat verifikasi.</div>
        @endif
    </div>

    <div class="btn-row">
        <button type="button" class="btn btn-ghost" wire:click="simpanDraft" wire:loading.attr="disabled" wire:target="simpanDraft">
            <span wire:loading.remove wire:target="simpanDraft">💾 Simpan Draft</span>
            <span wire:loading wire:target="simpanDraft">Menyimpan…</span>
        </button>
        <button type="button" class="btn btn-primary" wire:click="ajukanIsian" wire:loading.attr="disabled" wire:target="ajukanIsian" @disabled(! $this->formLengkap())>
            <span wire:loading.remove wire:target="ajukanIsian">Ajukan ke Tim SAKIP →</span>
            <span wire:loading wire:target="ajukanIsian">Mengirim…</span>
        </button>
    </div>
    @if (! $this->formLengkap())
        <div style="color:var(--muted);font-size:11.5px;margin-top:8px">🔒 Lengkapi seluruh isian wajib di atas untuk mengaktifkan tombol "Ajukan ke Tim SAKIP".</div>
    @endif
</div>
