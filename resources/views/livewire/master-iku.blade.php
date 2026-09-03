<div>
    <div class="page-head">
        <div class="page-title">Master IKU</div>
        <div class="page-sub">Kelola Indikator Kinerja Utama sebagai sumber dropdown &amp; penamaan otomatis di seluruh modul.</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="info red" style="margin-bottom:14px">⚠️ {{ session('error') }}</div>
    @endif

    @if ($pratinjauErrorStruktural)
        <div class="card card-red" style="margin-bottom:16px">
            <div class="sec"><span>✕ Berkas Ditolak</span></div>
            <ul style="margin:0;padding-left:18px;color:var(--red);font-size:13px;line-height:1.8">
                @foreach ($pratinjauErrorStruktural as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:12px">
            <div class="ico ico-teal" style="width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px">📥</div>
            <div>
                <div style="font-weight:700;color:var(--ink);font-size:13px">Template Sheet Master_IKU (.xlsx)</div>
                <div class="fhint" style="margin-top:2px">Kolom: Nama Sasaran, Kode Indikator, Penanggung Jawab (Tim), Indikator Kinerja, Dasar Hitung, Basis Data, Jenis Periode, Jenis Nilai (%/Non %), Satuan, Target Tahunan, Deskripsi &amp; Target X/Y (khusus "%"), Alokasi Target TW I-IV. Lihat sheet "Daftar Nama" untuk saran nama Tim yang sudah ada. Jangan hapus baris contoh (baris 2-3) &amp; petunjuk (baris 4).</div>
            </div>
        </div>
        <button type="button" class="btn btn-teal" wire:click="downloadTemplate">⬇ Unduh Template (.xlsx)</button>
    </div>

    @if ($pratinjau === null)
        <div class="card">
            <div class="sec"><span>Unggah Data IKU (Excel)</span></div>
            <div class="fhint" style="margin-bottom:14px">Prosesnya 2 langkah: berkas diunggah &amp; diperiksa dulu di sini (belum tersimpan ke database), lalu ditampilkan sebagai pratinjau baris valid/error untuk dicek sebelum menekan "Konfirmasi Import" pada langkah berikutnya.</div>

            <div class="row2">
                <div class="field">
                    <label>Tahun Alokasi Target <span class="req">*</span></label>
                    <input type="number" class="inp filled" wire:model="tahunImpor" min="2000" max="2100">
                    <div class="fhint">Target Tahunan &amp; Alokasi TW I-IV dari file disimpan untuk tahun ini (berkas Excel tidak punya kolom Tahun).</div>
                    @error('tahunImpor')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
                <div class="field">
                    <label>Mode Import <span class="req">*</span></label>
                    <select class="inp filled" wire:model="modeImpor">
                        <option value="insert">Insert baru (tolak Kode Indikator yang sudah ada)</option>
                        <option value="upsert">Upsert (perbarui Kode Indikator yang sudah ada)</option>
                    </select>
                    <div class="fhint">
                        <b>Insert baru</b>: khusus menambah IKU yang belum pernah ada — bila satu saja Kode Indikator di file sudah ada di database, baris itu ditolak (aman dipakai saat pertama kali impor).
                        <b>Upsert</b>: Kode Indikator yang sudah ada akan DITIMPA datanya dengan isi file ini, yang belum ada tetap ditambah baru — pakai ini saat mengunggah ulang untuk memperbaiki/memperbarui data IKU yang sudah ada.
                    </div>
                    @error('modeImpor')
                        <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="field" x-data="{ pendingExcelName: '' }">
                <label>Berkas Excel (.xlsx) <span class="req">*</span></label>

                @if ($excelFile)
                    <div class="filechip">
                        <span class="nm">📄 {{ $excelFile->getClientOriginalName() }}</span>
                        <span class="x" style="cursor:pointer" wire:click="$set('excelFile', null)">✕</span>
                    </div>
                @else
                    <label class="upload" style="cursor:pointer;display:block">
                        <div class="big">📤</div>
                        Klik untuk memilih berkas Excel (.xlsx) yang sudah diisi sesuai template
                        <input type="file" wire:model="excelFile" accept=".xlsx" style="display:none"
                            @change="pendingExcelName = $event.target.files[0]?.name || ''">
                    </label>

                    {{-- Tampil SEKETIKA berkas dipilih (dari File API browser via @change di
                         atas) — tidak menunggu unggahannya ke server benar-benar dimulai
                         dulu baru terlihat, supaya tidak terasa seperti tidak terjadi
                         apa-apa selama jeda antara memilih berkas dan unggahan mulai jalan. --}}
                    <div x-show="pendingExcelName !== ''" x-cloak style="font-size:11.5px;color:var(--muted);margin-top:6px">
                        📄 <span x-text="pendingExcelName"></span> — mengunggah…
                    </div>
                @endif

                @error('excelFile')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>

            <div class="btn-row">
                <button type="button" class="btn btn-primary" wire:click="pratinjauExcel" wire:loading.attr="disabled" wire:target="pratinjauExcel">
                    <span wire:loading.remove wire:target="pratinjauExcel">📤 Unggah &amp; Pratinjau (Langkah 1/2)</span>
                    <span wire:loading wire:target="pratinjauExcel"><i class="spin"></i> Memproses…</span>
                </button>
            </div>
        </div>
    @else
        <div class="card">
            <div class="sec">
                <span>Pratinjau Import (Langkah 2/2) — Tahun {{ $tahunImpor }}</span>
                <span class="badge b-approve">{{ $jumlahValid }} valid</span>
                @if ($jumlahError > 0)
                    <span class="badge b-tolak">{{ $jumlahError }} error</span>
                @endif
            </div>

            <div class="field" style="max-width:420px">
                <label class="fl-row" style="cursor:pointer;gap:8px">
                    <input type="checkbox" wire:model="batalkanSemuaBilaError">
                    <span>Batalkan semua bila ada error <span class="muted" style="font-weight:400">(tidak menyimpan satu pun baris jika masih ada baris error)</span></span>
                </label>
            </div>

            @if ($jumlahValid > 0)
                <div class="card-h" style="font-size:13px">✓ Baris Valid ({{ $jumlahValid }})</div>
                <div class="table-scroll" style="max-height:260px">
                    <table>
                        <thead>
                            <tr>
                                <th>Baris</th><th>Kode</th><th>Indikator</th><th>Tipe</th><th style="text-align:right">Target</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pratinjau as $baris)
                                @continue(! $baris['valid'])
                                <tr>
                                    <td class="muted">{{ $baris['baris'] }}</td>
                                    <td><b>{{ $baris['data']['kode'] }}</b></td>
                                    <td>{{ $baris['data']['master_iku']['indikator'] }}</td>
                                    <td class="muted">{{ $baris['data']['master_iku']['metode_capaian'] === 'rasio' ? '%' : 'Non %' }}</td>
                                    <td style="text-align:right" class="muted">
                                        @if ($baris['data']['master_iku']['metode_capaian'] === 'rasio')
                                            {{ $baris['data']['capaian_tahunan']['x_target'] }} / {{ $baris['data']['capaian_tahunan']['y_target'] }}
                                        @else
                                            {{ $baris['data']['capaian_tahunan']['alokasi_tw4'] }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($jumlahError > 0)
                <div class="card-h" style="font-size:13px;margin-top:16px">✕ Baris Error ({{ $jumlahError }})</div>
                <div class="table-scroll" style="max-height:260px">
                    <table>
                        <thead>
                            <tr><th>Baris</th><th>Alasan</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($pratinjau as $baris)
                                @continue($baris['valid'])
                                <tr>
                                    <td class="muted">{{ $baris['baris'] }}</td>
                                    <td style="color:var(--red)">{{ implode(' · ', $baris['errors']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="btn-row" style="margin-top:16px">
                <button type="button" class="btn btn-primary" wire:click="konfirmasiImpor" wire:loading.attr="disabled" wire:target="konfirmasiImpor"
                    @disabled($jumlahValid === 0 || ($batalkanSemuaBilaError && $jumlahError > 0))>
                    <span wire:loading.remove wire:target="konfirmasiImpor">✓ Konfirmasi Import</span>
                    <span wire:loading wire:target="konfirmasiImpor"><i class="spin"></i> Menyimpan…</span>
                </button>
                <button type="button" class="btn btn-ghost" wire:click="batalkanPratinjau" wire:loading.attr="disabled" wire:target="batalkanPratinjau">Batalkan</button>
            </div>
        </div>
    @endif

    @unless ($editingId)
        <div class="card">
            <div class="sec"><span>➕ Tambah IKU Manual</span></div>

            @include('livewire.partials._master-iku-form-fields')

            <div class="btn-row">
                <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">＋ Tambah IKU</span>
                    <span wire:loading wire:target="save"><i class="spin"></i> Menyimpan…</span>
                </button>
            </div>
        </div>
    @endunless

    <div class="card">
        <div class="sec">
            <span>Daftar Master IKU</span>
            <span class="badge b-verif">{{ $totalIndikator }} indikator</span>
        </div>

        <div class="table-scroll" style="max-height:520px" x-data="dataTable(10)">
            <table>
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Indikator</th>
                        <th>Sasaran</th>
                        <th>Satuan</th>
                        <th style="text-align:center">Metode</th>
                        <th>Periode</th>
                        <th>Penanggung Jawab (Tim)</th>
                        <th style="text-align:right">Tindakan</th>
                    </tr>
                </thead>
                <tbody x-ref="tbody">
                    @forelse ($ikuList as $iku)
                        <tr wire:key="iku-{{ $iku->id }}">
                            <td><b>{{ $iku->nomor_kode }}</b></td>
                            <td>{{ $iku->indikator }}</td>
                            <td class="muted">{{ $iku->sasaran ?? '—' }}</td>
                            <td class="muted">{{ $iku->satuan }}</td>
                            <td style="text-align:center">
                                @if ($iku->pakaiRasio())
                                    <span class="badge b-tunggu" style="white-space:nowrap">Rasio X÷Y</span>
                                @else
                                    <span class="badge b-draft" style="white-space:nowrap">Langsung</span>
                                @endif
                            </td>
                            <td class="muted">{{ $iku->pakaiTriwulanan() ? 'Triwulanan' : 'Tahunan' }}</td>
                            <td class="muted">{{ $iku->timList->isNotEmpty() ? $iku->timList->pluck('tim')->implode(', ') : '—' }}</td>
                            <td style="text-align:right;white-space:nowrap">
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="edit({{ $iku->id }})" wire:loading.attr="disabled" wire:target="edit({{ $iku->id }})">
                                    <span wire:loading.remove wire:target="edit({{ $iku->id }})">Ubah</span>
                                    <span wire:loading wire:target="edit({{ $iku->id }})"><i class="spin"></i></span>
                                </button>
                                <button type="button" class="btn btn-red btn-sm" wire:click="confirmDelete({{ $iku->id }})" wire:loading.attr="disabled" wire:target="confirmDelete({{ $iku->id }})">
                                    <span wire:loading.remove wire:target="confirmDelete({{ $iku->id }})">Hapus</span>
                                    <span wire:loading wire:target="confirmDelete({{ $iku->id }})"><i class="spin"></i></span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="color:var(--muted)">Belum ada data Master IKU. Unggah Excel atau tambah manual di atas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-table-pagination />
        </div>

        <div class="info" style="margin:14px 0 0">ℹ️ Bisa juga tambah/perbaiki satu IKU manual (Kode, Indikator, Tim — boleh lebih dari satu) tanpa Excel.</div>
    </div>

    @if ($pendingDeleteId && $alasanTidakBisaHapus)
        <x-confirm-modal :show="true" title="Tidak Bisa Dihapus" :danger="false"
            :message="'IKU '.$pendingDeleteKode.' tidak bisa dihapus karena masih ada '.implode(', ', $alasanTidakBisaHapus).' yang tertaut ke IKU ini. Hapus atau pindahkan dulu data terkait itu sebelum menghapus IKU-nya.'">
            <button type="button" class="btn btn-ghost" wire:click="cancelDelete">Tutup</button>
        </x-confirm-modal>
    @else
        <x-confirm-modal :show="$pendingDeleteId !== null" title="Hapus IKU?" :message="'Hapus IKU '.$pendingDeleteKode.'? Tindakan ini tidak dapat dibatalkan.'">
            <button type="button" class="btn btn-ghost" wire:click="cancelDelete" wire:loading.attr="disabled" wire:target="delete">Batal</button>
            <button type="button" class="btn btn-red" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                <span wire:loading.remove wire:target="delete">Hapus</span>
                <span wire:loading wire:target="delete"><i class="spin"></i> Menghapus…</span>
            </button>
        </x-confirm-modal>
    @endif

    @if ($editingId)
        <div class="modal-overlay" style="z-index:70">
            <div class="modal" style="max-width:640px;height:auto;max-height:92vh">
                <div class="modal-top">
                    <div class="mt-t">✎ Ubah IKU</div>
                    <button type="button" class="x" wire:click="cancelEdit" wire:loading.attr="disabled" wire:target="save" title="Tutup">✕</button>
                </div>
                <div style="padding:18px;overflow-y:auto">
                    @include('livewire.partials._master-iku-form-fields')

                    <div class="btn-row">
                        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">Simpan Perubahan</span>
                            <span wire:loading wire:target="save"><i class="spin"></i> Menyimpan…</span>
                        </button>
                        <button type="button" class="btn btn-ghost" wire:click="cancelEdit" wire:loading.attr="disabled" wire:target="save">Batal</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
