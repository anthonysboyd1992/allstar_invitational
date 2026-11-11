<?php

use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceType;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lineup Board')] class extends Component
{
    /**
     * When true, hide the race picker (used by the shared /tv host).
     */
    public bool $displayOnly = false;

    /**
     * Draft race ids in the picker (string values for checkbox wire:model).
     *
     * @var list<string>
     */
    public array $raceIds = [];

    /**
     * Soft stamp so polls skip a re-render when nothing changed.
     */
    public string $stamp = '';

    /**
     * @var list<array{id: int, title: string, laps: ?int, slots: list<array{position: int, car: ?string, name: ?string, kart: ?string}>}>
     */
    public array $columns = [];

    public function mount(): void
    {
        $this->raceIds = $this->stringifyIds(Setting::lineupBoardRaceIds());
        $this->refreshBoard();
    }

    public function showPillDrawOnTv(): void
    {
        Setting::putTvBoardMode(Setting::TvBoardPillDraw);
        $this->dispatch('notify', message: 'TV switched to Pill Draw.');
    }

    public function showLineupBoardOnTv(): void
    {
        Setting::putTvBoardMode(Setting::TvBoardLineup);
        $this->dispatch('notify', message: 'TV switched to Lineup Board.');
    }

    public function updatedRaceIds(mixed $value): void
    {
        $this->raceIds = $this->stringifyIds($value);
    }

    public function refreshBoard(): void
    {
        if (! $this->isDirty()) {
            $this->raceIds = $this->stringifyIds(Setting::lineupBoardRaceIds());
        }

        $races = $this->onAirRaces();
        $stamp = implode(',', Setting::lineupBoardRaceIds()).'|'.$races
            ->map(function (Race $race): string {
                $slots = $race->lineupSlots()->map(function (array $slot): string {
                    /** @var RaceEntry|null $entry */
                    $entry = $slot['entry'];

                    return $slot['position'].':'
                        .($entry?->competitor_id ?? '')
                        .':'
                        .(string) ($entry?->competitor?->car_number ?? '')
                        .':'
                        .(string) ($entry?->car_id ?? '')
                        .':'
                        .(string) ($entry?->car?->number ?? '');
                })->implode(',');

                return $race->id.'|'
                    .$race->name.'|'
                    .(string) $race->lineup_name.'|'
                    .(string) $race->laps.'|'
                    .(string) $race->sponsor.'|'
                    .$slots;
            })
            ->implode('||');

        if ($this->stamp === $stamp) {
            return;
        }

        $this->stamp = $stamp;
        $this->columns = $races->map(fn (Race $race): array => [
            'id' => $race->id,
            'title' => $race->printedLineupTitle(),
            'laps' => filled($race->laps) ? (int) $race->laps : null,
            'slots' => $race->lineupSlots()->map(function (array $slot) use ($race): array {
                /** @var RaceEntry|null $entry */
                $entry = $slot['entry'];
                $driver = $entry?->competitor;

                return [
                    'position' => (int) $slot['position'],
                    'car' => $driver !== null ? (string) $driver->car_number : null,
                    'kart' => $entry !== null ? ($race->kartAssignment($entry) ?: null) : null,
                    'name' => $driver?->full_name,
                ];
            })->values()->all(),
        ])->values()->all();
    }

    public function selectType(int $raceTypeId): void
    {
        $typeIds = $this->catalog->firstWhere('id', $raceTypeId)
            ?->racesInProgramOrder()
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all() ?? [];

        if ($typeIds === []) {
            return;
        }

        $selected = collect($this->raceIds)->map(fn ($id): string => (string) $id);
        $allSelected = collect($typeIds)->diff($selected)->isEmpty();

        $this->raceIds = $allSelected
            ? $selected->diff($typeIds)->values()->all()
            : $selected->merge($typeIds)->unique()->values()->all();
    }

    public function clearSelection(): void
    {
        $this->raceIds = [];
    }

    public function selectAll(): void
    {
        $this->raceIds = $this->catalog
            ->flatMap(fn (RaceType $type) => $type->racesInProgramOrder()->pluck('id'))
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function saveToTv(): void
    {
        $this->raceIds = $this->stringifyIds($this->raceIds);
        Setting::putLineupBoardRaceIds($this->raceIds);
        Setting::putTvBoardMode(Setting::TvBoardLineup);
        $this->refreshBoard();
        $this->dispatch('notify', message: 'Lineup board saved and put on TV.');
    }

    /**
     * @return Collection<int, RaceType>
     */
    #[Computed]
    public function catalog(): Collection
    {
        return RaceType::query()
            ->with('races')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function isDirty(): bool
    {
        return $this->stringifyIds($this->raceIds) !== $this->stringifyIds(Setting::lineupBoardRaceIds());
    }

    public function isSelected(int $raceId): bool
    {
        return in_array((string) $raceId, $this->stringifyIds($this->raceIds), true);
    }

    public function typeIsFullySelected(RaceType $type): bool
    {
        $ids = $type->racesInProgramOrder()->pluck('id')->map(fn ($id): string => (string) $id);

        return $ids->isNotEmpty() && $ids->diff($this->stringifyIds($this->raceIds))->isEmpty();
    }

    public function typeIsPartiallySelected(RaceType $type): bool
    {
        if ($this->typeIsFullySelected($type)) {
            return false;
        }

        $ids = $type->racesInProgramOrder()->pluck('id')->map(fn ($id): string => (string) $id);
        $selected = collect($this->stringifyIds($this->raceIds));

        return $ids->contains(fn (string $id): bool => $selected->contains($id));
    }

    /**
     * @param  mixed  $ids
     * @return list<string>
     */
    protected function stringifyIds(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->map(fn ($id): string => (string) $id)
            ->filter(fn (string $id): bool => $id !== '' && $id !== '0')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Races currently saved for the TV board.
     *
     * @return Collection<int, Race>
     */
    protected function onAirRaces(): Collection
    {
        $ids = collect(Setting::lineupBoardRaceIds());

        if ($ids->isEmpty()) {
            return collect();
        }

        $races = Race::query()
            ->with(['entries.competitor', 'entries.car', 'raceType'])
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        return $ids
            ->map(fn (int $id): ?Race => $races->get($id))
            ->filter()
            ->values();
    }
};
?>

<div @class(['lineup-board-page', 'mx-auto max-w-[100rem] px-1 py-1 sm:px-2' => ! $displayOnly]) wire:poll.2s="refreshBoard">
    @if (! $displayOnly)
        <div class="mb-2 px-1">
            <x-tv-on-air-controls :active="Setting::tvBoardMode()" />
        </div>
    @endif

    <div
        x-data="timingFullscreen()"
        x-on:fullscreenchange.document="sync()"
        x-on:webkitfullscreenchange.document="sync()"
        x-on:keydown.escape.window="if (full) exit()"
        class="lineup-board timing-tower flex min-h-[70vh] flex-col overflow-hidden rounded-lg bg-zinc-950 text-zinc-100 shadow-2xl ring-1 ring-zinc-800"
    >
        <div class="lineup-board-header flex shrink-0 flex-wrap items-center gap-4 border-b border-white/10 bg-black px-5 py-4 sm:px-6">
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold tracking-[0.28em] text-amber-400 uppercase">Official</p>
                <h1 class="truncate text-lg font-semibold tracking-wide uppercase sm:text-xl">Lineup Board</h1>
                <p class="mt-0.5 text-[11px] tracking-wide text-zinc-500 uppercase">
                    {{ count($columns) }} {{ count($columns) === 1 ? 'race' : 'races' }} on TV
                    @if (! $displayOnly && $this->isDirty())
                        · draft not saved
                    @endif
                </p>
            </div>
            <button
                type="button"
                x-on:click="toggle()"
                class="shrink-0 rounded px-2.5 py-1 text-[11px] font-semibold text-zinc-400 ring-1 ring-zinc-700 hover:bg-zinc-800 hover:text-white"
            >
                <span x-show="! full">Full screen</span>
                <span x-show="full" x-cloak>Exit</span>
            </button>
        </div>

        <div class="timing-tower-table lineup-board-body flex min-h-0 flex-1 overflow-hidden">
            @if (! $displayOnly)
            <aside
                x-show="! full"
                x-cloak
                class="lineup-board-picker flex w-64 shrink-0 flex-col border-r border-white/10 bg-zinc-900/90 sm:w-72 lg:w-80"
            >
                <div class="shrink-0 border-b border-white/10 px-4 py-3">
                    <p class="text-[10px] font-semibold tracking-[0.22em] text-zinc-500 uppercase">Races to show</p>
                    <p class="mt-1 text-[11px] text-zinc-500">Check races, then Save to TV. Clear wipes the draft.</p>
                    <div class="mt-2 flex flex-wrap gap-2 text-[11px] font-semibold">
                        <button type="button" wire:click="selectAll" class="rounded px-2 py-1 text-zinc-300 ring-1 ring-zinc-700 hover:bg-zinc-800">Select all</button>
                        <button type="button" wire:click="clearSelection" class="rounded px-2 py-1 text-zinc-300 ring-1 ring-zinc-700 hover:bg-zinc-800">Clear</button>
                    </div>
                    <p class="mt-2 text-[11px] text-zinc-500">
                        {{ count($raceIds) }} selected
                        @if ($this->isDirty())
                            <span class="text-amber-400">· unsaved</span>
                        @endif
                    </p>
                    <button
                        type="button"
                        wire:click="saveToTv"
                        @class([
                            'mt-3 w-full rounded-md px-3 py-2 text-sm font-bold uppercase tracking-wide',
                            'bg-amber-400 text-slate-950 hover:bg-amber-300' => $this->isDirty() || count($columns) === 0,
                            'bg-zinc-800 text-zinc-300 ring-1 ring-zinc-700' => ! $this->isDirty() && count($columns) > 0,
                        ])
                    >Save to TV</button>
                </div>

                <div wire:key="lineup-picker-{{ implode('-', $raceIds) }}" class="min-h-0 flex-1 space-y-4 overflow-y-auto p-3">
                    @forelse ($this->catalog as $type)
                        <div wire:key="lineup-type-{{ $type->id }}">
                            <label class="flex cursor-pointer items-center gap-2 text-xs font-black tracking-wide text-zinc-200 uppercase">
                                <input
                                    type="checkbox"
                                    class="size-4 rounded border-zinc-600 bg-zinc-950 text-amber-400 focus:ring-amber-400"
                                    wire:click.prevent="selectType({{ $type->id }})"
                                    @checked($this->typeIsFullySelected($type))
                                    @disabled($type->races->isEmpty())
                                    x-data
                                    x-init="$el.indeterminate = {{ $this->typeIsPartiallySelected($type) ? 'true' : 'false' }}"
                                >
                                <span @class(['text-amber-400' => $this->typeIsFullySelected($type) || $this->typeIsPartiallySelected($type)])>{{ $type->name }}</span>
                            </label>
                            <div class="mt-1.5 ml-6 space-y-1">
                                @forelse ($type->racesInProgramOrder() as $race)
                                    <label
                                        wire:key="lineup-pick-{{ $race->id }}-{{ $this->isSelected($race->id) ? 'on' : 'off' }}"
                                        @class([
                                            'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm transition',
                                            'bg-amber-400/10 text-zinc-100 ring-1 ring-amber-400/30' => $this->isSelected($race->id),
                                            'text-zinc-400 hover:bg-zinc-800/80 hover:text-zinc-200' => ! $this->isSelected($race->id),
                                        ])
                                    >
                                        <input
                                            type="checkbox"
                                            class="size-4 rounded border-zinc-600 bg-zinc-950 text-amber-400 focus:ring-amber-400"
                                            wire:model.live="raceIds"
                                            value="{{ $race->id }}"
                                        >
                                        <span class="truncate">{{ $race->name }}</span>
                                    </label>
                                @empty
                                    <p class="text-xs text-zinc-600">No races</p>
                                @endforelse
                            </div>
                        </div>
                    @empty
                        <p class="px-1 text-sm text-zinc-500">Add races on the Lineups page first.</p>
                    @endforelse
                </div>
            </aside>
            @endif

            <div class="lineup-board-main flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                @if ($columns === [])
                    <div class="flex flex-1 items-center justify-center px-10 py-16 text-center">
                        <div>
                            <h2 class="text-2xl font-semibold tracking-tight">Nothing on the TV yet</h2>
                            <p class="mt-4 text-sm text-zinc-400">
                                @if ($displayOnly)
                                    Pick races on the Lineup Board control page, then Save to TV.
                                @else
                                    Pick races on the left, then Save to TV. Every shared TV will show that set.
                                @endif
                            </p>
                        </div>
                    </div>
                @else
                    @php
                        $raceCount = count($columns);
                        $laneLayout = $raceCount <= 2;
                        $columnsPerRow = min(5, max(1, $raceCount));
                    @endphp
                    <div class="lineup-board-heats min-h-0 flex-1 overflow-hidden p-3 sm:p-4">
                        <div
                            class="lineup-board-grid grid h-full min-h-0 gap-3"
                            style="grid-template-columns: repeat({{ $columnsPerRow }}, minmax(0, 1fr)); grid-auto-rows: minmax(0, 1fr);"
                        >
                            @foreach ($columns as $column)
                                <section
                                    wire:key="lineup-col-{{ $column['id'] }}"
                                    @class([
                                        'tv-heat lineup-board-heat flex min-h-0 min-w-0 flex-col overflow-hidden',
                                        'tv-heat--lanes' => $laneLayout,
                                        'tv-heat--stack' => ! $laneLayout,
                                    ])
                                >
                                    <header class="tv-heat__header lineup-board-heat-header shrink-0">
                                        <h2 class="tv-heat__title lineup-board-heat-title">{{ $column['title'] }}</h2>
                                        @if ($column['laps'] !== null)
                                            <p class="tv-heat__laps">{{ $column['laps'] }} {{ $column['laps'] === 1 ? 'Lap' : 'Laps' }}</p>
                                        @endif
                                        <p class="tv-heat__count">
                                            {{ count($column['slots']) }} {{ count($column['slots']) === 1 ? 'spot' : 'spots' }}
                                        </p>
                                        @if ($laneLayout)
                                            <div class="tv-heat__lanes">
                                                <span>Inside</span>
                                                <span>Outside</span>
                                            </div>
                                        @endif
                                    </header>
                                    <ol @class([
                                        'tv-heat__grid lineup-board-list min-h-0 flex-1 overflow-y-auto',
                                        'tv-heat__grid--lanes' => $laneLayout,
                                        'tv-heat__grid--stack' => ! $laneLayout,
                                    ])>
                                        @forelse ($column['slots'] as $slot)
                                            <x-tv-driver-tile
                                                wire:key="lineup-slot-{{ $column['id'] }}-{{ $slot['position'] }}"
                                                class="lineup-board-row"
                                                :position="$slot['position']"
                                                :car="$slot['car']"
                                                :kart="$slot['kart'] ?? null"
                                                :name="$slot['name']"
                                                :meta="filled($slot['name']) ? 'Start '.$slot['position'] : null"
                                                :side="$laneLayout ? ($slot['position'] % 2 ? 'inside' : 'outside') : 'stack'"
                                                title="{{ $laneLayout ? 'Row '.(int) ceil($slot['position'] / 2).' '.($slot['position'] % 2 ? 'inside' : 'outside') : 'Start '.$slot['position'] }}"
                                            />
                                        @empty
                                            <li @class(['tv-heat__empty', 'col-span-2' => $laneLayout])>No drivers in the races yet</li>
                                        @endforelse
                                    </ol>
                                </section>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
