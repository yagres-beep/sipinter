@php
    $formatAngka = fn ($nilai) => \App\Models\PengaturanCapaian::formatAngka($nilai);
    $formatPersen = fn ($nilai) => \App\Models\PengaturanCapaian::formatPersen($nilai);

    $badgeCapaian = function (?float $persen) use ($formatPersen) {
        if ($formatPersen($persen) === '-') {
            return 'b-draft';
        }

        return $persen >= 90 ? 'b-approve' : ($persen >= 70 ? 'b-tunggu' : 'b-tolak');
    };

    $rataRata = $lakin->baris->pluck('capaian_persen')->filter(fn ($p) => $p !== null);
    $jumlahSasaran = $lakin->baris->pluck('sasaran')->filter()->unique()->count();
    $barisPerSasaran = $lakin->baris->groupBy(fn ($b) => $b->sasaran ?: 'Tanpa Sasaran');
@endphp

<div>
    <div class="page-head">
        <div class="page-title">Rekap Kinerja Tahunan {{ $lakin->tahun }}</div>
        <div class="page-sub">Rekap kinerja tahunan — sasaran, indikator, target, realisasi, dan capaian %.</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <div class="period-banner">
        <span class="pb-ico">📈</span>
        <div>
            <div class="pb-lbl">Tahun Laporan</div>
            <div class="pb-val"><b>{{ $lakin->tahun }}</b></div>
        </div>
        <div class="btn-row" style="margin:0 0 0 auto">
            <a wire:navigate href="{{ route('lakin.index') }}" class="btn btn-ghost btn-sm">← Daftar Rekap</a>
            <button type="button" class="btn btn-teal btn-sm" wire:click="unduhExcel" wire:loading.attr="disabled" wire:target="unduhExcel">
                <span wire:loading.remove wire:target="unduhExcel">⬇ Unduh Excel</span>
                <span wire:loading wire:target="unduhExcel"><i class="spin"></i> Menyiapkan…</span>
            </button>
            @if ($this->isTimSakip())
                <button type="button" class="btn btn-ghost btn-sm" wire:click="bukaKonfirmasiSegarkan" wire:loading.attr="disabled" wire:target="segarkanAngka">
                    <span wire:loading.remove wire:target="segarkanAngka">↻ Segarkan Angka</span>
                    <span wire:loading wire:target="segarkanAngka"><i class="spin"></i> Menyegarkan…</span>
                </button>
            @endif
        </div>
    </div>

    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat-tile">
            <div class="si">🧾</div>
            <div class="sv">{{ $lakin->baris->count() }}</div>
            <div class="sl">Jumlah Indikator</div>
        </div>
        <div class="stat-tile">
            <div class="si">🎯</div>
            <div class="sv">{{ $rataRata->isNotEmpty() ? round($rataRata->avg(), 1) : '-' }}%</div>
            <div class="sl">Rata-rata Capaian</div>
        </div>
        <div class="stat-tile">
            <div class="si">🗂️</div>
            <div class="sv">{{ $jumlahSasaran }}</div>
            <div class="sl">Sasaran Strategis</div>
        </div>
    </div>

    <div class="card">
        <div class="card-h">📋 Tabel Rekap Kinerja</div>

        @if ($lakin->baris->isEmpty())
            <p style="color:var(--muted);font-size:13px">Belum ada baris. {{ $this->isTimSakip() ? 'Pilih dari data capaian atau tambahkan baris custom lewat form di bawah.' : '' }}</p>
        @else
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th style="min-width:220px">Indikator</th>
                            <th style="text-align:right;width:110px">Target</th>
                            <th style="text-align:right;width:110px">Realisasi</th>
                            <th style="text-align:right;width:90px">Capaian %</th>
                            <th style="min-width:180px">Kegiatan</th>
                            <th style="min-width:180px">Solusi &amp; Kendala</th>
                            <th style="min-width:180px">RTL</th>
                            <th style="min-width:120px">PIC</th>
                            <th style="min-width:140px">Batas Waktu Tindak Lanjut</th>
                            <th style="min-width:160px">Link Bukti Dukung Kinerja</th>
                            <th style="min-width:160px">Link RTL Sebelumnya</th>
                            @if ($this->isTimSakip())
                                <th style="width:70px"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($barisPerSasaran as $sasaran => $daftarBaris)
                            <tr>
                                <td colspan="{{ $this->isTimSakip() ? 12 : 11 }}" style="background:var(--bg);font-size:11px;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.5px;padding:8px 12px">
                                    {{ $sasaran }}
                                </td>
                            </tr>
                            @foreach ($daftarBaris as $baris)
                                <tr wire:key="baris-{{ $baris->id }}">
                                    @if ($this->isTimSakip())
                                        <td>
                                            <textarea class="inp filled" style="height:auto;font-size:12.5px" rows="1" wire:model="edit.{{ $baris->id }}.indikator"></textarea>
                                            <input type="text" class="inp filled" style="margin-top:4px;font-size:11px;color:var(--muted)" placeholder="Sasaran (opsional)" wire:model="edit.{{ $baris->id }}.sasaran">
                                            @error("edit.{$baris->id}.indikator")
                                                <div style="color:var(--red);font-size:11px;margin-top:4px">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td style="text-align:right"><input type="number" step="0.01" class="inp filled" style="text-align:right" wire:model="edit.{{ $baris->id }}.target"></td>
                                        <td style="text-align:right"><input type="number" step="0.01" class="inp filled" style="text-align:right" wire:model="edit.{{ $baris->id }}.realisasi"></td>
                                        <td style="text-align:right">
                                            <span class="badge {{ $badgeCapaian($baris->capaian_persen) }}">{{ $formatPersen($baris->capaian_persen) }}</span>
                                        </td>
                                        <td><textarea class="inp filled" style="height:auto;font-size:12.5px" rows="1" wire:model="edit.{{ $baris->id }}.kegiatan"></textarea></td>
                                        <td><textarea class="inp filled" style="height:auto;font-size:12.5px" rows="1" wire:model="edit.{{ $baris->id }}.solusi_kendala"></textarea></td>
                                        <td><textarea class="inp filled" style="height:auto;font-size:12.5px" rows="1" wire:model="edit.{{ $baris->id }}.rtl"></textarea></td>
                                        <td><input type="text" class="inp filled" style="font-size:12.5px" wire:model="edit.{{ $baris->id }}.pic"></td>
                                        <td><input type="date" class="inp filled" style="font-size:12.5px" wire:model="edit.{{ $baris->id }}.batas_waktu_tindak_lanjut"></td>
                                        <td><input type="text" class="inp filled" style="font-size:12.5px" placeholder="https://…" wire:model="edit.{{ $baris->id }}.link_bukti_dukung_kinerja"></td>
                                        <td><input type="text" class="inp filled" style="font-size:12.5px" placeholder="https://…" wire:model="edit.{{ $baris->id }}.link_rtl_sebelumnya"></td>
                                        <td style="text-align:right;white-space:nowrap">
                                            <button type="button" class="btn btn-ghost btn-sm" title="Simpan" wire:click="simpanBaris({{ $baris->id }})" wire:loading.attr="disabled" wire:target="simpanBaris({{ $baris->id }}),hapusBaris({{ $baris->id }})">
                                                <span wire:loading.remove wire:target="simpanBaris({{ $baris->id }})">💾</span>
                                                <span wire:loading wire:target="simpanBaris({{ $baris->id }})"><i class="spin"></i></span>
                                            </button>
                                            <button type="button" class="btn btn-red btn-sm" title="Hapus" wire:click="confirmHapusBaris({{ $baris->id }})" wire:loading.attr="disabled" wire:target="simpanBaris({{ $baris->id }}),confirmHapusBaris({{ $baris->id }})">
                                                <span wire:loading.remove wire:target="confirmHapusBaris({{ $baris->id }})">🗑</span>
                                                <span wire:loading wire:target="confirmHapusBaris({{ $baris->id }})"><i class="spin"></i></span>
                                            </button>
                                        </td>
                                    @else
                                        <td>{{ $baris->indikator }}</td>
                                        <td style="text-align:right">{{ $formatAngka($baris->target) }}</td>
                                        <td style="text-align:right">{{ $formatAngka($baris->realisasi) }}</td>
                                        <td style="text-align:right">
                                            <span class="badge {{ $badgeCapaian($baris->capaian_persen) }}">{{ $formatPersen($baris->capaian_persen) }}</span>
                                        </td>
                                        <td>{{ $baris->kegiatan }}</td>
                                        <td>{{ $baris->solusi_kendala }}</td>
                                        <td>{{ $baris->rtl }}</td>
                                        <td>{{ $baris->pic }}</td>
                                        <td>{{ $baris->batas_waktu_tindak_lanjut?->format('d-m-Y') }}</td>
                                        <td>@if ($baris->link_bukti_dukung_kinerja)<a href="{{ $baris->link_bukti_dukung_kinerja }}" target="_blank" rel="noopener">Buka tautan</a>@endif</td>
                                        <td>@if ($baris->link_rtl_sebelumnya)<a href="{{ $baris->link_rtl_sebelumnya }}" target="_blank" rel="noopener">Buka tautan</a>@endif</td>
                                    @endif
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($this->isTimSakip())
        <div class="card" style="margin-top:16px">
            <div class="card-h">📥 Tambah dari Data Capaian</div>
            <div class="info">ℹ️ IKU yang sudah diverifikasi Tim SAKIP otomatis tercentang. IKU yang belum diverifikasi tetap bisa dicentang manual bila mau. Hanya yang dicentang yang ditambahkan saat Anda menekan "Tambahkan Terpilih".</div>

            @error('ikuTerpilihUntukTambah')
                <div style="color:var(--red);font-size:11.5px;margin-bottom:10px">{{ $message }}</div>
            @enderror

            @if ($ikuBelumDitambahkan->isEmpty())
                <p style="color:var(--muted);font-size:13px">Semua IKU yang punya data capaian tahun {{ $lakin->tahun }} sudah ditambahkan.</p>
            @else
                <div class="btn-row" style="margin-bottom:10px">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="pilihSemuaUntukTambah">☑ Pilih Semua</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="batalkanSemuaUntukTambah">☐ Batalkan Semua</button>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px;margin-bottom:14px">
                    @foreach ($ikuBelumDitambahkan as $data)
                        <label class="fl-row" style="cursor:pointer;align-items:flex-start;gap:10px">
                            <input type="checkbox" value="{{ $data->iku->id }}" wire:model="ikuTerpilihUntukTambah" style="margin-top:3px">
                            <span>
                                <b style="font-size:12.5px">{{ $data->iku->kode }}</b>
                                @if ($data->terverifikasi)
                                    <x-badge-status status="diverifikasi" />
                                @else
                                    <x-badge-status status="menunggu" label="Belum Diverifikasi" />
                                @endif
                                <span style="display:block;font-size:12px;color:var(--muted)">{{ $data->iku->indikator }}</span>
                                <span style="display:block;font-size:11px;color:var(--faint);margin-top:2px">Target {{ $formatAngka($data->target) }} · Realisasi {{ $formatAngka($data->realisasi) }} · Capaian {{ $formatPersen($data->capaian_persen) }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <div class="btn-row">
                    <button type="button" class="btn btn-primary" wire:click="tambahDariCapaian" wire:loading.attr="disabled" wire:target="tambahDariCapaian">
                        <span wire:loading.remove wire:target="tambahDariCapaian">📥 Tambahkan Terpilih</span>
                        <span wire:loading wire:target="tambahDariCapaian"><i class="spin"></i> Menambahkan…</span>
                    </button>
                </div>
            @endif
        </div>

        <div class="card" style="margin-top:16px">
            <div class="card-h">✏️ Tambah Baris Custom</div>
            <div class="info">ℹ️ Untuk indikator yang tidak mengacu ke Master IKU manapun, atau format rekap yang berbeda dari bawaan sistem.</div>
            <div class="grid grid-2">
                <div class="field">
                    <label>Sasaran (opsional)</label>
                    <input type="text" class="inp filled" wire:model="sasaranBaru">
                </div>
                <div class="field">
                    <label>Indikator <span class="req">*</span></label>
                    <input type="text" class="inp filled" wire:model="indikatorBaru">
                    @error('indikatorBaru')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
                <div class="field">
                    <label>Target (opsional)</label>
                    <input type="number" step="0.01" class="inp filled" wire:model="targetBaru">
                </div>
                <div class="field">
                    <label>Realisasi (opsional)</label>
                    <input type="number" step="0.01" class="inp filled" wire:model="realisasiBaru">
                </div>
                <div class="field">
                    <label>Kegiatan (opsional)</label>
                    <textarea class="inp filled" rows="2" wire:model="kegiatanBaru"></textarea>
                </div>
                <div class="field">
                    <label>Solusi &amp; Kendala (opsional)</label>
                    <textarea class="inp filled" rows="2" wire:model="solusiKendalaBaru"></textarea>
                </div>
                <div class="field">
                    <label>RTL / Rencana Tindak Lanjut (opsional)</label>
                    <textarea class="inp filled" rows="2" wire:model="rtlBaru"></textarea>
                </div>
                <div class="field">
                    <label>PIC (opsional)</label>
                    <input type="text" class="inp filled" wire:model="picBaru">
                </div>
                <div class="field">
                    <label>Batas Waktu Tindak Lanjut (opsional)</label>
                    <input type="date" class="inp filled" wire:model="batasWaktuTindakLanjutBaru">
                    @error('batasWaktuTindakLanjutBaru')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
                <div class="field">
                    <label>Link Bukti Dukung Kinerja (opsional)</label>
                    <input type="text" class="inp filled" placeholder="https://…" wire:model="linkBuktiDukungKinerjaBaru">
                </div>
                <div class="field">
                    <label>Link RTL Sebelumnya (opsional)</label>
                    <input type="text" class="inp filled" placeholder="https://…" wire:model="linkRtlSebelumnyaBaru">
                </div>
            </div>
            <div class="btn-row">
                <button type="button" class="btn btn-ghost" wire:click="tambahBaris" wire:loading.attr="disabled" wire:target="tambahBaris">
                    <span wire:loading.remove wire:target="tambahBaris">＋ Tambah Baris</span>
                    <span wire:loading wire:target="tambahBaris"><i class="spin"></i> Menambahkan…</span>
                </button>
            </div>
        </div>
    @endif

    <x-confirm-modal :show="$showSegarkanConfirm" title="Segarkan Angka?" :danger="false"
        message="Segarkan angka baris yang sudah ada dari data capaian terbaru? Baris custom tidak berubah, dan ini tidak menambah baris baru.">
        <button type="button" class="btn btn-ghost" wire:click="batalkanSegarkan" wire:loading.attr="disabled" wire:target="segarkanAngka">Batal</button>
        <button type="button" class="btn btn-primary" wire:click="segarkanAngka" wire:loading.attr="disabled" wire:target="segarkanAngka">
            <span wire:loading.remove wire:target="segarkanAngka">Segarkan</span>
            <span wire:loading wire:target="segarkanAngka"><i class="spin"></i> Menyegarkan…</span>
        </button>
    </x-confirm-modal>

    <x-confirm-modal :show="$pendingHapusBarisId !== null" title="Hapus Baris?" message="Hapus baris ini?">
        <button type="button" class="btn btn-ghost" wire:click="batalkanHapusBaris" wire:loading.attr="disabled" wire:target="hapusBaris">Batal</button>
        <button type="button" class="btn btn-red" wire:click="hapusBaris" wire:loading.attr="disabled" wire:target="hapusBaris">
            <span wire:loading.remove wire:target="hapusBaris">Hapus</span>
            <span wire:loading wire:target="hapusBaris"><i class="spin"></i> Menghapus…</span>
        </button>
    </x-confirm-modal>
</div>
