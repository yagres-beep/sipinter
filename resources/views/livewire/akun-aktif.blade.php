<div>
    <div class="info">ℹ️ Peran &amp; keanggotaan tim satu akun diubah di baris yang sama. Kolom Tim hanya berlaku untuk peran Ketua Tim — chip abu-abu "via tim" di tab Penugasan IKU muncul otomatis dari sini. Satu Ketua Tim boleh merangkap lebih dari satu tim.</div>

    <div class="table-scroll" style="max-height:520px" x-data="dataTable(10)">
        <table style="table-layout:fixed">
            <thead>
                <tr>
                    <th style="width:16%">Nama</th>
                    <th style="width:13%">Username</th>
                    <th style="width:11%">Status</th>
                    <th style="width:15%">Peran</th>
                    <th style="width:23%">Tim</th>
                    <th style="width:22%;text-align:right">Aksi</th>
                </tr>
            </thead>
            <tbody x-ref="tbody">
                @forelse ($userList as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td style="word-break:break-word"><b>{{ $user->nama }}</b></td>
                        <td class="muted" style="word-break:break-word">{{ $user->username }}</td>
                        <td><x-badge-status :status="$user->status_verifikasi" /></td>
                        <td>
                            <select class="inp filled" style="width:100%" wire:model="roleBaru.{{ $user->id }}">
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}">{{ $role->nama }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            @if ($user->role?->nama === 'Ketua Tim')
                                <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                                    @forelse ($user->timList as $anggota)
                                        <span class="chip chip-tim" wire:key="tim-{{ $anggota->id }}">
                                            {{ $anggota->tim }}
                                            <span class="chip-x" wire:click="hapusTim({{ $anggota->id }})" wire:loading.class="btn-busy" wire:target="hapusTim({{ $anggota->id }})">✕</span>
                                        </span>
                                    @empty
                                        <span class="muted" style="font-size:11.5px">Belum ada tim.</span>
                                    @endforelse

                                    <input type="text" list="daftar-tim" class="inp filled"
                                        style="width:auto;min-width:120px;display:inline-block;font-size:11.5px;padding:6px 9px"
                                        wire:model="timBaru.{{ $user->id }}" wire:keydown.enter="tambahTim({{ $user->id }})"
                                        wire:loading.attr="disabled" wire:target="tambahTim({{ $user->id }})"
                                        placeholder="+ tim…">
                                    <button type="button" class="btn btn-ghost btn-sm" style="padding:5px 9px" wire:click="tambahTim({{ $user->id }})"
                                        wire:loading.attr="disabled" wire:target="tambahTim({{ $user->id }})">
                                        <span wire:loading.remove wire:target="tambahTim({{ $user->id }})">＋</span>
                                        <span wire:loading wire:target="tambahTim({{ $user->id }})"><i class="spin"></i></span>
                                    </button>
                                </div>
                            @else
                                <span class="muted" style="font-size:11.5px">— (khusus Ketua Tim)</span>
                            @endif
                        </td>
                        <td style="text-align:right">
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="updateRole({{ $user->id }})"
                                wire:loading.attr="disabled" wire:target="updateRole({{ $user->id }})">
                                <span wire:loading.remove wire:target="updateRole({{ $user->id }})">Simpan</span>
                                <span wire:loading wire:target="updateRole({{ $user->id }})"><i class="spin"></i></span>
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="confirmEdit({{ $user->id }})">
                                ✎ Profil
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="confirmReset({{ $user->id }})">
                                🔑 Reset
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="color:var(--muted)">Belum ada pengguna lain.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-table-pagination />
    </div>

    <datalist id="daftar-tim">
        @foreach ($daftarTim as $tim)
            <option value="{{ $tim }}"></option>
        @endforeach
    </datalist>

    @if ($pendingResetId)
        <div class="modal-overlay" style="z-index:70">
            <div class="modal" style="max-width:420px;height:auto" x-data="{ show: false }">
                <div class="modal-top">
                    <div class="mt-t">🔑 Reset Kata Sandi</div>
                    <button type="button" class="x" wire:click="cancelReset" wire:loading.attr="disabled" wire:target="resetPassword" title="Tutup">✕</button>
                </div>
                <div style="padding:18px">
                    <p style="font-size:13px;color:var(--ink);line-height:1.6;margin:0 0 14px">
                        Tentukan kata sandi baru untuk <b>{{ $userList->firstWhere('id', $pendingResetId)?->nama }}</b>, lalu sampaikan langsung ke pengguna dan minta segera menggantinya di halaman Profil.
                    </p>
                    <div class="field" style="margin-bottom:4px">
                        <label>Kata Sandi Baru <span class="req">*</span></label>
                        <div class="pw-wrap">
                            <input class="inp filled" :type="show ? 'text' : 'password'" wire:model="passwordBaru"
                                wire:keydown.enter="resetPassword" placeholder="Minimal 8 karakter" autofocus>
                            <button type="button" class="eye" @click="show = !show" tabindex="-1">
                                <span x-text="show ? '🙈' : '👁'"></span>
                            </button>
                        </div>
                        @error('passwordBaru')
                            <div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="btn-row" style="justify-content:flex-end">
                        <button type="button" class="btn btn-ghost" wire:click="cancelReset" wire:loading.attr="disabled" wire:target="resetPassword">Batal</button>
                        <button type="button" class="btn btn-primary" wire:click="resetPassword" wire:loading.attr="disabled" wire:target="resetPassword">
                            <span wire:loading.remove wire:target="resetPassword">Reset Kata Sandi</span>
                            <span wire:loading wire:target="resetPassword"><i class="spin"></i> Menyimpan…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($pendingEditId)
        <div class="modal-overlay" style="z-index:70">
            <div class="modal" style="max-width:420px;height:auto">
                <div class="modal-top">
                    <div class="mt-t">✎ Ubah Email &amp; Nomor Telepon</div>
                    <button type="button" class="x" wire:click="cancelEdit" wire:loading.attr="disabled" wire:target="simpanProfil" title="Tutup">✕</button>
                </div>
                <div style="padding:18px">
                    <p style="font-size:13px;color:var(--ink);line-height:1.6;margin:0 0 14px">
                        Ubah email &amp; nomor telepon <b>{{ $userList->firstWhere('id', $pendingEditId)?->nama }}</b> — email dipakai untuk lupa kata sandi &amp; pengingat otomatis.
                    </p>
                    <div class="field">
                        <label>Email <span class="req">*</span></label>
                        <input class="inp filled" type="email" wire:model="emailBaru" placeholder="nama@bps.go.id" autofocus>
                        @error('emailBaru')
                            <div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="field" style="margin-bottom:4px">
                        <label>Nomor Telepon <span class="req">*</span></label>
                        <input class="inp filled" type="text" wire:model="nomorTeleponBaru" wire:keydown.enter="simpanProfil" placeholder="08xxxxxxxxxx">
                        @error('nomorTeleponBaru')
                            <div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="btn-row" style="justify-content:flex-end">
                        <button type="button" class="btn btn-ghost" wire:click="cancelEdit" wire:loading.attr="disabled" wire:target="simpanProfil">Batal</button>
                        <button type="button" class="btn btn-primary" wire:click="simpanProfil" wire:loading.attr="disabled" wire:target="simpanProfil">
                            <span wire:loading.remove wire:target="simpanProfil">Simpan</span>
                            <span wire:loading wire:target="simpanProfil"><i class="spin"></i> Menyimpan…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
