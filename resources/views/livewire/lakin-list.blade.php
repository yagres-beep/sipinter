<div>
    <div class="page-title">LAKIN</div>
    <div class="page-sub">Laporan Kinerja tahunan — dibentuk Tim SAKIP dari data capaian yang sudah terverifikasi.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif

    @if (auth()->user()->namaRole() === 'Tim SAKIP')
        <div class="card">
            <div class="sec"><span>Bentuk LAKIN</span></div>
            <div class="info">ℹ️ Sistem akan mengambil data capaian yang sudah terverifikasi sepanjang tahun yang dipilih, satu baris per IKU. Bila LAKIN untuk tahun itu sudah pernah dibentuk, angka baris otomatis akan diperbarui (baris custom yang ditambah manual tidak berubah).</div>
            <div class="field" style="max-width:200px">
                <label>Tahun</label>
                <input type="number" class="inp filled" wire:model="tahunBaru">
                @error('tahunBaru')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="btn-row">
                <button type="button" class="btn btn-primary" wire:click="bentukLakin">📈 Bentuk LAKIN</button>
            </div>
        </div>
    @endif

    <div class="card" style="margin-top:16px">
        <div class="sec"><span>Daftar LAKIN</span></div>
        <table>
            <thead>
                <tr>
                    <th>Tahun</th>
                    <th>Jumlah Indikator</th>
                    <th style="text-align:right">Terakhir Diperbarui</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($daftar as $lakin)
                    <tr class="clickable" onclick="window.location='{{ route('lakin.show', $lakin) }}'">
                        <td><b>{{ $lakin->tahun }}</b></td>
                        <td>{{ $lakin->baris_count }} indikator</td>
                        <td style="text-align:right" class="muted">{{ $lakin->updated_at->translatedFormat('d F Y, H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" style="color:var(--muted)">Belum ada LAKIN yang dibentuk.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
