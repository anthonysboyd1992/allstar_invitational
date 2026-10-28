<?php

use App\Models\RaceEntry;
use App\Models\RaceType;
use App\Services\TransferCalculator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Results')] class extends Component
{
    public int $version = 0;

    public bool $printPickerOpen = false;

    /** @var list<string> */
    public array $printRaceIds = [];

    /**
     * @return Collection<int, RaceType>
     */
    #[Computed]
    public function raceTypes(): Collection
    {
        return RaceType::query()
            ->where('has_results', true)
            ->with(['races.entries.competitor', 'transferRules.destination.races'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function scoreNext(int $entryId): void
    {
        $entry = RaceEntry::query()->with('race.entries')->findOrFail($entryId);

        if ($entry->finish_position !== null) {
            return;
        }

        $next = ((int) $entry->race->entries->max('finish_position')) + 1;
        $entry->update(['finish_position' => $next]);
        $this->version++;
    }

    public function unscore(int $entryId): void
    {
        $entry = RaceEntry::query()->findOrFail($entryId);
        $raceId = $entry->race_id;
        $entry->update(['finish_position' => null]);
        $this->rewriteFinishes(
            $raceId,
            RaceEntry::query()
                ->where('race_id', $raceId)
                ->whereNotNull('finish_position')
                ->orderBy('finish_position')
                ->get(),
        );
        $this->version++;
    }

    /**
     * Drag-and-drop on the finish list. Position 0 is first place.
     */
    public function sortFinishes(int|string $entryId, int $position): void
    {
        $entry = RaceEntry::query()->findOrFail((int) $entryId);
        $scored = RaceEntry::query()
            ->where('race_id', $entry->race_id)
            ->whereNotNull('finish_position')
            ->orderBy('finish_position')
            ->get();
        $moving = $scored->firstWhere('id', $entry->id);

        if ($moving === null) {
            return;
        }

        $ordered = $scored->reject(fn (RaceEntry $row): bool => $row->id === $moving->id)->values();
        $ordered->splice(max(0, min($position, $ordered->count())), 0, [$moving]);
        $this->rewriteFinishes($entry->race_id, $ordered->values());
        $this->version++;
    }

    public function calculate(int $raceTypeId): void
    {
        $raceType = RaceType::query()->findOrFail($raceTypeId);

        if (! $raceType->has_results) {
            return;
        }

        $error = (new TransferCalculator)->calculate($raceType);

        if ($error !== null) {
            $this->dispatch('notify', message: $error, type: 'error');

            return;
        }

        $this->dispatch('notify', message: 'Calculated '.$raceType->name.' transfers.');
    }

    public function openPrintPicker(?int $raceTypeId = null): void
    {
        $this->printPickerOpen = true;
        $this->printRaceIds = $this->raceTypes
            ->when($raceTypeId, fn (Collection $types) => $types->where('id', $raceTypeId))
            ->flatMap(fn (RaceType $type) => $type->racesInProgramOrder())
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    public function closePrintPicker(): void
    {
        $this->printPickerOpen = false;
    }

    public function selectAllPrintRaces(): void
    {
        $this->printRaceIds = $this->raceTypes
            ->flatMap(fn (RaceType $type) => $type->racesInProgramOrder())
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    public function clearPrintRaces(): void
    {
        $this->printRaceIds = [];
    }

    public function togglePrintType(int $raceTypeId): void
    {
        $ids = $this->raceTypes->firstWhere('id', $raceTypeId)?->racesInProgramOrder()
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all() ?? [];
        $selected = collect($this->printRaceIds)->map(fn ($id): string => (string) $id);

        $this->printRaceIds = collect($ids)->diff($selected)->isEmpty() && $ids !== []
            ? $selected->diff($ids)->values()->all()
            : $selected->merge($ids)->unique()->values()->all();
    }

    public function updatedPrintRaceIds(mixed $value): void
    {
        $this->printRaceIds = collect($value)
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function printSheetUrl(): ?string
    {
        $ids = collect($this->printRaceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $ids === [] ? null : route('results.print', ['races' => $ids]);
    }

    /**
     * @param  Collection<int, RaceEntry>  $ordered
     */
    private function rewriteFinishes(int $raceId, Collection $ordered): void
    {
        foreach ($ordered as $entry) {
            $entry->update(['finish_position' => null]);
        }

        foreach ($ordered->values() as $index => $entry) {
            $entry->update(['finish_position' => $index + 1]);
        }
    }
};
?>

<div class="mx-auto max-w-7xl space-y-4">
    @if ($printPickerOpen)
        <div
            class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-20"
            x-on:keydown.escape.window="$wire.closePrintPicker()"
            wire:click.self="closePrintPicker"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-black uppercase">Print</h2>
                    <button type="button" wire:click="closePrintPicker" class="rounded px-2 text-2xl leading-none text-slate-400 hover:text-slate-900">&times;</button>
                </div>
                <p class="mb-3 text-sm text-slate-500">Choose the races to put on the landscape results sheet.</p>
                <div class="mb-3 flex gap-2 text-xs font-bold">
                    <button type="button" wire:click="selectAllPrintRaces" class="rounded px-2 py-1 ring-1 ring-slate-300 hover:bg-slate-50">Select all</button>
                    <button type="button" wire:click="clearPrintRaces" class="rounded px-2 py-1 ring-1 ring-slate-300 hover:bg-slate-50">Clear</button>
                </div>
                <div class="max-h-80 space-y-3 overflow-y-auto">
                    @foreach ($this->raceTypes as $raceType)
                        <div wire:key="print-type-{{ $raceType->id }}">
                            <label class="flex items-center gap-2 text-sm font-black uppercase">
                                <input
                                    type="checkbox"
                                    wire:click="togglePrintType({{ $raceType->id }})"
                                    @checked($raceType->races->isNotEmpty() && $raceType->races->every(fn ($race) => in_array((string) $race->id, $printRaceIds, true)))
                                    @disabled($raceType->races->isEmpty())
                                >
                                {{ $raceType->name }}
                            </label>
                            <div class="mt-1 ml-6 space-y-1">
                                @forelse ($raceType->racesInProgramOrder() as $race)
                                    <label wire:key="print-race-{{ $race->id }}" class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model.live="printRaceIds" value="{{ $race->id }}">
                                        {{ $race->name }}
                                    </label>
                                @empty
                                    <p class="text-xs text-slate-400">No races in this type.</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="closePrintPicker" class="rounded-md px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</button>
                    <a
                        href="{{ $this->printSheetUrl() ?? '#' }}"
                        target="_blank"
                        @class(['rounded-md px-4 py-2 text-sm font-semibold', 'bg-slate-900 text-white hover:bg-slate-700' => $this->printSheetUrl(), 'pointer-events-none bg-slate-200 text-slate-400' => ! $this->printSheetUrl()])
                    >Print selected</a>
                </div>
            </div>
        </div>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black uppercase">Results</h1>
            <p class="mt-1 text-sm text-slate-500">Click a driver in the lineup to score them. Drag the results list to fix a mistake.</p>
        </div>
        <button type="button" wire:click="openPrintPicker" class="rounded-md bg-white px-3 py-1.5 text-sm font-semibold ring-1 ring-slate-300 hover:bg-slate-50">Print results</button>
    </div>

    @forelse ($this->raceTypes as $raceType)
        <section wire:key="results-type-{{ $raceType->id }}" class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-black uppercase">{{ $raceType->name }}</h2>
                <span class="text-xs text-slate-500">
                    @forelse ($raceType->transferRules as $rule)
                        {{ $rule->position_from }}{{ $rule->position_to && $rule->position_to !== $rule->position_from ? '–'.$rule->position_to : ($rule->position_to === null ? '+' : '') }}
                        → {{ $rule->destination?->name ?? '—' }}
                        @if (! $loop->last)
                            <span class="text-slate-300">·</span>
                        @endif
                    @empty
                        No transfer rules
                    @endforelse
                </span>
                <button type="button" wire:click="openPrintPicker({{ $raceType->id }})" class="ml-auto rounded-md bg-white px-2.5 py-1 text-xs font-bold ring-1 ring-slate-300 hover:bg-slate-50">Print</button>
                <button
                    type="button"
                    wire:click="calculate({{ $raceType->id }})"
                    @disabled(! $raceType->resultsAreComplete() || $raceType->transferRules->isEmpty())
                    class="rounded-md bg-[#ee6426] px-2.5 py-1 text-xs font-bold text-white hover:bg-orange-600 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                >Calculate</button>
            </div>

            <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                @forelse ($raceType->racesInProgramOrder() as $race)
                    <article wire:key="results-race-{{ $race->id }}-{{ $version }}" class="overflow-hidden rounded-sm bg-white ring-2 ring-slate-800">
                        <div class="flex items-center justify-between bg-slate-900 px-3 py-2 text-white">
                            <h3 class="text-sm font-black uppercase">{{ $race->name }}</h3>
                            <span class="bg-[#ee6426] px-1.5 py-0.5 font-mono text-xs font-black text-white">{{ $race->scoredEntries()->count() }}/{{ $race->entries->count() }}</span>
                        </div>
                        @if ($race->entries->isEmpty())
                            <p class="px-3 py-4 text-sm text-slate-400">No drivers in this race.</p>
                        @else
                            <div class="grid grid-cols-2">
                                <div class="bg-slate-100">
                                    <p class="bg-slate-800 px-3 py-1.5 text-xs font-black tracking-wide text-white uppercase">Lineup</p>
                                    <div class="flex flex-col gap-1 p-1.5">
                                        @foreach ($race->entries->sortBy('start_position') as $entry)
                                            @if ($entry->finish_position === null)
                                                <button
                                                    type="button"
                                                    wire:key="lineup-{{ $entry->id }}-{{ $version }}"
                                                    wire:click="scoreNext({{ $entry->id }})"
                                                    class="flex w-full items-center gap-2 bg-white px-2 py-2 text-left text-sm ring-1 ring-slate-300 hover:bg-sky-50 hover:ring-sky-500"
                                                >
                                                    <span class="flex size-6 shrink-0 items-center justify-center bg-slate-800 font-mono text-xs font-black text-white">{{ $entry->start_position }}</span>
                                                    <span class="w-10 shrink-0 bg-slate-900 py-0.5 text-center font-mono text-xs font-black text-white">{{ filled($entry->competitor->car_number) ? $entry->competitor->car_number : '—' }}</span>
                                                    <span class="min-w-0 truncate font-semibold">{{ $entry->competitor->full_name }}</span>
                                                </button>
                                            @else
                                                <div wire:key="lineup-{{ $entry->id }}-{{ $version }}" class="flex items-center gap-2 bg-slate-200/80 px-2 py-2 text-sm text-slate-400">
                                                    <span class="flex size-6 shrink-0 items-center justify-center bg-slate-300 font-mono text-xs font-black text-white">{{ $entry->start_position }}</span>
                                                    <span class="w-10 shrink-0 bg-slate-300 py-0.5 text-center font-mono text-xs font-black text-white">{{ filled($entry->competitor->car_number) ? $entry->competitor->car_number : '—' }}</span>
                                                    <span class="min-w-0 truncate line-through">{{ $entry->competitor->full_name }}</span>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                                <div class="border-l-4 border-[#ee6426] bg-orange-50">
                                    <p class="bg-[#ee6426] px-3 py-1.5 text-xs font-black tracking-wide text-white uppercase">Results</p>
                                    <div
                                        wire:key="finishes-{{ $race->id }}-{{ $version }}"
                                        wire:sort="sortFinishes"
                                        wire:sort:group="finish-{{ $race->id }}"
                                        class="flex min-h-24 flex-col gap-1 p-1.5"
                                    >
                                        @forelse ($race->scoredResultRows() as $row)
                                            <div
                                                wire:key="scored-{{ $row['entry']->id }}-{{ $version }}"
                                                wire:sort:item="{{ $row['entry']->id }}"
                                                class="flex items-center gap-2 bg-white px-2 py-2 text-sm ring-1 ring-orange-200"
                                            >
                                                <span wire:sort:handle class="flex size-6 shrink-0 cursor-grab items-center justify-center bg-[#ee6426] font-mono text-xs font-black text-white select-none">{{ $row['entry']->finish_position }}</span>
                                                <span class="w-10 shrink-0 bg-slate-900 py-0.5 text-center font-mono text-xs font-black text-white">{{ filled($row['entry']->competitor->car_number) ? $row['entry']->competitor->car_number : '—' }}</span>
                                                <span class="min-w-0 flex-1 truncate font-semibold">{{ $row['entry']->competitor->full_name }}</span>
                                                @if ($row['transfer'])
                                                    <span class="max-w-24 shrink-0 truncate bg-slate-900 px-1 py-0.5 text-[10px] font-black text-white uppercase">{{ $row['transfer'] }}</span>
                                                @endif
                                                <button type="button" wire:click.stop="unscore({{ $row['entry']->id }})" wire:sort:ignore title="Remove from results" class="flex size-6 shrink-0 items-center justify-center bg-orange-100 text-slate-500 hover:bg-red-600 hover:text-white">&times;</button>
                                            </div>
                                        @empty
                                            <p class="px-2 py-4 text-xs font-semibold text-orange-800/60">Click the lineup.</p>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="rounded-lg bg-white px-3 py-4 text-sm text-slate-400 ring-1 ring-slate-200">No races in this type.</p>
                @endforelse
            </div>
        </section>
    @empty
        <p class="rounded-xl bg-white p-6 text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">Turn on Has results when editing a race type, then score finishes here.</p>
    @endforelse
</div>
