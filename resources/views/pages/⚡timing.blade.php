<?php

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Timing')] class extends Component
{
    #[Computed]
    public function raceTypes(): Collection
    {
        return RaceType::query()
            ->with('races.entries.competitor')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (RaceType $raceType): bool => $raceType->races->isNotEmpty())
            ->values();
    }

    /**
     * One selectable file per race, or one file per type when One file is on.
     *
     * @return Collection<int, Race>
     */
    #[Computed]
    public function allRaces(): Collection
    {
        return $this->raceTypes
            ->flatMap(function (RaceType $raceType): Collection {
                $races = $raceType->combines_groups
                    ? $raceType->races->take(1)
                    : $raceType->races;

                return $races->each(fn (Race $race) => $race->setRelation('raceType', $raceType));
            })
            ->values();
    }

    public function updateLaps(int $raceId, ?string $laps): void
    {
        $laps = trim((string) $laps);
        $value = $laps === '' ? null : max(0, (int) $laps);
        $race = Race::query()->findOrFail($raceId);

        if ($race->raceType->combines_groups) {
            $race->raceType->races()->update(['laps' => $value]);

            return;
        }

        $race->update(['laps' => $value]);
    }

    public function updateTransponder(int $competitorId, ?string $transponder): void
    {
        $transponder = trim((string) $transponder);

        if (strlen($transponder) > 50) {
            $this->dispatch('notify', message: 'Transponder numbers can be at most 50 characters.', type: 'error');

            return;
        }

        Competitor::query()->whereKey($competitorId)->update(['transponder' => $transponder === '' ? null : $transponder]);
    }
};
?>

<div
    class="mx-auto max-w-6xl space-y-4"
    x-data="{
        selected: @js($this->allRaces
            ->filter(function ($race) {
                if ($race->raceType->combines_groups) {
                    return $race->raceType->races->contains(fn ($group) => $group->entries->isNotEmpty());
                }

                return $race->entries->isNotEmpty();
            })
            ->pluck('id')
            ->values()),
        allIds: @js($this->allRaces->pluck('id')->values()),
        zipUrl(ids) {
            return @js(route('exports.timing-zip')).concat('?races=', ids.join(','));
        },
        toggle(ids) {
            const allSelected = ids.every(id => this.selected.includes(id));
            this.selected = allSelected
                ? this.selected.filter(id => ! ids.includes(id))
                : [...new Set([...this.selected, ...ids])];
        },
    }"
