<label class="block">
    <span class="text-xs font-semibold text-slate-500">{{ $label }}</span>
    <span class="mt-0.5 flex items-center gap-1">
        <input
            type="number"
            min="0"
            max="1440"
            @if ($blur ?? false)
                wire:model.blur="{{ $minutes }}"
            @else
                wire:model="{{ $minutes }}"
            @endif
            aria-label="Minutes"
            class="w-16 rounded-md border border-slate-300 bg-white px-2 py-1.5 text-right font-mono text-sm focus:border-amber-500 focus:outline-none"
        >
        <span class="text-xs text-slate-500">min</span>
        <input
            type="number"
            min="0"
            max="59"
            @if ($blur ?? false)
                wire:model.blur="{{ $seconds }}"
            @else
                wire:model="{{ $seconds }}"
            @endif
            aria-label="Seconds"
            class="w-16 rounded-md border border-slate-300 bg-white px-2 py-1.5 text-right font-mono text-sm focus:border-amber-500 focus:outline-none"
        >
        <span class="text-xs text-slate-500">sec</span>
    </span>
</label>
