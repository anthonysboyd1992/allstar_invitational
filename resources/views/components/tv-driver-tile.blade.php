@props([
    'position',
    'car' => null,
    'name' => null,
    'meta' => null,
    'kart' => null,
    'side' => 'stack',
])

@php
    $filled = filled($name);
    $isInside = $side === 'inside';
    $isOutside = $side === 'outside';
@endphp

<li
    {{ $attributes->class([
        'tv-driver-tile group relative flex min-h-[4.5rem] flex-col justify-center gap-1 rounded-lg px-2.5 py-2',
        'tv-driver-tile--inside' => $isInside,
        'tv-driver-tile--outside' => $isOutside,
        'tv-driver-tile--stack' => $side === 'stack',
        'tv-driver-tile--filled' => $filled,
        'tv-driver-tile--empty' => ! $filled,
    ]) }}
>
    <div class="flex items-start gap-2">
        <span class="tv-driver-tile__pos mt-0.5 font-mono text-[11px] font-bold tracking-wide">{{ $position }}</span>
        @if ($filled)
            <span class="tv-driver-tile__car flex min-w-[2.35rem] shrink-0 items-center justify-center rounded-md px-1.5 py-1 font-mono text-sm font-black">
                {{ filled($car) ? $car : '—' }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="tv-driver-tile__name text-[13px] leading-snug font-semibold tracking-wide break-words">{{ $name }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                    @if (filled($kart))
                        <span class="tv-driver-tile__kart" title="Assigned invitational car">Car {{ $kart }}</span>
                    @endif
                    @if (filled($meta))
                        <span class="tv-driver-tile__meta text-[10px] font-semibold tracking-[0.14em] uppercase">{{ $meta }}</span>
                    @endif
                </div>
            </div>
        @else
            <div class="flex min-w-0 flex-1 items-center">
                <p class="tv-driver-tile__empty-label text-[11px] font-semibold tracking-[0.18em] uppercase">Empty</p>
            </div>
        @endif
    </div>
</li>
