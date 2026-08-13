<div>
    <div class="page-title">Akun &amp; Storage</div>
    <div class="page-sub">Kelola akun Gmail institusi tempat seluruh bukti dukung tersimpan di Google Drive.</div>

    @if (session('status'))
        <div class="badge b-approve" style="display:block;margin-bottom:14px">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="info red" style="margin-bottom:14px">⚠️ {{ session('error') }}</div>
    @endif

    {{-- RF-10c: peringatan saat storage aktif mendekati penuh --}}
    @if ($akunAktif && $akunAktif->mendekatiPenuh())
        <div class="card" style="border-color:#fca5a5;background:var(--red-soft);margin-bottom:16px">
            <div class="card-h" style="color:var(--red)">⚠ Kuota Storage Aktif Hampir Penuh</div>
            <p style="color:var(--red);font-size:13px;margin:0">
                Akun <b>{{ $akunAktif->email_gmail_institusi }}</b> sudah terpakai
                {{ $akunAktif->persentaseTerpakai() }}% dari {{ (float) $akunAktif->kuota_total }} GB.
                Siapkan akun Gmail institusi berikutnya dan jadikan storage aktif sebelum kuota benar-benar habis.
            </p>
        </div>
    @endif

    <div class="card">
        <div class="card-h">➕ Tambah Akun Gmail Institusi</div>

        <div class="grid2">
            <div class="field">
                <label>Email Gmail Institusi <span class="req">*</span></label>
                <input type="email" class="inp filled" wire:model="email" placeholder="mis. sipinter.bps.buton.utara@gmail.com">
                @error('email')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
            <div class="field">
                <label>Kuota Total (GB) <span class="req">*</span></label>
                <input type="number" step="0.01" class="inp filled" wire:model="kuotaTotal">
                @error('kuotaTotal')
                    <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="field">
            <label>ID Folder Induk Drive <span class="fhint" style="margin:0">(opsional)</span></label>
            <input type="text" class="inp filled" wire:model="driveFolderId" placeholder="Kosongkan saja — akan dibuat otomatis saat akun dihubungkan ke Google Drive">
            <div style="color:var(--muted);font-size:11.5px;margin-top:5px">
                Tidak perlu diisi manual lagi — klik <b>🔗 Hubungkan ke Google Drive</b> pada akun ini setelah
                ditambahkan, folder akan dibuat otomatis di akun tersebut. Isian ini hanya untuk kasus lama:
                folder yang sudah dibagikan (share) ke Service Account (Google Workspace/Shared Drive saja).
            </div>
            @error('driveFolderId')
                <div style="color:var(--red);font-size:11.5px;margin-top:5px">{{ $message }}</div>
            @enderror
        </div>

        <div class="btn-row">
            <button type="button" class="btn btn-primary" wire:click="tambah">＋ Tambah Akun</button>
        </div>
    </div>

    <div class="card">
        <div class="card-h">☁️ Daftar Akun Storage</div>
        <table>
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Google Drive</th>
                    <th style="width:220px">Kuota Terpakai</th>
                    <th style="text-align:right">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($akunList as $akun)
                    <tr wire:key="akun-{{ $akun->id }}">
                        <td>{{ $akun->email_gmail_institusi }}</td>
                        <td>
                            <span class="badge {{ $akun->status === \App\Models\StorageAccount::STATUS_AKTIF ? 'b-approve' : 'b-draft' }}">
                                {{ $akun->status === \App\Models\StorageAccount::STATUS_AKTIF ? 'Aktif' : 'Tidak Aktif' }}
                            </span>
                        </td>
                        <td>
                            @if ($akun->googleTerhubung())
                                <span class="badge b-approve">✅ Terhubung</span>
                                <a href="{{ route('storage-accounts.google-redirect', $akun) }}" style="display:block;font-size:11px;color:var(--muted);margin-top:4px">Sambungkan ulang</a>
                            @else
                                <a href="{{ route('storage-accounts.google-redirect', $akun) }}" class="btn btn-ghost btn-sm">🔗 Hubungkan ke Google Drive</a>
                            @endif
                        </td>
                        <td>
                            <div class="quota-bar">
                                <div class="quota-fill {{ $akun->mendekatiPenuh() ? 'danger' : '' }}" style="width: {{ $akun->persentaseTerpakai() }}%"></div>
                            </div>
                            <div style="font-size:11px;color:var(--muted);margin-top:4px">
                                {{ (float) $akun->kuota_terpakai }} GB / {{ (float) $akun->kuota_total }} GB
                                ({{ $akun->persentaseTerpakai() }}%)
                            </div>
                        </td>
                        <td style="text-align:right">
                            @if ($akun->status !== \App\Models\StorageAccount::STATUS_AKTIF)
                                <button type="button" class="btn btn-teal btn-sm" wire:click="jadikanAktif({{ $akun->id }})">Jadikan Aktif</button>
                            @else
                                <span style="font-size:11.5px;color:var(--muted)">Sedang dipakai</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="color:var(--muted)">Belum ada akun storage. Tambahkan akun pertama di atas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
