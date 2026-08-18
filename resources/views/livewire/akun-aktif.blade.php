<div>
    <div class="info">ℹ️ Peran &amp; keanggotaan tim satu akun diubah di baris yang sama. Kolom Tim hanya berlaku untuk peran Ketua Tim — chip abu-abu "via tim" di tab Penugasan IKU muncul otomatis dari sini. Satu Ketua Tim boleh merangkap lebih dari satu tim.</div>

    <div class="table-scroll" style="max-height:520px" x-data="dataTable(10)">
        <table>
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Username</th>
                    <th>Status</th>
                    <th style="width:170px">Peran</th>
                    <th>Tim</th>
                </tr>
            </thead>
            <tbody x-ref="tbody">
                @forelse ($userList as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td><b>{{ $user->nama }}</b></td>
                        <td class="muted">{{ $user->username }}</td>
                        <td><x-badge-status :status="$user->status_verifikasi" /></td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                <select class="inp filled" style="width:auto" wire:model="roleBaru.{{ $user->id }}">
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->id }}">{{ $role->nama }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="updateRole({{ $user->id }})">Simpan</button>
                            </div>
                        </td>
                        <td>
                            @if ($user->role?->nama === 'Ketua Tim')
                                <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                                    @forelse ($user->timList as $anggota)
                                        <span class="chip chip-tim" wire:key="tim-{{ $anggota->id }}">
                                            {{ $anggota->tim }}
                                            <span class="chip-x" wire:click="hapusTim({{ $anggota->id }})">✕</span>
                                        </span>
                                    @empty
                                        <span class="muted" style="font-size:11.5px">Belum ada tim.</span>
                                    @endforelse

                                    <input type="text" list="daftar-tim" class="inp filled"
                                        style="width:auto;min-width:120px;display:inline-block;font-size:11.5px;padding:6px 9px"
                                        wire:model="timBaru.{{ $user->id }}" wire:keydown.enter="tambahTim({{ $user->id }})"
                                        placeholder="+ tim…">
                                    <button type="button" class="btn btn-ghost btn-sm" style="padding:5px 9px" wire:click="tambahTim({{ $user->id }})">＋</button>
                                </div>
                            @else
                                <span class="muted" style="font-size:11.5px">— (khusus Ketua Tim)</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="color:var(--muted)">Belum ada pengguna lain.</td>
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
</div>
