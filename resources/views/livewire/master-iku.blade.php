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

    @if (session('excelErrors'))
        <div class="card card-red" style="margin-bottom:16px">
            <div class="sec"><span>✕ Unggahan Ditolak</span></div>
            <ul style="margin:0;padding-left:18px;color:var(--red);font-size:13px;line-height:1.8">
                @foreach (session('excelErrors') as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:12px">
            <div class="ico ico-teal" style="width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px">📥</div>
            <div>
                <div style="font-weight:700;color:var(--ink);font-size:13px">Template Data IKU (.xlsx)</div>
                <div class="fhint" style="margin-top:2px">Kolom: Kode, Indikator, Tim, Penanggung Jawab, Sasaran · ada baris contoh, petunjuk, &amp; sheet Daftar Nama untuk disalin ke kolom Penanggung Jawab. Jangan hapus baris contoh (baris 2) &amp; petunjuk (baris 3).</div>
            </div>
        </div>
        <button type="button" class="btn btn-teal" wire:click="downloadTemplate">⬇ Unduh Template (.xlsx)</button>
    </div>

    <div class="card">
        <div class="sec"><span>Unggah Data IKU (Excel)</span></div>

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
            <button type="button" class="btn btn-primary" wire:click="uploadExcel" wire:loading.attr="disabled" wire:target="uploadExcel">
                <span wire:loading.remove wire:target="uploadExcel">Unggah &amp; Proses</span>
                <span wire:loading wire:target="uploadExcel"><i class="spin"></i> Memproses…</span>
            </button>
        </div>
    </div>

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
                        <th>Tim</th>
                        <th>Sasaran</th>
                        <th>Satuan</th>
                        <th>Metode</th>
                        <th>Penanggung Jawab</th>
                        <th style="text-align:right">Tindakan</th>
                    </tr>
                </thead>
                <tbody x-ref="tbody">
                    @forelse ($ikuList as $iku)
                        <tr wire:key="iku-{{ $iku->id }}">
                            <td><b>{{ $iku->nomor_kode }}</b></td>
                            <td>{{ $iku->indikator }}</td>
                            <td class="muted">{{ $iku->tim }}</td>
                            <td class="muted">{{ $iku->sasaran ?? '—' }}</td>
                            <td class="muted">{{ $iku->satuan }}</td>
                            <td>
                                @if ($iku->pakaiRasio())
                                    <span class="badge b-tunggu">Rasio X÷Y</span>
                                @else
                                    <span class="muted">Langsung</span>
                                @endif
                            </td>
                            <td class="muted">{{ $iku->penanggung_jawab }}</td>
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

        <div class="info" style="margin:14px 0 0">ℹ️ Bisa juga tambah/perbaiki satu IKU manual (Kode, Indikator, Tim, Penanggung Jawab) tanpa Excel.</div>
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
