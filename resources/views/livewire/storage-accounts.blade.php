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
        <div class="card card-red" style="margin-bottom:16px">
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
            <input type="text" class="inp filled" wire:model="driveFolderId" placeholder="Kosongkan saja, atau tempel URL/ID folder Drive yang sudah ada">
            <div style="color:var(--muted);font-size:11.5px;margin-top:5px">
                Kosongkan bila ingin folder dibuat otomatis saat akun ini nanti diklik <b>🔗 Hubungkan ke Google
                Drive</b>. Atau tempel URL folder Drive yang SUDAH ADA (mis. hasil "Salin tautan" di Drive) supaya
                akun ini memakai folder itu — bisa juga diubah belakangan lewat "✏️ Ubah folder" di tabel bawah.
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
                        <td>
                            {{ $akun->email_gmail_institusi }}
                            @if ($akun->is_master)
                                <span class="badge b-draft" title="Akun lain otomatis dibagikan (share) ke folder milik akun ini saat terhubung">🔑 Folder Master</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $akun->status === \App\Models\StorageAccount::STATUS_AKTIF ? 'b-approve' : 'b-draft' }}">
                                {{ $akun->status === \App\Models\StorageAccount::STATUS_AKTIF ? 'Aktif' : 'Tidak Aktif' }}
                            </span>
                        </td>
                        <td>
                            @if ($akun->googleTerhubung())
                                <span class="badge b-approve">✅ Terhubung</span>
                                <a href="{{ route('storage-accounts.google-redirect', $akun) }}" style="display:block;font-size:11px;color:var(--muted);margin-top:4px">Sambungkan ulang</a>
                            @elseif ($akun->googlePerluHubungUlang())
                                <span class="badge b-tolak">⚠️ Sesi Drive rusak</span>
                                <div style="font-size:11px;color:var(--muted);margin-top:4px">
                                    Token tersimpan tidak bisa dibaca lagi (APP_KEY berubah). Unggahan ke Drive sedang MATI —
                                    berkas baru hanya tersimpan lokal sampai akun ini dihubungkan ulang.
                                </div>
                                <a href="{{ route('storage-accounts.google-redirect', $akun) }}" class="btn btn-ghost btn-sm" style="margin-top:6px">🔗 Hubungkan ulang sekarang</a>
                            @else
                                <a href="{{ route('storage-accounts.google-redirect', $akun) }}" class="btn btn-ghost btn-sm">🔗 Hubungkan ke Google Drive</a>
                            @endif

                            <div style="margin-top:8px">
                                @if ($editFolderId === $akun->id)
                                    <input type="text" class="inp filled" wire:model="editFolderValue"
                                        placeholder="Tempel URL folder Drive, mis. https://drive.google.com/drive/folders/sakip7409"
                                        style="font-size:11.5px;padding:6px 8px">
                                    @error('editFolderValue')
                                        <div style="color:var(--red);font-size:11px;margin-top:3px">{{ $message }}</div>
                                    @enderror
                                    <div style="display:flex;gap:6px;margin-top:6px">
                                        <button type="button" class="btn btn-teal btn-sm" wire:click="simpanFolder">Simpan</button>
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="batalUbahFolder">Batal</button>
                                    </div>
                                @else
                                    <div style="font-size:11px;color:var(--muted)">
                                        Folder:
                                        @if ($akun->drive_folder_id)
                                            <a href="https://drive.google.com/drive/folders/{{ $akun->drive_folder_id }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($akun->drive_folder_id, 18) }}</a>
                                        @else
                                            <span>belum diatur</span>
                                        @endif
                                        <button type="button" class="btn btn-ghost btn-sm" style="padding:1px 7px;margin-left:4px" wire:click="mulaiUbahFolder({{ $akun->id }})">✏️ Ubah</button>
                                    </div>
                                @endif
                            </div>
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
                            @if (! $akun->is_master)
                                <button type="button" class="btn btn-ghost btn-sm" style="margin-left:6px"
                                    wire:click="jadikanMaster({{ $akun->id }})"
                                    title="Akun lain yang terhubung ke Google Drive setelah ini akan otomatis menulis ke folder akun ini">
                                    🔑 Jadikan Master
                                </button>
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
