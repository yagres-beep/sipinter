<div>
    <div class="page-title">Master IKU</div>
    <div class="page-sub">Kelola Indikator Kinerja Utama sebagai sumber dropdown &amp; penamaan otomatis di seluruh modul.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @if (session('excelErrors'))
        <div class="card" style="border-color:#fca5a5;background:var(--red-soft);margin-bottom:16px">
            <div class="sec" style="color:var(--red);border-color:#fca5a5"><span>✕ Unggahan Ditolak</span></div>
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
                <div class="fhint" style="margin-top:2px">Kolom: Kode, Indikator, Tim, Penanggung Jawab · ada baris contoh &amp; petunjuk. Jangan hapus baris contoh (baris 2) &amp; petunjuk (baris 3).</div>
            </div>
        </div>
        <button type="button" class="btn btn-teal" wire:click="downloadTemplate">⬇ Unduh Template (.xlsx)</button>
    </div>

    <div class="card">
        <div class="sec"><span>Unggah Data IKU (Excel)</span></div>

        <div class="field">
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
                    <input type="file" wire:model="excelFile" accept=".xlsx" style="display:none">
                </label>
            @endif

            <div wire:loading wire:target="excelFile" style="font-size:11.5px;color:var(--muted);margin-top:6px">Mengunggah berkas…</div>

            @error('excelFile')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="uploadExcel" wire:loading.attr="disabled" wire:target="uploadExcel">
                <span wire:loading.remove wire:target="uploadExcel">Unggah &amp; Proses</span>
                <span wire:loading wire:target="uploadExcel">Memproses…</span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="sec"><span>{{ $editingId ? '✎ Ubah IKU' : '➕ Tambah IKU Manual' }}</span></div>

        <div class="row2">
            <div class="field">
                <label>Kode <span class="req">*</span></label>
                <input type="text" class="inp filled" wire:model="kode" placeholder="mis. IKU-1131">
                @error('kode')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="field">
                <label>Tim <span class="req">*</span></label>
                <input type="text" class="inp filled" wire:model="tim" placeholder="mis. Produksi Statistik">
                @error('tim')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="field">
            <label>Indikator <span class="req">*</span></label>
            <textarea class="inp filled" style="height:auto;display:block" rows="2" wire:model="indikator"
                placeholder="mis. Persentase publikasi tepat waktu"></textarea>
            @error('indikator')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="row2">
            <div class="field">
                <label>Penanggung Jawab <span class="req">*</span></label>
                <input type="text" class="inp filled" wire:model="penanggungJawab" placeholder="Nama petugas/pejabat">
                @error('penanggungJawab')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="field">
                <label>Sasaran</label>
                <input type="text" class="inp filled" wire:model="sasaran" placeholder="mis. Statistik Kesejahteraan Rakyat">
                <div class="fhint">Untuk mengelompokkan tabel "Kesiapan per Sasaran" di halaman Kompilasi Notula. Boleh dikosongkan.</div>
                @error('sasaran')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="save">{{ $editingId ? 'Simpan Perubahan' : '＋ Tambah IKU' }}</button>
            @if ($editingId)
                <button type="button" class="btn btn-ghost" wire:click="cancelEdit">Batal</button>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="sec">
            <span>Daftar Master IKU</span>
            <span class="badge b-verif">{{ $totalIndikator }} indikator</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Indikator</th>
                    <th>Tim</th>
                    <th>Sasaran</th>
                    <th>Penanggung Jawab</th>
                    <th style="text-align:right">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ikuList as $iku)
                    <tr wire:key="iku-{{ $iku->id }}">
                        <td><b>{{ $iku->kode }}</b></td>
                        <td>{{ $iku->indikator }}</td>
                        <td class="muted">{{ $iku->tim }}</td>
                        <td class="muted">{{ $iku->sasaran ?? '—' }}</td>
                        <td class="muted">{{ $iku->penanggung_jawab }}</td>
                        <td style="text-align:right;white-space:nowrap">
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="edit({{ $iku->id }})">Ubah</button>
                            <button type="button" class="btn btn-red btn-sm" wire:click="delete({{ $iku->id }})"
                                onclick="return confirm('Hapus IKU {{ $iku->kode }}? Tindakan ini tidak dapat dibatalkan.')">Hapus</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="color:var(--muted)">Belum ada data Master IKU. Unggah Excel atau tambah manual di atas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="info" style="margin:14px 0 0">ℹ️ Bisa juga tambah/perbaiki satu IKU manual (Kode, Indikator, Tim, Penanggung Jawab) tanpa Excel.</div>
    </div>
</div>
