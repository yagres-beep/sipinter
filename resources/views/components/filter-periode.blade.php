{{--
    Filter periode dipakai ulang di halaman daftar (RF-48): Tahun + Triwulan selalu
    tampil; Bulan HANYA tampil bila mode="lengkap" (opsi pertamanya "semua bulan
    triwulan ini" — memilihnya berarti filter TRIWULANAN, memilih bulan tertentu
    berarti filter BULANAN — satu kontrol untuk dua jenis filter RF-48 sekaligus).

    Komponen ini mengandalkan host (komponen Livewire pemanggil) punya properti
    publik $tahun, $triwulan, dan (bila mode="lengkap") $bulan — semua komponen
    Livewire di SIPINTER sudah konsisten memakai nama-nama ini.
--}}
@props([
    'mode' => 'triwulanan',
    'tahun',
    'triwulan',
    'bulan' => null,
    'labelBulanKosong' => 'Semua Bulan (Triwulan Penuh)',
])

@php
    $namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $bulanDalamTriwulan = [
        ($triwulan - 1) * 3 + 1,
        ($triwulan - 1) * 3 + 2,
        ($triwulan - 1) * 3 + 3,
    ];

    $labelPeriodeAktif = 'Triwulan '.(['I', 'II', 'III', 'IV'][$triwulan - 1] ?? $triwulan).' '.$tahun;

    if ($mode === 'lengkap' && filled($bulan)) {
        $labelPeriodeAktif = $namaBulan[$bulan - 1].' '.$tahun.' · Triwulan '.(['I', 'II', 'III', 'IV'][$triwulan - 1] ?? $triwulan);
    }
@endphp

<div class="period-banner" wire:loading.class="periode-loading" wire:target="tahun,triwulan,bulan">
    <span class="pb-ico">📅</span>
    <div>
        <div class="pb-lbl">Periode Aktif</div>
        <div class="pb-val">
            <select wire:model.live="tahun" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                @foreach (range(now()->year - 1, now()->year + 1) as $tahunOpsi)
                    <option value="{{ $tahunOpsi }}">{{ $tahunOpsi }}</option>
                @endforeach
            </select>

            <select wire:model.live="triwulan" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                @foreach (['I', 'II', 'III', 'IV'] as $idx => $label)
                    <option value="{{ $idx + 1 }}">Triwulan {{ $label }}</option>
                @endforeach
            </select>

            @if ($mode === 'lengkap')
                <select wire:model.live="bulan" wire:key="filter-bulan-tw-{{ $triwulan }}" style="border:none;background:transparent;font-weight:700;color:var(--ink);font-size:14px">
                    <option value="">{{ $labelBulanKosong }}</option>
                    @foreach ($bulanDalamTriwulan as $bulanOpsi)
                        <option value="{{ $bulanOpsi }}">{{ $namaBulan[$bulanOpsi - 1] }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>
    <span class="pb-tag" wire:loading.remove wire:target="tahun,triwulan,bulan">{{ $labelPeriodeAktif }}</span>
    <span class="pb-tag" wire:loading wire:target="tahun,triwulan,bulan">Memuat…</span>
</div>
