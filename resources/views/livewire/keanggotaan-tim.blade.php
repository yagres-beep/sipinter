<div>
    <div class="info">ℹ️ Chip abu-abu "via tim" muncul otomatis di tab Penugasan IKU berdasarkan keanggotaan tim di sini. Satu Ketua Tim boleh merangkap lebih dari satu tim.</div>

    @forelse ($ketuaTimList as $ketua)
        <div class="assign-card" wire:key="ketua-{{ $ketua->id }}">
            <div class="ac-person">
                <div class="ac-av">{{ strtoupper(substr($ketua->nama, 0, 1)) }}{{ strtoupper(substr(strrchr($ketua->nama, ' ') ?: '', 1, 1)) }}</div>
                <div>
                    <div class="ac-name">{{ $ketua->nama }}</div>
                    <div class="ac-sub">{{ $ketua->username }}</div>
                </div>
            </div>
            <div class="ac-chips">
                @forelse ($ketua->timList as $anggota)
                    <span class="chip chip-tim" wire:key="tim-{{ $anggota->id }}">
                        {{ $anggota->tim }}
                        <span style="cursor:pointer;margin-left:4px" wire:click="hapusTim({{ $anggota->id }})">✕</span>
                    </span>
                @empty
                    <span class="muted" style="font-size:12px">Belum tergabung tim mana pun.</span>
                @endforelse

                <input type="text" list="daftar-tim" class="inp filled" style="width:auto;min-width:160px;display:inline-block"
                    wire:model="timBaru.{{ $ketua->id }}" wire:keydown.enter="tambahTim({{ $ketua->id }})"
                    placeholder="Tambah tim…">
                <button type="button" class="btn btn-ghost btn-sm" wire:click="tambahTim({{ $ketua->id }})">＋ Tambah</button>
            </div>
        </div>
    @empty
        <p style="color:var(--muted);font-size:13px">Belum ada akun Ketua Tim yang terverifikasi.</p>
    @endforelse

    <datalist id="daftar-tim">
        @foreach ($daftarTim as $tim)
            <option value="{{ $tim }}"></option>
        @endforeach
    </datalist>
</div>