>
    <div class="flex flex-wrap items-center gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="min-w-0 flex-1">
            <h1 class="text-lg font-black uppercase">Timing files</h1>
            <p class="text-sm text-slate-500">
                Each race has a transponder <b>.csv</b> in starting order (no header row) and a <b>.dat</b> with Laps, Length, Duration, and Breakout.
                <b>.zip</b> puts both files together. Types marked One file only show that combined file.
            </p>
        </div>
        <a
            href="{{ route('exports.timing-puller') }}"
            download
            class="rounded-md px-3 py-2 text-sm font-semibold ring-1 ring-slate-300 hover:bg-slate-50"
        >Download Windows puller</a>
        <button type="button" x-on:click="toggle(allIds)" class="rounded-md px-3 py-2 text-sm font-semibold ring-1 ring-slate-300 hover:bg-slate-50"
            x-text="allIds.every(id => selected.includes(id)) ? 'Select none' : 'Select all'"></button>
        <a
            x-bind:href="selected.length ? zipUrl(selected) : undefined"
            x-bind:aria-disabled="selected.length === 0"
            x-bind:class="selected.length === 0 ? 'pointer-events-none opacity-50' : ''"
            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-700"
            x-text="`Download ${selected.length} ${selected.length === 1 ? 'race' : 'races'}`"
        ></a>
    </div>

    @forelse ($this->raceTypes as $raceType)
        @php($typeMissing = $raceType->missingTransponderCount())
        <section wire:key="timing-type-{{ $raceType->id }}" class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200" @if ($raceType->combines_groups) x-data="{ open: false }" @endif>
            @if ($raceType->combines_groups)
                @php($fileRace = $raceType->races->first())
                @php($typeEntries = $raceType->timingEntries())
                <div class="flex flex-wrap items-center gap-3 px-4 py-2">
                    <input type="checkbox" value="{{ $fileRace->id }}" x-model.number="selected" class="size-4">
                    <button type="button" x-on:click="open = ! open" class="flex min-w-48 items-center gap-2 text-left">
                        <span class="text-xs text-slate-400" x-text="open ? '▾' : '▸'"></span>
                        <span class="font-bold">{{ $raceType->name }}</span>
                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-black tracking-wide text-amber-800 uppercase">One file</span>
                        <span class="text-xs text-slate-400">{{ $typeEntries->count() }} cars</span>
                    </button>
                    <label class="flex items-center gap-1 text-sm text-slate-600">
                        Laps
                        <input
                            type="number"
                            min="0"
                            value="{{ $raceType->sessionLaps() }}"
                            wire:change="updateLaps({{ $fileRace->id }}, $event.target.value)"
                            @class([
                                'w-16 rounded border px-1.5 py-0.5 focus:border-amber-500 focus:outline-none',
                                'border-red-400 bg-red-50' => ! $raceType->sessionLaps(),
                                'border-slate-300' => $raceType->sessionLaps(),
                            ])
                        >
                    </label>
                    <span class="flex flex-1 gap-2 text-xs font-semibold">
                        @if ($typeEntries->isEmpty())
                            <span class="text-slate-400">No drivers</span>
                        @endif
                        @if ($typeMissing)
                            <span class="text-red-600">{{ $typeMissing }} missing transponder{{ $typeMissing === 1 ? '' : 's' }}</span>
                        @endif
                        @if (! $raceType->sessionLaps())
                            <span class="text-red-600">No laps — .dat will say Laps,0</span>
                        @endif
                    </span>
                    <div class="flex gap-1 text-xs font-bold">
                        <a href="{{ route('exports.type-transponders', $raceType) }}" download class="rounded px-2 py-1 ring-1 ring-slate-300 hover:bg-slate-50">.csv</a>
                        <a href="{{ route('exports.type-laps', $raceType) }}" download class="rounded px-2 py-1 ring-1 ring-slate-300 hover:bg-slate-50">.dat</a>
                        <a href="{{ route('exports.type-zip', $raceType) }}" download class="rounded bg-slate-900 px-2 py-1 text-white hover:bg-slate-700">.zip</a>
                    </div>
                </div>
                <div x-show="open" x-cloak class="border-t border-slate-100 bg-slate-50 px-4 py-2">
                    @if ($typeEntries->isEmpty())
                        <p class="text-sm text-slate-400">No drivers in this type yet.</p>
                    @else
                        <table class="w-full max-w-2xl text-sm">
                            <thead class="text-left text-xs font-bold tracking-wide text-slate-500 uppercase">
                                <tr>
                                    <th class="w-12 py-1">Pos</th>
                                    <th class="w-16 py-1">#</th>
                                    <th class="py-1">Driver</th>
                                    <th class="w-48 py-1">Trns ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($typeEntries as $entry)
                                    <tr wire:key="timing-type-entry-{{ $entry->id }}">
                                        <td class="py-0.5 text-slate-400">{{ $loop->iteration }}</td>
                                        <td class="py-0.5 font-bold">{{ $entry->competitor->car_number }}</td>
                                        <td class="py-0.5">{{ $entry->competitor->last_name }}, {{ $entry->competitor->first_name }}</td>
                                        <td class="py-0.5">
                                            <input
                                                value="{{ $entry->competitor->transponder }}"
                                                placeholder="Missing"
                                                wire:change="updateTransponder({{ $entry->competitor_id }}, $event.target.value)"
                                                @class([
                                                    'w-full rounded border px-1.5 py-0.5 focus:border-amber-500 focus:outline-none',
                                                    'border-red-400 bg-red-50 placeholder:text-red-400' => blank($entry->competitor->transponder),
                                                    'border-slate-200 bg-white' => filled($entry->competitor->transponder),
                                                ])
                                            >
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @else
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-2">
                    <h2 class="text-base font-black uppercase">{{ $raceType->name }}</h2>
                    <button
                        type="button"
                        x-on:click="toggle(@js($raceType->races->pluck('id')))"
                        class="text-xs font-semibold text-slate-500 hover:text-slate-900"
                    >Select all {{ $raceType->name }}</button>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($raceType->races as $race)
                        @php($missingTransponders = $race->entries->filter(fn ($entry) => blank($entry->competitor->transponder))->count())
                        <div wire:key="timing-race-{{ $race->id }}" x-data="{ open: false }">
                            <div class="flex flex-wrap items-center gap-3 px-4 py-2">
                                <input type="checkbox" value="{{ $race->id }}" x-model.number="selected" class="size-4">
                                <button type="button" x-on:click="open = ! open" class="flex min-w-48 items-center gap-2 text-left">
                                    <span class="text-xs text-slate-400" x-text="open ? '▾' : '▸'"></span>
                                    <span class="font-bold">{{ $race->name }}</span>
                                    <span class="text-xs text-slate-400">{{ $race->entries->count() }} cars</span>
                                </button>
                                <label class="flex items-center gap-1 text-sm text-slate-600">
                                    Laps
                                    <input
                                        type="number"
                                        min="0"
                                        value="{{ $race->laps }}"
                                        wire:change="updateLaps({{ $race->id }}, $event.target.value)"
                                        @class([
                                            'w-16 rounded border px-1.5 py-0.5 focus:border-amber-500 focus:outline-none',
                                            'border-red-400 bg-red-50' => ! $race->laps,
                                            'border-slate-300' => $race->laps,
                                        ])
                                    >
                                </label>
                                <span class="flex flex-1 gap-2 text-xs font-semibold">
                                    @if ($race->entries->isEmpty())
                                        <span class="text-slate-400">No drivers</span>
                                    @endif
                                    @if ($missingTransponders)
                                        <span class="text-red-600" title="Drivers without a transponder are left out of the CSV">{{ $missingTransponders }} missing transponder{{ $missingTransponders === 1 ? '' : 's' }}</span>
                                    @endif
                                    @if (! $race->laps)
                                        <span class="text-red-600">No laps — .dat will say Laps,0</span>
                                    @endif
                                </span>
                                <div class="flex gap-1 text-xs font-bold">
                                    <a href="{{ route('exports.transponders', $race) }}" download class="rounded px-2 py-1 ring-1 ring-slate-300 hover:bg-slate-50">.csv</a>
                                    <a href="{{ route('exports.laps', $race) }}" download class="rounded px-2 py-1 ring-1 ring-slate-300 hover:bg-slate-50">.dat</a>
                                    <a href="{{ route('exports.race-zip', $race) }}" download class="rounded bg-slate-900 px-2 py-1 text-white hover:bg-slate-700">.zip</a>
                                </div>
                            </div>
                            <div x-show="open" x-cloak class="bg-slate-50 px-4 py-2">
                                @if ($race->entries->isEmpty())
                                    <p class="text-sm text-slate-400">No drivers in this race yet.</p>
                                @else
                                    <table class="w-full max-w-2xl text-sm">
                                        <thead class="text-left text-xs font-bold tracking-wide text-slate-500 uppercase">
                                            <tr>
                                                <th class="w-12 py-1">Pos</th>
                                                <th class="w-16 py-1">#</th>
                                                <th class="py-1">Driver</th>
                                                <th class="w-48 py-1">Trns ID</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($race->entries as $entry)
                                                <tr wire:key="timing-entry-{{ $entry->id }}">
                                                    <td class="py-0.5 text-slate-400">{{ $entry->start_position }}</td>
                                                    <td class="py-0.5 font-bold">{{ $entry->competitor->car_number }}</td>
                                                    <td class="py-0.5">{{ $entry->competitor->last_name }}, {{ $entry->competitor->first_name }}</td>
                                                    <td class="py-0.5">
                                                        <input
                                                            value="{{ $entry->competitor->transponder }}"
                                                            placeholder="Missing"
                                                            wire:change="updateTransponder({{ $entry->competitor_id }}, $event.target.value)"
                                                            @class([
                                                                'w-full rounded border px-1.5 py-0.5 focus:border-amber-500 focus:outline-none',
                                                                'border-red-400 bg-red-50 placeholder:text-red-400' => blank($entry->competitor->transponder),
                                                                'border-slate-200 bg-white' => filled($entry->competitor->transponder),
                                                            ])
                                                        >
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @empty
        <div class="rounded-xl border-2 border-dashed border-slate-300 p-10 text-center text-slate-400">
            No races yet. Set them up on <a href="{{ route('lineups') }}" wire:navigate class="text-amber-600 underline">Races &amp; Lineups</a>.
        </div>
    @endforelse
</div>
