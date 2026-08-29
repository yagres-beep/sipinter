@php
    $namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
@endphp

<div x-data="{ modalBerkas: null }">
    <div class="page-head">
        <div class="page-title">Detail Verifikasi — {{ $capaian->masterIku->kode }}</div>
        <div class="page-sub">
            {{ $capaian->masterIku->indikator }} · {{ $namaBulan[$capaian->periode->bulan - 1] }} {{ $capaian->periode->tahun }} ·
            {{ $kegiatanList->count() }} kegiatan pendukung
        </div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @error('berkas')
        <div class="badge b-kembali" style="display:block;margin-bottom:14px">{{ $message }}</div>
    @enderror

    <div class="info teal">✏️ Analisis Capaian, Target &amp; Realisasi Triwulanan, dan teks bergaris putus-putus (uraian kegiatan, kendala, solusi, realisasi RTL) selalu bisa disunting Tim SAKIP — klik "Simpan Perubahan" di bawah kapan pun, tidak perlu menunggu status "diajukan".</div>
    @unless ($bisaDiverifikasi)
        <div class="info">🔒 Isian ini berstatus <x-badge-status :status="$capaian->status" /> — verifikasi/pengembalian bukti hanya bisa dilakukan selagi berstatus "diajukan" atau "sedang ditangani", tapi field di atas tetap bisa disunting &amp; disimpan.</div>
    @endunless

    @if ($riwayatStatus->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Riwayat Status</span></div>
            <div style="display:flex;flex-direction:column;gap:10px">
                @foreach ($riwayatStatus as $riwayat)
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                        <x-badge-status :status="$riwayat->status" />
                        <span style="font-size:12.5px">{{ $riwayat->user?->nama ?? 'Sistem' }}</span>
                        <span style="font-size:11.5px;color:var(--muted)">{{ $riwayat->created_at->wita()->translatedFormat('d F Y, H:i') }} WITA</span>
                        @if ($riwayat->catatan)
                            <span style="font-size:11.5px;color:var(--muted);width:100%">📝 {{ $riwayat->catatan }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card">
        <div class="sec"><span>Angka Capaian</span> <span class="badge b-tunggu">diisi Tim SAKIP</span></div>

        <div class="field">
            <label>Analisis Capaian</label>
            <textarea class="inp filled" style="height:auto;display:block" rows="3" wire:model="analisis_capaian"
                placeholder="Narasi analisis capaian..."></textarea>
            @error('analisis_capaian')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label>Penjelasan/Pembahasan Lainnya</label>
            <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="catatan"
                placeholder="Opsional — tampil di Notula Bagian I pada baris Penjelasan/pembahasan lainnya"></textarea>
            @error('catatan')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        {{-- Target Tahunan tidak lagi diedit dari sini — sekali per tahun per IKU,
            diisi terpusat di tab "Target Tahunan" (Data Master & Konfigurasi) supaya
            tidak perlu diketik ulang tiap sesi verifikasi bulanan. Di sini cukup
            ditampilkan sebagai referensi. --}}
        <div class="field">
            <label>Target Tahunan <span class="muted" style="font-weight:400;font-size:10px">— sekali per tahun, berlaku untuk seluruh bulan IKU ini</span></label>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span class="inp filled" style="background:var(--ro-bg);display:inline-block;width:auto;padding:8px 14px">
                    @if ($capaian->masterIku->pakaiRasio())
                        {{ $capaian->masterIku->deskripsi_x ?: 'X' }} {{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->x_target) }} ÷ {{ $capaian->masterIku->deskripsi_y ?: 'Y' }} {{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->y_target) }} = {{ $capaianTahunan->targetTahunan() }}%
                    @else
                        {{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->target_tahunan) }} {{ $capaian->masterIku->satuan }}
                    @endif
                </span>
                <a wire:navigate href="{{ route('master-iku.index') }}#target" class="btn btn-ghost btn-sm">✏️ Ubah di Target Tahunan</a>
            </div>
        </div>
        @if ($capaian->masterIku->pakaiRasio())
            <div class="fhint" style="margin:10px 0 6px">Alokasi Pembilang (X) &amp; Penyebut (Y) TW I-IV sudah ditetapkan sekali di awal tahun lewat <a wire:navigate href="{{ route('master-iku.index') }}#target">🎯 Target Tahunan</a> — di sini cukup isi <strong>Realisasi Pembilang (X) TRIWULAN INI SAJA</strong> (bukan kumulatif). Realisasi Penyebut (Y) otomatis mengikuti Alokasi Y. Kumulatif TW I s.d. TW berjalan &amp; persentasenya (X÷Y×100) dihitung otomatis di bawah.</div>
        @else
            <div class="fhint" style="margin:10px 0 6px">Isi Alokasi Target &amp; Realisasi TRIWULAN INI SAJA (bukan kumulatif, tidak perlu melihat isian triwulan sebelumnya) — kumulatif TW I s.d. TW berjalan dihitung otomatis di bawah.</div>
        @endif

        @php $twAktif = (int) $capaian->periode->triwulan; @endphp
        <div class="fhint" style="margin-bottom:8px">🔒 Hanya kolom TW {{ ['I', 'II', 'III', 'IV'][$twAktif - 1] }} yang bisa diubah dari sesi verifikasi ini (sesuai periode isian ini) — kolom triwulan lain ditampilkan sebagai referensi, disunting lewat sesi verifikasi bulan pada triwulan itu sendiri.</div>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th></th>
                        @foreach (['I', 'II', 'III', 'IV'] as $tw)
                            <th style="text-align:center">TW {{ $tw }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @if ($capaian->masterIku->pakaiRasio())
                    @foreach ([
                        ['x_alokasi', $capaian->masterIku->deskripsi_x ?: 'Pembilang (X)', 'Alokasi', false],
                        ['y_alokasi', $capaian->masterIku->deskripsi_y ?: 'Penyebut (Y)', 'Alokasi', false],
                        ['x_realisasi', $capaian->masterIku->deskripsi_x ?: 'Pembilang (X)', 'Realisasi', true],
                        ['y_realisasi', $capaian->masterIku->deskripsi_y ?: 'Penyebut (Y)', 'Realisasi', false],
                    ] as [$prefix, $label, $bagian, $bisaDiedit])
                        <tr>
                            <td>
                                {{ $label }} — {{ $bagian }}
                                @if ($bisaDiedit)
                                    <span class="muted" style="font-weight:400">(TW ini)</span>
                                @else
                                    <span class="muted" style="font-weight:400">
                                        ({{ $bagian === 'Alokasi' ? 'Target Tahunan' : 'otomatis = Alokasi Y' }})
                                    </span>
                                @endif
                            </td>
                            @for ($tw = 1; $tw <= 4; $tw++)
                                <td style="text-align:center{{ $prefix === 'x_realisasi' && $tw === $twAktif ? ';min-width:220px;text-align:left' : '' }}">
                                    @if ($prefix === 'x_realisasi' && $tw === $twAktif)
                                        <div style="max-height:140px;overflow-y:auto;display:flex;flex-direction:column;gap:3px">
                                            @foreach ($this->rincianNBisaDipilih() as $n)
                                                <label class="fl-row" style="cursor:pointer;gap:6px;font-size:11.5px">
                                                    <input type="checkbox" wire:model.live="rincianNPilih.{{ $n->id }}">
                                                    <span>{{ $n->uraian }}</span>
                                                </label>
                                            @endforeach
                                            @foreach ($this->rincianNTerkunci() as $n)
                                                <div class="muted" style="font-size:11.5px" title="Direalisasikan TW {{ ['I', 'II', 'III', 'IV'][$n->triwulan_realisasi - 1] }}, tidak bisa diubah dari sesi verifikasi ini">✓ {{ $n->uraian }} <span style="font-weight:400">(TW {{ ['I', 'II', 'III', 'IV'][$n->triwulan_realisasi - 1] }})</span></div>
                                            @endforeach
                                            @if ($this->rincianNList()->isEmpty())
                                                <span class="muted" style="font-size:11.5px">Belum ada Rincian N — tambahkan di <a wire:navigate href="{{ route('master-iku.index') }}#target">🎯 Target Tahunan</a>.</span>
                                            @endif
                                        </div>
                                        <div class="muted" style="font-size:10.5px;margin-top:2px">{{ $this->{"x_realisasi_tw{$tw}"} ?? 0 }} item terpilih = Realisasi X TW ini</div>
                                    @elseif ($bisaDiedit)
                                        <span class="muted" title="Hanya bisa diubah dari sesi verifikasi TW {{ ['I', 'II', 'III', 'IV'][$tw - 1] }}">🔒 {{ $capaianTahunan->{"{$prefix}_tw{$tw}"} ?? '-' }}</span>
                                    @else
                                        <span class="muted" title="{{ $bagian === 'Alokasi' ? 'Diisi lewat halaman Target Tahunan, bukan di sini' : 'Otomatis disalin dari Alokasi Y di Target Tahunan' }}">🔒 {{ $capaianTahunan->{"{$prefix}_tw{$tw}"} ?? '-' }}</span>
                                    @endif
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                    <tr class="muted">
                        <td>Alokasi Target Kumulatif (%)</td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->alokasiKumulatif($tw)) }}</td>
                        @endfor
                    </tr>
                    <tr class="muted">
                        <td>Realisasi Kumulatif (%)</td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->realisasiKumulatif($tw)) }}</td>
                        @endfor
                    </tr>
                @else
                    <tr>
                        <td>Alokasi Target <span class="muted" style="font-weight:400">(TW ini)</span></td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">
                                @if ($tw === $twAktif)
                                    <input type="number" step="0.01" class="inp filled" style="width:100px;text-align:center" wire:model.live="alokasi_tw{{ $tw }}">
                                    @error("alokasi_tw{$tw}")
                                        <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                    @enderror
                                @else
                                    <span class="muted" title="Hanya bisa diubah dari sesi verifikasi TW {{ ['I', 'II', 'III', 'IV'][$tw - 1] }}">🔒 {{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->{"alokasi_tw{$tw}"}) }}</span>
                                @endif
                            </td>
                        @endfor
                    </tr>
                    <tr>
                        <td>Realisasi <span class="muted" style="font-weight:400">(TW ini)</span></td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">
                                @if ($tw === $twAktif)
                                    <input type="number" step="0.01" class="inp filled" style="width:100px;text-align:center" wire:model.live="realisasi_tw{{ $tw }}">
                                    @error("realisasi_tw{$tw}")
                                        <div style="color:var(--red);font-size:10.5px">{{ $message }}</div>
                                    @enderror
                                @else
                                    <span class="muted" title="Hanya bisa diubah dari sesi verifikasi TW {{ ['I', 'II', 'III', 'IV'][$tw - 1] }}">🔒 {{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->{"realisasi_tw{$tw}"}) }}</span>
                                @endif
                            </td>
                        @endfor
                    </tr>
                    <tr class="muted">
                        <td>Alokasi Target Kumulatif</td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->alokasiKumulatif($tw)) }}</td>
                        @endfor
                    </tr>
                    <tr class="muted">
                        <td>Realisasi Kumulatif</td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatAngka($capaianTahunan->realisasiKumulatif($tw)) }}</td>
                        @endfor
                    </tr>
                @endif
                    <tr>
                        <td>Capaian % <span class="muted" style="font-weight:400">(Triwulanan)</span></td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatPersen($capaianTahunan->capaianTriwulanan($tw)) }}</td>
                        @endfor
                    </tr>
                    <tr>
                        <td>Capaian % <span class="muted" style="font-weight:400">(Setahun)</span></td>
                        @for ($tw = 1; $tw <= 4; $tw++)
                            <td style="text-align:center">{{ \App\Models\PengaturanCapaian::formatPersen($capaianTahunan->capaianSetahun($tw)) }}</td>
                        @endfor
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="fhint" style="margin-top:8px">ℹ️ Kumulatif = jumlah TW I s.d. triwulan tsb, dihitung otomatis dari isian triwulanan di atas — tidak perlu dijumlahkan manual. Capaian % (Triwulanan) = Realisasi Kumulatif ÷ Alokasi Target Kumulatif; Capaian % (Setahun) = Realisasi Kumulatif ÷ Target Tahunan — sesuai Kertas Kerja Pengukuran Kinerja Triwulanan, dibatasi maksimal sesuai <a wire:navigate href="{{ route('master-iku.index') }}">Pengaturan Rumus Capaian</a>.</div>

        <div class="btn-row" style="margin-top:14px">
            <button type="button" class="btn btn-teal" wire:click="simpanPerubahan" wire:loading.attr="disabled" wire:target="simpanPerubahan">
                <span wire:loading.remove wire:target="simpanPerubahan">💾 Simpan Perubahan</span>
                <span wire:loading wire:target="simpanPerubahan"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="sec"><span>Verifikasi Bukti Capaian per Kegiatan</span></div>
        @if ($bisaDiverifikasi)
            <div class="info">ℹ️ Uraian kegiatan bisa disunting bila ada salah ketik, lalu tandai sesuai/tidak sesuai. Klik tiap berkas untuk memeriksa &amp; menandai sesuai/tidak sesuai.</div>
        @endif

        @forelse ($kegiatanList as $kegiatan)
            @php
                $berkasKegiatan = $berkasPerKegiatan[$kegiatan->id] ?? collect();
                $bisaDikoreksi = $this->kegiatanBisaDikoreksi($kegiatan);
            @endphp
            <div class="keg" wire:key="keg-{{ $kegiatan->id }}" @if (!$bisaDikoreksi) style="opacity:.8" @endif>
                <div class="keg-head">
                    <span class="t">Kegiatan {{ $loop->iteration }}</span>
                    <x-badge-status :status="$kegiatan->status_dokumen" />
                    <span style="color:var(--muted);font-size:11.5px">
                        {{ $kegiatan->jenis === 'survei_sensus' ? 'Survei/Sensus' : 'Bukan Survei/Sensus' }}{{ $kegiatan->tahapan_survei ? ' · '.ucfirst($kegiatan->tahapan_survei) : '' }}
                    </span>
                </div>
                @unless ($bisaDikoreksi)
                    <div style="font-size:11.5px;color:var(--muted);margin-bottom:6px">🔒 Sudah diproses pada pengajuan sebelumnya — tidak ikut disunting di sini.</div>
                @endunless
                <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model="koreksiKegiatan.{{ $kegiatan->id }}" @readonly(!$bisaDikoreksi)></textarea>
                @error("koreksiKegiatan.{$kegiatan->id}")
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror

                @if ($this->uraianBisaDiverifikasi($kegiatan->id))
                    <div x-data="{ pendingMark: null }" style="margin-top:8px">
                        <div style="display:flex;gap:8px">
                            <button type="button" class="mark"
                                :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $kegiatan->status_verifikasi_uraian === 'terverifikasi' ? 'true' : 'false' }}) }"
                                @click="pendingMark = 'ok'"
                                wire:click="tandaiUraianSesuai({{ $kegiatan->id }})" wire:loading.attr="disabled" wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})">
                                <span wire:loading.remove wire:target="tandaiUraianSesuai({{ $kegiatan->id }})">✓ Sesuai</span>
                                <span wire:loading wire:target="tandaiUraianSesuai({{ $kegiatan->id }})"><i class="spin"></i></span>
                            </button>
                            <button type="button" class="mark"
                                :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $kegiatan->status_verifikasi_uraian === 'ditolak' ? 'true' : 'false' }}) }"
                                @click="pendingMark = 'no'"
                                wire:click="tandaiUraianTolak({{ $kegiatan->id }})" wire:loading.attr="disabled" wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})">
                                <span wire:loading.remove wire:target="tandaiUraianTolak({{ $kegiatan->id }})">✕ Tidak Sesuai</span>
                                <span wire:loading wire:target="tandaiUraianTolak({{ $kegiatan->id }})"><i class="spin"></i></span>
                            </button>
                        </div>
                        <div class="field" style="margin-top:10px;margin-bottom:0">
                            <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                            <textarea class="inp filled" style="height:auto;display:block;font-size:11.5px" rows="2"
                                wire:model="catatanUraian.{{ $kegiatan->id }}" placeholder="mis. Uraian belum menjelaskan output yang dihasilkan"
                                x-on:blur="if (pendingMark === 'no') $wire.tandaiUraianTolak({{ $kegiatan->id }})"></textarea>
                            @error('catatanUraian.'.$kegiatan->id)
                                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                            @enderror

                            <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                x-show="pendingMark !== null || {{ $kegiatan->status_verifikasi_uraian !== 'menunggu' ? 'true' : 'false' }}">
                                <button type="button" class="btn btn-ghost btn-sm"
                                    x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $kegiatan->status_verifikasi_uraian === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiUraianTolak({{ $kegiatan->id }}) : $wire.tandaiUraianSesuai({{ $kegiatan->id }})"
                                    wire:loading.attr="disabled" wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})">
                                    <span wire:loading.remove wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})">💾 Simpan Verifikasi</span>
                                    <span wire:loading wire:target="tandaiUraianSesuai({{ $kegiatan->id }}),tandaiUraianTolak({{ $kegiatan->id }})"><i class="spin"></i> Menyimpan…</span>
                                </button>
                                @if ($kegiatan->status_verifikasi_uraian !== 'menunggu' && !$errors->has('catatanUraian.'.$kegiatan->id))
                                    <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @elseif ($kegiatan->catatan_uraian)
                    <div class="field" style="margin-top:6px;margin-bottom:0">
                        <label style="font-size:11.5px">Catatan</label>
                        <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $kegiatan->catatan_uraian }}</p>
                    </div>
                @endif

                @forelse ($berkasKegiatan as $file)
                    <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" style="margin-top:8px" wire:key="berkas-{{ $file->id }}">
                        <span class="nm">
                            📄 {{ $file->nama_file }}
                            @if ($file->status_verifikasi === 'ditolak')
                                <span class="sub" style="color:var(--red)">Tidak Sesuai</span>
                            @endif
                        </span>
                        <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file->id }}">🔍 Periksa</button>
                    </div>
                @empty
                    <p style="color:var(--muted);font-size:12.5px;margin-top:8px">Belum ada bukti diunggah.</p>
                @endforelse
            </div>
        @empty
            <p style="color:var(--muted);font-size:13px">Belum ada kegiatan.</p>
        @endforelse
    </div>

    @if ($kegiatanList->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Rincian Output (RO)</span></div>
            <div class="info">ℹ️ Opsional — isi hanya bila IKU ini belum punya realisasi triwulan berjalan, agar tabel Realisasi Volume RO &amp; Progres Pelaksanaan Kegiatan di notula terisi benar. Satu kegiatan boleh punya lebih dari satu RO — tidak wajib diisi untuk tiap kegiatan/IKU.</div>

            @php $nomorRo = 0; @endphp
            @foreach ($kegiatanList as $kegiatan)
                @php $bisaDikoreksi = $this->kegiatanBisaDikoreksi($kegiatan); @endphp
                <div class="keg" wire:key="ro-keg-{{ $kegiatan->id }}" @if (!$bisaDikoreksi) style="opacity:.8" @endif>
                    @foreach (($rincianOutput[$kegiatan->id] ?? []) as $kunciRo => $baris)
                        @php $nomorRo++; @endphp
                        <div class="keg" style="margin-top:8px" wire:key="ro-{{ $kegiatan->id }}-{{ $kunciRo }}">
                            <div class="keg-head">
                                <span class="t">RO {{ $nomorRo }}</span>
                                @if ($bisaDikoreksi)
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="hapusRo({{ $kegiatan->id }}, '{{ $kunciRo }}')" wire:loading.attr="disabled" wire:target="hapusRo({{ $kegiatan->id }}, '{{ $kunciRo }}')">✕ Hapus</button>
                                @endif
                            </div>

                            <div class="field">
                                <label>Rincian Output (RO)</label>
                                <input type="text" class="inp filled" wire:model="rincianOutput.{{ $kegiatan->id }}.{{ $kunciRo }}.uraian" @readonly(!$bisaDikoreksi) placeholder="mis. Publikasi/Laporan Statistik Sumber Daya Mineral dan Konstruksi">
                                @error("rincianOutput.{$kegiatan->id}.{$kunciRo}.uraian")
                                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="row2" style="margin-top:6px">
                                <div class="field">
                                    <label>Realisasi Volume RO</label>
                                    <input type="text" class="inp filled" wire:model="rincianOutput.{{ $kegiatan->id }}.{{ $kunciRo }}.volume_ro" @readonly(!$bisaDikoreksi) placeholder="mis. 1 publikasi">
                                    @error("rincianOutput.{$kegiatan->id}.{$kunciRo}.volume_ro")
                                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="field">
                                    <label>Progres Pelaksanaan Kegiatan (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" class="inp filled" wire:model="rincianOutput.{{ $kegiatan->id }}.{{ $kunciRo }}.progres_persen" @readonly(!$bisaDikoreksi) placeholder="mis. 100">
                                    @error("rincianOutput.{$kegiatan->id}.{$kunciRo}.progres_persen")
                                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    @endforeach

                    @if ($bisaDikoreksi)
                        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" wire:click="tambahRo({{ $kegiatan->id }})" wire:loading.attr="disabled" wire:target="tambahRo({{ $kegiatan->id }})">+ Tambah RO</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($kendalaSolusiList->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Kendala &amp; Solusi</span></div>
            @foreach ($kendalaSolusiList as $ks)
                <div class="keg" wire:key="ks-{{ $ks->id }}">
                    <div class="keg-head">
                        <span class="t">Pasangan {{ $loop->iteration }}</span>
                        <x-badge-status :status="$ks->status_verifikasi === 'terverifikasi' ? 'diverifikasi' : ($ks->status_verifikasi === 'ditolak' ? 'dikembalikan' : 'diajukan')" :label="$ks->status_verifikasi === 'terverifikasi' ? 'Sesuai' : ($ks->status_verifikasi === 'ditolak' ? 'Tidak Sesuai' : 'Menunggu')" />
                    </div>
                    <div class="field" style="margin-bottom:10px">
                        <label style="font-size:11.5px">Kendala</label>
                        <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model="koreksiKendala.{{ $ks->id }}.kendala"></textarea>
                    </div>
                    @if ($ks->solusi)
                        <div class="field" style="margin-bottom:10px">
                            <label style="font-size:11.5px">Solusi</label>
                            <textarea class="inp filled" style="height:auto;display:block;font-style:italic" rows="2" wire:model="koreksiKendala.{{ $ks->id }}.solusi"></textarea>
                        </div>
                    @endif

                    @if ($this->kendalaBisaDiverifikasi($ks->id))
                        {{-- pendingMark = pilihan terakhir yg DIKLIK, ditampilkan LANGSUNG (optimistic)
                            tanpa menunggu balasan server (Supabase remote ~400ms/query) — supaya tombol
                            tidak terasa perlu diklik berkali-kali sebelum berubah warna. Begitu respons
                            server datang, render ulang dari status_verifikasi tetap jadi sumber
                            kebenaran akhir (fallback saat pendingMark masih null, mis. buka modal baru). --}}
                        <div x-data="{ pendingMark: null }">
                            <div style="display:flex;gap:8px;margin-top:6px">
                                <button type="button" class="mark"
                                    :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $ks->status_verifikasi === 'terverifikasi' ? 'true' : 'false' }}) }"
                                    @click="pendingMark = 'ok'"
                                    wire:click="tandaiKendalaSesuai({{ $ks->id }})" wire:loading.attr="disabled" wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})">
                                    <span wire:loading.remove wire:target="tandaiKendalaSesuai({{ $ks->id }})">✓ Sesuai</span>
                                    <span wire:loading wire:target="tandaiKendalaSesuai({{ $ks->id }})"><i class="spin"></i></span>
                                </button>
                                <button type="button" class="mark"
                                    :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $ks->status_verifikasi === 'ditolak' ? 'true' : 'false' }}) }"
                                    @click="pendingMark = 'no'"
                                    wire:click="tandaiKendalaTolak({{ $ks->id }})" wire:loading.attr="disabled" wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})">
                                    <span wire:loading.remove wire:target="tandaiKendalaTolak({{ $ks->id }})">✕ Tidak Sesuai</span>
                                    <span wire:loading wire:target="tandaiKendalaTolak({{ $ks->id }})"><i class="spin"></i></span>
                                </button>
                            </div>
                            <div class="field" style="margin-top:10px;margin-bottom:0">
                                <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                                {{-- @blur menyusulkan ulang tandaiKendalaTolak() begitu catatan selesai
                                    diisi (lihat catatan yang sama pada catatanBerkas di atas) — tanpa ini
                                    klik "Tidak Sesuai" saat catatan kosong tampak "berhasil" (tombolnya
                                    sudah merah lewat pendingMark optimistic) padahal belum tersimpan. --}}
                                <textarea class="inp filled" style="height:auto;display:block;font-size:11.5px" rows="2"
                                    wire:model="catatanKendala.{{ $ks->id }}" placeholder="mis. Solusi belum konkret / tidak relevan dengan kendala"
                                    x-on:blur="if (pendingMark === 'no') $wire.tandaiKendalaTolak({{ $ks->id }})"></textarea>
                                @error('catatanKendala.'.$ks->id)
                                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                @enderror

                                <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                    x-show="pendingMark !== null || {{ $ks->status_verifikasi !== 'menunggu' ? 'true' : 'false' }}">
                                    <button type="button" class="btn btn-ghost btn-sm"
                                        x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $ks->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiKendalaTolak({{ $ks->id }}) : $wire.tandaiKendalaSesuai({{ $ks->id }})"
                                        wire:loading.attr="disabled" wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})">
                                        <span wire:loading.remove wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})">💾 Simpan Verifikasi</span>
                                        <span wire:loading wire:target="tandaiKendalaSesuai({{ $ks->id }}),tandaiKendalaTolak({{ $ks->id }})"><i class="spin"></i> Menyimpan…</span>
                                    </button>
                                    @if ($ks->status_verifikasi !== 'menunggu' && !$errors->has('catatanKendala.'.$ks->id))
                                        <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @elseif ($ks->catatan)
                        <div class="field" style="margin-top:6px;margin-bottom:0">
                            <label style="font-size:11.5px">Catatan</label>
                            <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $ks->catatan }}</p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($bagianKustomList->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Bagian Kustom</span></div>
            @foreach ($bagianKustomList->groupBy('bagian_kustom_id') as $poinPerBagian)
                @php $namaBagian = $poinPerBagian->first()->bagianKustom->nama; @endphp
                <div style="font-size:12px;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">{{ $namaBagian }}</div>
                @foreach ($poinPerBagian as $poin)
                    @php $berkasPoin = $berkasPerBagianKustom[$poin->id] ?? collect(); @endphp
                    <div class="keg" wire:key="bagian-kustom-{{ $poin->id }}">
                        <p style="font-size:13px;margin-bottom:8px">{{ $poin->teks }}</p>

                        @if ($this->bagianKustomBisaDiverifikasi($poin->id))
                            <div x-data="{ pendingMark: null }" style="margin-bottom:10px">
                                <div style="display:flex;gap:8px">
                                    <button type="button" class="mark"
                                        :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $poin->status_verifikasi === 'terverifikasi' ? 'true' : 'false' }}) }"
                                        @click="pendingMark = 'ok'"
                                        wire:click="tandaiBagianKustomSesuai({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiBagianKustomSesuai({{ $poin->id }})">✓ Sesuai</span>
                                        <span wire:loading wire:target="tandaiBagianKustomSesuai({{ $poin->id }})"><i class="spin"></i></span>
                                    </button>
                                    <button type="button" class="mark"
                                        :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }}) }"
                                        @click="pendingMark = 'no'"
                                        wire:click="tandaiBagianKustomTolak({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiBagianKustomTolak({{ $poin->id }})">✕ Tidak Sesuai</span>
                                        <span wire:loading wire:target="tandaiBagianKustomTolak({{ $poin->id }})"><i class="spin"></i></span>
                                    </button>
                                </div>
                                <div class="field" style="margin-top:10px;margin-bottom:0">
                                    <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                                    <textarea class="inp filled" style="height:auto;display:block;font-size:11.5px" rows="2"
                                        wire:model="catatanBagianKustom.{{ $poin->id }}" placeholder="mis. Narasi belum menjelaskan tindak lanjut yang dilakukan"
                                        x-on:blur="if (pendingMark === 'no') $wire.tandaiBagianKustomTolak({{ $poin->id }})"></textarea>
                                    @error('catatanBagianKustom.'.$poin->id)
                                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                    @enderror

                                    <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                        x-show="pendingMark !== null || {{ $poin->status_verifikasi !== 'menunggu' ? 'true' : 'false' }}">
                                        <button type="button" class="btn btn-ghost btn-sm"
                                            x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiBagianKustomTolak({{ $poin->id }}) : $wire.tandaiBagianKustomSesuai({{ $poin->id }})"
                                            wire:loading.attr="disabled" wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})">
                                            <span wire:loading.remove wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})">💾 Simpan Verifikasi</span>
                                            <span wire:loading wire:target="tandaiBagianKustomSesuai({{ $poin->id }}),tandaiBagianKustomTolak({{ $poin->id }})"><i class="spin"></i> Menyimpan…</span>
                                        </button>
                                        @if ($poin->status_verifikasi !== 'menunggu' && !$errors->has('catatanBagianKustom.'.$poin->id))
                                            <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @elseif ($poin->catatan)
                            <div class="field" style="margin-bottom:10px">
                                <label style="font-size:11.5px">Catatan</label>
                                <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $poin->catatan }}</p>
                            </div>
                        @endif

                        @forelse ($berkasPoin as $file)
                            <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" wire:key="berkas-{{ $file->id }}">
                                <span class="nm">📄 {{ $file->nama_file }} <span class="sub">Bukti dukung</span></span>
                                <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file->id }}">🔍 Periksa</button>
                            </div>
                        @empty
                            <p style="color:var(--muted);font-size:12.5px">Belum ada bukti diunggah.</p>
                        @endforelse
                    </div>
                @endforeach
            @endforeach
        </div>
    @endif

    @php
        $semuaBerkas = collect($berkasPerKegiatan)->flatten(1)
            ->concat($rtlSebelumnya->flatMap->berkas)
            ->concat(collect($berkasPerBagianKustom)->flatten(1));
    @endphp

    @foreach ($semuaBerkas as $file)
        <div class="modal-overlay" x-show="modalBerkas === {{ $file->id }}" x-cloak style="display:none" @click.self="modalBerkas = null" wire:key="modal-berkas-{{ $file->id }}">
            <div class="modal">
                <div class="modal-top">
                    <div class="mt-t">📄 {{ $file->nama_file }}</div>
                    <button type="button" class="x" @click="modalBerkas = null">✕</button>
                </div>
                <div class="modal-body">
                    <div class="pdf-view">
                        <h4>{{ strtoupper(str_replace('_', ' ', $file->kategori)) }}</h4>
                        <div class="pmeta">{{ $file->nama_file }}</div>
                        {{-- src diisi lewat Alpine (bukan langsung di server) SUPAYA berkas baru
                            diminta/dimuat saat modal-nya benar-benar dibuka — kalau semua
                            &lt;iframe&gt; ini diberi src langsung, browser akan diam-diam mengunduh
                            SEMUA berkas bukti di halaman ini sekaligus saat halaman dimuat,
                            meski modalnya masih tersembunyi (x-show cuma mengatur display:none,
                            tidak mencegah iframe mulai memuat).

                            wire:ignore WAJIB ada — tanpanya, tiap wire:click APA PUN di komponen
                            ini (termasuk tandaiSesuai/tandaiTolak) membuat Livewire ikut me-morph
                            elemen ini, dan menimpa iframe.src lewat JS SELALU memicu browser
                            memuat ulang PDF dari awal walau ke URL yang sama persis — inilah
                            sebabnya tombol Sesuai/Tidak Sesuai terasa jauh lebih lambat dibanding
                            tombol lain yang tidak bersebelahan dengan iframe. wire:ignore membuat
                            Livewire sama sekali tidak menyentuh elemen ini lagi setelah render
                            pertama; reaktivitas x-bind:src terhadap modalBerkas tetap jalan
                            normal karena itu urusan Alpine, bukan Livewire. --}}
                        <iframe
                            wire:ignore
                            x-bind:src="modalBerkas === {{ $file->id }} ? @js(route('berkas.show', $file)) : null"
                            class="pdf-frame" title="Pratinjau {{ $file->nama_file }}"
                        ></iframe>
                        <a href="{{ route('berkas.show', $file) }}" target="_blank" class="btn btn-ghost btn-sm" style="margin-top:10px;align-self:flex-start">🔗 Buka di tab baru / layar penuh</a>
                    </div>
                    <div class="verify-panel" x-data="{ pendingMark: null }">
                        <div class="vp-t">Verifikasi Berkas</div>
                        @if ($this->berkasBisaDiverifikasi($file->id))
                            {{-- pendingMark = pilihan terakhir yg DIKLIK, ditampilkan LANGSUNG (optimistic)
                                tanpa menunggu balasan server (Supabase remote ~400ms/query) — supaya
                                "Sesuai" langsung ijo di klik pertama, dan "Tidak Sesuai" langsung
                                kelihatan aktif walau ditolak validasi (catatan kosong) supaya jelas
                                kalau perlu isi catatan dulu. status_verifikasi tetap sumber kebenaran
                                akhir lewat pendingMark===null (mis. saat modal baru dibuka). --}}
                            <button type="button" class="mark"
                                :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $file->status_verifikasi === 'terverifikasi' ? 'true' : 'false' }}) }"
                                @click="pendingMark = 'ok'"
                                wire:click="tandaiSesuai({{ $file->id }})" wire:loading.attr="disabled" wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})">
                                <span wire:loading.remove wire:target="tandaiSesuai({{ $file->id }})">✓ Sesuai</span>
                                <span wire:loading wire:target="tandaiSesuai({{ $file->id }})"><i class="spin"></i></span>
                            </button>
                            <button type="button" class="mark"
                                :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $file->status_verifikasi === 'ditolak' ? 'true' : 'false' }}) }"
                                @click="pendingMark = 'no'"
                                wire:click="tandaiTolak({{ $file->id }})" wire:loading.attr="disabled" wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})">
                                <span wire:loading.remove wire:target="tandaiTolak({{ $file->id }})">✕ Tidak Sesuai</span>
                                <span wire:loading wire:target="tandaiTolak({{ $file->id }})"><i class="spin"></i></span>
                            </button>
                            <div class="field" style="margin-top:12px">
                                <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                                {{-- Klik "Tidak Sesuai" saat catatan masih kosong ditolak validasi (lihat
                                    tandaiTolak()) — tombolnya SUDAH terlanjur merah lewat pendingMark
                                    optimistic di atas, jadi kalau berhenti di situ saja pengguna mengira
                                    penandaannya sudah tersimpan padahal belum. @blur di sini menyusulkan
                                    ulang tandaiTolak() begitu catatan selesai diisi (fokus pindah, termasuk
                                    saat modal ditutup) supaya tidak perlu klik "Tidak Sesuai" dua kali. --}}
                                <textarea class="inp filled" style="height:auto;display:block;min-height:56px;font-size:11.5px" rows="3"
                                    wire:model="catatanBerkas.{{ $file->id }}" placeholder="mis. Bukti belum menunjukkan tanggal pelaksanaan…"
                                    :style="pendingMark === 'no' && {{ $file->status_verifikasi !== 'ditolak' ? 'true' : 'false' }} ? 'border-color:var(--red)' : ''"
                                    x-on:blur="if (pendingMark === 'no') $wire.tandaiTolak({{ $file->id }})"></textarea>
                                @error('catatanBerkas.'.$file->id)
                                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                @enderror

                                {{-- Tombol simpan eksplisit untuk penandaan Sesuai/Tidak Sesuai
                                    beserta catatannya — @blur di atas sudah menyusulkan simpan
                                    otomatis, tapi pengguna tidak selalu sadar itu terjadi (mis.
                                    langsung menutup modal setelah mengetik). Tombol ini memicu
                                    penyimpanan yang sama secara eksplisit, dan label "✓ Tersimpan"
                                    di sampingnya (dibaca langsung dari status_verifikasi, bukan
                                    state Alpine) memberi kepastian nyata bahwa penandaan + catatan
                                    sudah ada di database, bukan cuma tampak begitu. Catatan hanya
                                    relevan untuk "Tidak Sesuai" — tandaiSesuai() di komponen
                                    SELALU mengosongkannya (lihat catatan di sana). --}}
                                <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                    x-show="pendingMark !== null || {{ $file->status_verifikasi !== 'menunggu' ? 'true' : 'false' }}">
                                    <button type="button" class="btn btn-ghost btn-sm"
                                        x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $file->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiTolak({{ $file->id }}) : $wire.tandaiSesuai({{ $file->id }})"
                                        wire:loading.attr="disabled" wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})">
                                        <span wire:loading.remove wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})">💾 Simpan Verifikasi</span>
                                        <span wire:loading wire:target="tandaiSesuai({{ $file->id }}),tandaiTolak({{ $file->id }})"><i class="spin"></i> Menyimpan…</span>
                                    </button>
                                    @if ($file->status_verifikasi !== 'menunggu' && !$errors->has('catatanBerkas.'.$file->id))
                                        <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <span class="mark {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" style="cursor:default">{{ $file->status_verifikasi === 'terverifikasi' ? '✓ Sesuai' : ($file->status_verifikasi === 'ditolak' ? '✕ Tidak Sesuai' : '… Menunggu') }}</span>
                            @if ($bisaDiverifikasi)
                                <div style="font-size:11px;color:var(--muted);margin-top:6px">🔒 Milik kegiatan yang sudah diproses sebelumnya.</div>
                            @endif
                            @if ($file->catatan)
                                <div class="field" style="margin-top:12px">
                                    <label style="font-size:11.5px">Catatan</label>
                                    <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $file->catatan }}</p>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($rtlSebelumnya->isNotEmpty())
        <div class="card">
            <div class="sec"><span>Pengecekan Realisasi RTL Triwulan Sebelumnya</span></div>
            <div class="info">ℹ️ RTL yang direncanakan pada triwulan sebelumnya ditampilkan berdampingan dengan realisasi yang dilaporkan ketua tim, beserta bukti bila ada. Cukup periksa kesesuaiannya — tanpa skor persentase.</div>

            @foreach ($rtlSebelumnya as $poin)
                <div class="match-row" wire:key="rtl-check-{{ $poin->id }}">
                    <div class="match-col"><div class="mc-lbl">RTL Direncanakan</div><div class="mc-txt">{{ $poin->rtl_teks }}</div></div>
                    <div class="match-badge {{ $poin->sudahDievaluasi() ? 'mb-ok' : 'mb-warn' }}">{{ $poin->sudahDievaluasi() ? '✓' : '…' }}</div>
                    <div class="match-col">
                        <div class="mc-lbl">Realisasi Dilaporkan</div>
                        <textarea class="inp filled" style="height:auto;display:block;font-style:italic;font-size:12px" rows="2"
                            wire:model="koreksiRtlRealisasi.{{ $poin->id }}" placeholder="— belum dilaporkan —"></textarea>
                        @foreach ($poin->berkas as $file)
                            <div class="filechip {{ $file->status_verifikasi === 'terverifikasi' ? 'ok' : ($file->status_verifikasi === 'ditolak' ? 'no' : '') }}" style="margin-top:6px" wire:key="berkas-{{ $file->id }}">
                                <span class="nm">
                                    📄 {{ $file->nama_file }}
                                    @if ($file->status_verifikasi === 'ditolak')
                                        <span class="sub" style="color:var(--red)">Tidak Sesuai</span>
                                    @endif
                                </span>
                                <button type="button" class="btn btn-ghost btn-sm" @click="modalBerkas = {{ $file->id }}">🔍 Periksa</button>
                            </div>
                        @endforeach

                        @if ($this->rtlBisaDiverifikasi($poin->id))
                            <div x-data="{ pendingMark: null }" style="margin-top:8px">
                                <div style="display:flex;gap:8px">
                                    <button type="button" class="mark"
                                        :class="{ ok: pendingMark === 'ok' || (pendingMark === null && {{ $poin->status_verifikasi === 'terverifikasi' ? 'true' : 'false' }}) }"
                                        @click="pendingMark = 'ok'"
                                        wire:click="tandaiRtlSesuai({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiRtlSesuai({{ $poin->id }})">✓ Sesuai</span>
                                        <span wire:loading wire:target="tandaiRtlSesuai({{ $poin->id }})"><i class="spin"></i></span>
                                    </button>
                                    <button type="button" class="mark"
                                        :class="{ no: pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }}) }"
                                        @click="pendingMark = 'no'"
                                        wire:click="tandaiRtlTolak({{ $poin->id }})" wire:loading.attr="disabled" wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})">
                                        <span wire:loading.remove wire:target="tandaiRtlTolak({{ $poin->id }})">✕ Tidak Sesuai</span>
                                        <span wire:loading wire:target="tandaiRtlTolak({{ $poin->id }})"><i class="spin"></i></span>
                                    </button>
                                </div>
                                <div class="field" style="margin-top:8px;margin-bottom:0">
                                    <label style="font-size:11.5px">Catatan (wajib bila tidak sesuai)</label>
                                    <textarea class="inp filled" style="height:auto;display:block;font-size:11.5px" rows="2"
                                        wire:model="catatanRtl.{{ $poin->id }}" placeholder="mis. Realisasi belum sesuai dengan RTL yang direncanakan"
                                        x-on:blur="if (pendingMark === 'no') $wire.tandaiRtlTolak({{ $poin->id }})"></textarea>
                                    @error('catatanRtl.'.$poin->id)
                                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                                    @enderror

                                    <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                                        x-show="pendingMark !== null || {{ $poin->status_verifikasi !== 'menunggu' ? 'true' : 'false' }}">
                                        <button type="button" class="btn btn-ghost btn-sm"
                                            x-on:click="(pendingMark === 'no' || (pendingMark === null && {{ $poin->status_verifikasi === 'ditolak' ? 'true' : 'false' }})) ? $wire.tandaiRtlTolak({{ $poin->id }}) : $wire.tandaiRtlSesuai({{ $poin->id }})"
                                            wire:loading.attr="disabled" wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})">
                                            <span wire:loading.remove wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})">💾 Simpan Verifikasi</span>
                                            <span wire:loading wire:target="tandaiRtlSesuai({{ $poin->id }}),tandaiRtlTolak({{ $poin->id }})"><i class="spin"></i> Menyimpan…</span>
                                        </button>
                                        @if ($poin->status_verifikasi !== 'menunggu' && !$errors->has('catatanRtl.'.$poin->id))
                                            <span style="color:#16a34a;font-size:11.5px">✓ Tersimpan</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @elseif ($poin->catatan)
                            <div class="field" style="margin-top:8px;margin-bottom:0">
                                <label style="font-size:11.5px">Catatan</label>
                                <p style="font-size:12.5px;color:var(--muted);margin:0">{{ $poin->catatan }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($bisaDiverifikasi)
        <div class="btn-row" style="margin-top:16px">
            <button type="button" class="btn btn-teal" wire:click="verifikasiSelesai" wire:loading.attr="disabled" wire:target="verifikasiSelesai,kembalikanKeKetuaTim,simpanSementara">
                <span wire:loading.remove wire:target="verifikasiSelesai">✓ Verifikasi Selesai — Masukkan ke Notula</span>
                <span wire:loading wire:target="verifikasiSelesai"><i class="spin"></i> Memproses…</span>
            </button>
            <button type="button" class="btn btn-red" wire:click="kembalikanKeKetuaTim" wire:loading.attr="disabled" wire:target="verifikasiSelesai,kembalikanKeKetuaTim,simpanSementara">
                <span wire:loading.remove wire:target="kembalikanKeKetuaTim">↩ Kembalikan ke Ketua Tim</span>
                <span wire:loading wire:target="kembalikanKeKetuaTim"><i class="spin"></i> Mengembalikan…</span>
            </button>
            <button type="button" class="btn btn-ghost" wire:click="simpanSementara" wire:loading.attr="disabled" wire:target="verifikasiSelesai,kembalikanKeKetuaTim,simpanSementara">
                <span wire:loading.remove wire:target="simpanSementara">💾 Simpan Sementara</span>
                <span wire:loading wire:target="simpanSementara"><i class="spin"></i> Menyimpan…</span>
            </button>
        </div>
        @if ($capaian->status === \App\Models\Capaian::STATUS_SEDANG_DITANGANI)
            <div class="info" style="margin-top:10px">💾 Pemeriksaan ini masih <b>Sedang Ditangani</b> — progres tersimpan, lanjutkan menandai berkas lalu pilih "Verifikasi Selesai" atau "Kembalikan ke Ketua Tim" saat sudah selesai memeriksa semuanya.</div>
        @endif
    @elseif ($bisaDibukaKembali)
        <div class="card">
            <div class="sec"><span>Buka Kembali Isian</span></div>
            <div class="info warn">⚠️ Isian ini sudah <b>disetujui</b> dan masuk notula final. Membuka kembali akan mengembalikannya ke Ketua Tim untuk ditambahkan kegiatan baru — kegiatan yang sudah disetujui sebelumnya TIDAK ikut berubah/terhapus.</div>
            <div class="field" style="margin-top:10px">
                <label style="font-size:11.5px">Alasan membuka kembali (opsional)</label>
                <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="catatanBukaKembali" placeholder="mis. Ada kegiatan tambahan yang perlu dilaporkan…"></textarea>
            </div>
            <div class="btn-row" style="margin-top:12px">
                <button type="button" class="btn btn-red" wire:click="bukaKembali" wire:loading.attr="disabled" wire:target="bukaKembali">
                    <span wire:loading.remove wire:target="bukaKembali">↩ Buka Kembali ke Ketua Tim</span>
                    <span wire:loading wire:target="bukaKembali"><i class="spin"></i> Memproses…</span>
                </button>
                <a wire:navigate href="{{ route('verifikasi.index') }}" class="btn btn-ghost">← Kembali ke Daftar</a>
            </div>
        </div>
    @else
        <div class="btn-row" style="margin-top:16px">
            <a wire:navigate href="{{ route('verifikasi.index') }}" class="btn btn-ghost">← Kembali ke Daftar</a>
        </div>
    @endif
</div>
