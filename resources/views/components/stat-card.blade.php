{{--
    Kartu KPI ringkasan (mockup S3/S7 dasbor) — dipakai berulang di halaman dasbor.
    Pemakaian:
      <x-stat-card icon="📊" icon-class="ico-blue" :value="$total" label="Total IKU dipantau" />
      <x-stat-card icon="✅" icon-class="ico-teal" :value="$verified" label="Sudah diverifikasi" tag="+3" />
--}}
@props(['icon', 'iconClass' => 'ico-blue', 'value', 'label', 'tag' => null])

<div {{ $attributes->merge(['class' => 'card stat']) }}>
    @if ($tag)
        <span class="tag tag-up">{{ $tag }}</span>
    @endif
    <div class="ico {{ $iconClass }}">{{ $icon }}</div>
    <span class="num">{{ $value }}</span>
    <span class="lbl">{{ $label }}</span>
</div>
