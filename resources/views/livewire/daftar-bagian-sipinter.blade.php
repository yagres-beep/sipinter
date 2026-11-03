<div>
    <div class="page-head">
        <div class="page-title">Pengelolaan Bagian</div>
        <div class="page-sub">Seluruh bagian SIPINTER di satu tempat: bagian bawaan dan Bagian Kustom, beserta hak akses, alur, dan batas pengaturannya.</div>
    </div>

    @if (session('status-bagian-bawaan'))
        <div class="badge b-approve" role="status" style="display:block;margin-bottom:14px;white-space:normal">{{ session('status-bagian-bawaan') }}</div>
    @endif

    <div class="info" style="font-size:12px">
        ℹ️ <b>Jenis A</b> (Bagian Kustom): struktur, hak aksi per peran, bukti, dan notula diatur di daftar Bagian Kustom di bawah.
        <b>Jenis B</b> (bawaan Isian Kegiatan): label menu, judul, dan petunjuk bisa diganti. Struktur, hak akses, alur, notula, dan folder Drive tetap dari kode.
        <b>Jenis C</b>: logika khusus, hanya ditampilkan.
    </div>

    <div class="card" data-daftar-seluruh-bagian>
        <div class="sec"><span>Daftar Seluruh Bagian</span><span style="margin-left:auto;font-size:12px;color:var(--muted);font-weight:500">{{ count($bawaan) }} bawaan · {{ $kustom->count() }} kustom</span></div>

        <div style="overflow-x:auto;max-width:100%">
            <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:640px">
                <thead>
                    <tr style="text-align:left;color:var(--muted)">
                        <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">Bagian</th>
                        <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">Jenis</th>
                        <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">Menu</th>
                        <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">Status</th>
                        <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)">Diubah</th>
                        <th scope="col" style="padding:6px;border-bottom:1px solid var(--line)"><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bawaan as $kode => $b)
                        @php
                            $t = $tampilan[$kode] ?? null;
                            $atur = $aturan[$kode] ?? null;
                        @endphp
                        <tr wire:key="bawaan-{{ $kode }}" data-bagian-bawaan="{{ $kode }}" style="vertical-align:top">
                            <td style="padding:6px;border-bottom:1px solid var(--line2)">
                                <b>{{ $b['n'] ? $b['n'].'. ' : '' }}{{ $t['label'] ?? $b['label'] }}</b>
                                <code style="font-size:11px;color:var(--muted)">{{ $kode }}</code>
                                @if ($t && $t['diubah'])<span class="badge b-verif" style="font-size:10px">label diganti</span>@endif
                            </td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2)">{{ $b['jenis'] }} — {{ $jenis[$b['jenis']] }}</td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2)">{{ $b['menu'] }}</td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2)"><span class="badge b-approve">Bawaan, selalu aktif</span></td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2);color:var(--muted)">
                                @if ($atur?->updated_at){{ $atur->updated_at->wita()->format('d/m/Y H:i') }}{{ $atur->pengubah ? ' · '.$atur->pengubah->nama : '' }}@else — @endif
                            </td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2);white-space:nowrap">
                                <button type="button" class="btn {{ $rinci === $kode ? 'btn-primary' : 'btn-ghost' }} btn-sm" wire:click="lihat('{{ $kode }}')">👁 Rincian</button>
                                <button type="button" class="btn {{ $sunting === $kode ? 'btn-primary' : 'btn-ghost' }} btn-sm" wire:click="buka('{{ $kode }}')">✎ Pengaturan</button>
                            </td>
                        </tr>

                        @if ($rinci === $kode)
                            <tr wire:key="rinci-{{ $kode }}"><td colspan="6" style="padding:10px;border-bottom:1px solid var(--line2);background:var(--surface-2)">
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:4px 16px;line-height:1.7">
                                    <div><span style="color:var(--muted)">Butuh IKU:</span> {{ $b['butuh_iku'] ? 'Ya' : 'Tidak' }}</div>
                                    <div><span style="color:var(--muted)">Periode:</span> {{ $b['periode'] }}</div>
                                    <div><span style="color:var(--muted)">Bukti dukung:</span> {{ $b['bukti'] }}</div>
                                    <div><span style="color:var(--muted)">Notula:</span> {{ $t['judul_notula'] ?? $b['notula'] }} @if (! empty($t['notula_periode']) && $t['notula_periode'] === 'kumulatif')<span class="badge b-tunggu">kumulatif</span>@endif</div>
                                    <div><span style="color:var(--muted)">Tabel:</span> <code style="font-size:11px">{{ $b['tabel'] }}</code></div>
                                </div>
                                <div style="font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.5px;margin:10px 0 4px">Hak akses (ditegakkan kode)</div>
                                <table style="border-collapse:collapse;font-size:12px">
                                    @foreach ($namaPeran as $p => $label)
                                        <tr><th scope="row" style="text-align:left;padding:2px 10px 2px 0;font-weight:600">{{ $label }}</th><td style="padding:2px 0">{{ $b['hak'][$p] ?? '—' }}</td></tr>
                                    @endforeach
                                </table>
                                <div style="margin-top:8px"><span style="color:var(--muted)">Alur:</span> {{ $b['alur'] }}</div>
                                <div style="margin-top:4px"><span style="color:var(--muted)">Batasan:</span> {{ $b['batasan'] }}</div>
                                @if (! empty($t['catatan']))
                                    <div style="margin-top:6px;padding:6px 10px;background:var(--surface-1);border-radius:6px;font-size:12px"><b>Catatan Tim SAKIP:</b> {{ $t['catatan'] }}</div>
                                @endif
                            </td></tr>
                        @endif

                        @if ($sunting === $kode)
                            <tr wire:key="sunting-{{ $kode }}"><td colspan="6" style="padding:12px;border-bottom:1px solid var(--line2);background:var(--bg)">
                                @error('formLabel')<div role="alert" style="color:var(--red);font-size:11.5px;margin-bottom:8px">{{ $message }}</div>@enderror
                                <div class="row2">
                                    <div class="field">
                                        <label for="label-menu-{{ $kode }}">Label menu lompat</label>
                                        <input id="label-menu-{{ $kode }}" type="text" class="inp filled" maxlength="60" wire:model="formLabel.label_menu" placeholder="Bawaan: {{ $b['label'] }}">
                                        @error('formLabel.label_menu')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="judul-{{ $kode }}">Judul bagian</label>
                                        <input id="judul-{{ $kode }}" type="text" class="inp filled" maxlength="150" wire:model="formLabel.judul" placeholder="Bawaan: {{ $b['judul'] }}">
                                        @error('formLabel.judul')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                                <div class="field">
                                    <label for="petunjuk-{{ $kode }}">Petunjuk pengisian <span style="color:var(--muted);font-weight:500">(opsional, tampil di bawah judul)</span></label>
                                    <textarea id="petunjuk-{{ $kode }}" class="inp filled" style="height:auto;display:block" rows="2" maxlength="1000" wire:model="formLabel.petunjuk"></textarea>
                                    @error('formLabel.petunjuk')<div style="color:var(--red);font-size:11.5px;margin-top:4px">{{ $message }}</div>@enderror
                                </div>
                                <div class="row2">
                                    <div class="field">
                                        <label for="notula-periode-{{ $kode }}">Cakupan periode di notula</label>
                                        <select id="notula-periode-{{ $kode }}" class="inp filled" wire:model="formLabel.notula_periode">
                                            <option value="triwulan">Hanya triwulan berjalan</option>
                                            <option value="kumulatif">Kumulatif (triwulan I s.d. triwulan notula)</option>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label for="judul-notula-{{ $kode }}">Judul di notula</label>
                                        <input id="judul-notula-{{ $kode }}" type="text" class="inp filled" maxlength="150" wire:model="formLabel.judul_notula" placeholder="Bawaan: {{ $b['notula'] ?? $b['judul'] }}">
                                    </div>
                                </div>
                                <div class="row2" style="align-items:center;margin-top:6px">
                                    <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer">
                                        <input type="checkbox" wire:model="formLabel.masuk_notula"> Tampilkan di notula
                                    </label>
                                    <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer">
                                        <input type="checkbox" wire:model="formLabel.aktif"> Bagian aktif
                                    </label>
                                </div>
                                <div class="field" style="margin-top:8px">
                                    <label for="catatan-{{ $kode }}">Catatan internal / instruksi</label>
                                    <textarea id="catatan-{{ $kode }}" class="inp filled" style="height:auto;display:block" rows="2" maxlength="2000" wire:model="formLabel.catatan" placeholder="Catatan tambahan untuk bagian ini"></textarea>
                                </div>
                                <div class="fhint">Pengaturan disimpan dinamis. Kosongkan isian untuk memakai bawaan kode sistem.</div>
                                <div class="btn-row">
                                    <button type="button" class="btn btn-primary btn-sm" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan">💾 Simpan Pengaturan</button>
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="kosongkan">Kembalikan ke bawaan</button>
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="tutup">Batal</button>
                                </div>
                            </td></tr>
                        @endif
                    @endforeach

                    @foreach ($kustom as $k)
                        <tr wire:key="kustom-{{ $k->id }}" data-bagian-kustom-daftar="{{ $k->id }}" style="vertical-align:top">
                            <td style="padding:6px;border-bottom:1px solid var(--line2)">🧩 <b>{{ $k->nama }}</b> <code style="font-size:11px;color:var(--muted)">{{ $k->kode }}</code>
                                <div style="font-size:11.5px;color:var(--muted)">Hak: {{ \App\Models\RiwayatKonfigurasiBagianKustom::ringkasHak($k->matriksAksi()) }}@if ($k->peran_pembaca) · pembaca: {{ $k->labelPeranPembaca() }}@endif</div>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2)">A — {{ $jenis['A'] }}</td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2)">{{ $k->labelPenempatan() }}</td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2)"><span class="badge {{ $k->aktif ? 'b-approve' : 'b-draft' }}">{{ $k->aktif ? 'Aktif' : 'Nonaktif' }}</span> <span style="color:var(--muted)">urutan {{ $k->urutan }}</span></td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2);color:var(--muted)">{{ $k->updated_at?->wita()->format('d/m/Y H:i') }}</td>
                            <td style="padding:6px;border-bottom:1px solid var(--line2);color:var(--muted);font-size:11.5px">Atur di daftar Bagian Kustom ↓</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($riwayatBawaan->isNotEmpty())
            <details style="margin-top:12px">
                <summary style="cursor:pointer;font-size:12.5px;font-weight:700">🕘 Riwayat perubahan label bagian bawaan</summary>
                @foreach ($riwayatBawaan as $r)
                    <div style="font-size:12px;padding:6px 0;border-top:1px solid var(--line2)">
                        <span style="color:var(--muted)">{{ $r->created_at?->wita()->format('d/m/Y H:i') }} · {{ $r->nama_pengguna ?? 'sistem' }}</span> — {{ $r->ringkasan }}
                    </div>
                @endforeach
            </details>
        @endif
    </div>
</div>
