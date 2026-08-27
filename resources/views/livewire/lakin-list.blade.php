<div>
    <div class="page-head">
        <div class="page-title">Rekap Kinerja Tahunan</div>
        <div class="page-sub">Rekap kinerja tahunan — dibentuk Tim SAKIP dari data capaian yang sudah terverifikasi.</div>
    </div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @if (auth()->user()->namaRole() === 'Tim SAKIP')
        <div class="card">
            <div class="sec"><span>Bentuk Rekap Baru</span></div>
            <div class="info">ℹ️ Buat rekap kinerja tahunan untuk tahun ini, lalu pilih sendiri IKU mana saja yang mau dimasukkan lewat checklist di halaman berikutnya — tidak otomatis semua. Bila rekap untuk tahun itu sudah pernah dibuat, Anda akan diarahkan ke dokumen yang sama.</div>
            <div class="field" style="max-width:200px">
                <label>Tahun</label>
                <input type="number" class="inp filled" wire:model="tahunBaru">
                @error('tahunBaru')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="btn-row">
                <button type="button" class="btn btn-primary" wire:click="bentukLakin" wire:loading.attr="disabled" wire:target="bentukLakin">
                    <span wire:loading.remove wire:target="bentukLakin">📈 Bentuk Rekap</span>
                    <span wire:loading wire:target="bentukLakin"><i class="spin"></i> Membentuk…</span>
                </button>
            </div>
        </div>
    @endif

    <div class="card" style="margin-top:16px">
        <div class="sec"><span>Daftar Rekap Kinerja Tahunan</span></div>
        <div class="table-scroll" style="max-height:520px" x-data="dataTable(10)">
            <table>
                <thead>
                    <tr>
                        <th>Tahun</th>
                        <th>Jumlah Indikator</th>
                        <th style="text-align:right">Terakhir Diperbarui</th>
                    </tr>
                </thead>
                <tbody x-ref="tbody">
                    @forelse ($daftar as $lakin)
                        <tr wire:key="lakin-{{ $lakin->id }}" class="clickable" onclick="window.location='{{ route('lakin.show', $lakin) }}'">
                            <td><b>{{ $lakin->tahun }}</b></td>
                            <td>{{ $lakin->baris_count }} indikator</td>
                            <td style="text-align:right" class="muted">{{ $lakin->updated_at->wita()->translatedFormat('d F Y, H:i') }} WITA</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="color:var(--muted)">Belum ada rekap yang dibentuk.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-table-pagination />
        </div>
    </div>
</div>
