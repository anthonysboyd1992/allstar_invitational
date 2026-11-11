@props([
    'active' => null,
])

@php
    $active = $active ?? \App\Models\Setting::tvBoardMode();
@endphp

<div {{ $attributes->class('rounded-lg bg-zinc-900/80 p-3 ring-1 ring-white/10') }}>
    <p class="text-[10px] font-semibold tracking-[0.22em] text-zinc-500 uppercase">Shared TV screen</p>
    <p class="mt-1 text-sm font-semibold text-zinc-200">
        On air:
        <span class="text-amber-400">{{ \App\Models\Setting::tvBoardModeLabel($active) }}</span>
    </p>
    <div class="mt-3 flex flex-wrap gap-2">
        <button
            type="button"
            wire:click="showPillDrawOnTv"
            @class([
                'rounded-md px-3 py-1.5 text-xs font-bold uppercase tracking-wide',
                'bg-amber-400 text-slate-950' => $active === \App\Models\Setting::TvBoardPillDraw,
                'bg-zinc-800 text-zinc-300 ring-1 ring-zinc-700 hover:bg-zinc-700' => $active !== \App\Models\Setting::TvBoardPillDraw,
            ])
        >Pill Draw</button>
        <button
            type="button"
            wire:click="showLineupBoardOnTv"
            @class([
                'rounded-md px-3 py-1.5 text-xs font-bold uppercase tracking-wide',
                'bg-amber-400 text-slate-950' => $active === \App\Models\Setting::TvBoardLineup,
                'bg-zinc-800 text-zinc-300 ring-1 ring-zinc-700 hover:bg-zinc-700' => $active !== \App\Models\Setting::TvBoardLineup,
            ])
        >Lineups</button>
        <a
            href="{{ route('tv') }}"
            target="_blank"
            class="rounded-md px-3 py-1.5 text-xs font-bold tracking-wide text-zinc-300 uppercase ring-1 ring-zinc-700 hover:bg-zinc-800"
        >Open TV</a>
    </div>
</div>
