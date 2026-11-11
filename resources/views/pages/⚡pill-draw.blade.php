<?php

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pill Draw')] class extends Component
{
    public const DefaultHeatCount = 5;

    /**
     * When true, hide control chrome (used by the shared /tv host).
     */
    public bool $displayOnly = false;

    /**
     * @var array{
     *     stamp: string,
     *     drawn: int,
     *     complete: bool,
     *     drawing: ?array{id: int, car: string, name: string, pill: ?int},
     *     heats: list<array{title: string, drivers: list<array{id: int, pill: int, car: string, name: string, fresh: bool}>}>,
     *     undrawn: list<array{id: int, car: string, name: string}>
     * }
     */
    public array $board = [
        'stamp' => '',
        'drawn' => 0,
        'complete' => false,
        'drawing' => null,
        'heats' => [],
        'undrawn' => [],
    ];

    public function mount(): void
    {
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

    public function refreshBoard(): void
    {
        $labels = $this->heatLabels();
        $competitors = Competitor::query()->get();
        $drawn = $competitors->filter(fn (Competitor $driver): bool => filled($driver->pill_number))->values();
        $undrawn = $competitors->filter(fn (Competitor $driver): bool => blank($driver->pill_number))
            ->sort(fn (Competitor $a, Competitor $b): int => strcasecmp($a->last_name, $b->last_name)
                ?: strcasecmp($a->first_name, $b->first_name)
                ?: strnatcasecmp((string) $a->car_number, (string) $b->car_number))
            ->values();

        $drawingCompetitor = Setting::pillDrawCompetitor();
        $pendingPill = Setting::pillDrawPendingPill();
        $drawing = $drawingCompetitor === null ? null : [
            'id' => $drawingCompetitor->id,
            'car' => (string) $drawingCompetitor->car_number,
            'name' => $drawingCompetitor->full_name,
            'pill' => $pendingPill,
        ];

        $stamp = $labels->implode('|').'|'.$drawn
            ->sortBy(fn (Competitor $driver): string => sprintf('%06d-%d', (int) $driver->pill_number, $driver->id))
            ->map(fn (Competitor $driver): string => $driver->id.':'.$driver->pill_number)
            ->implode(',').'|u:'.$undrawn->pluck('id')->implode(',').'|d:'.($drawing['id'] ?? '').':'.($drawing['pill'] ?? '');

        if (($this->board['stamp'] ?? '') === $stamp) {
            return;
        }

        $previousUndrawnIds = collect($this->board['undrawn'] ?? [])->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $previousPlacementById = [];

        foreach ($this->board['heats'] ?? [] as $heatIndex => $heat) {
            foreach ($heat['drivers'] as $slot => $driver) {
                $previousPlacementById[(int) $driver['id']] = [
                    'heat' => (int) $heatIndex,
                    'slot' => (int) $slot,
                ];
            }
        }

        $buckets = Competitor::dealAcrossBuckets(Competitor::sortByPillNumber($drawn), $labels->count());
        $complete = $competitors->isNotEmpty() && $undrawn->isEmpty();
        $arrivals = [];

        $heats = $labels->map(function (string $title, int $index) use ($buckets, $previousUndrawnIds, $previousPlacementById, &$arrivals): array {
            $drivers = ($buckets[$index] ?? collect())->values()->map(function (Competitor $driver, int $slot) use ($index, $title, $previousUndrawnIds, $previousPlacementById, &$arrivals): array {
                $id = $driver->id;
                $fromUndrawn = in_array($id, $previousUndrawnIds, true);
                $previous = $previousPlacementById[$id] ?? null;
                $relocated = $previous !== null
                    && ((int) $previous['heat'] !== $index || (int) $previous['slot'] !== $slot);
                $fresh = $fromUndrawn || $relocated;

                if ($fresh) {
                    $arrivals[] = [
                        'id' => $id,
                        'pill' => (int) $driver->pill_number,
                        'car' => (string) $driver->car_number,
                        'name' => $driver->full_name,
                        'heat' => $index,
                        'slot' => $slot,
                        'title' => $title,
                        'from' => $fromUndrawn ? 'undrawn' : 'heat',
                    ];
                }

                return [
                    'id' => $id,
                    'pill' => (int) $driver->pill_number,
                    'car' => (string) $driver->car_number,
                    'name' => $driver->full_name,
                    'fresh' => $fresh,
                ];
            })->all();

            return [
                'title' => $title,
                'drivers' => $drivers,
            ];
        })->values()->all();

        $this->board = [
            'stamp' => $stamp,
            'drawn' => $drawn->count(),
            'complete' => $complete,
            'drawing' => $drawing,
            'heats' => $heats,
            'undrawn' => $undrawn->map(fn (Competitor $driver): array => [
                'id' => $driver->id,
                'car' => (string) $driver->car_number,
                'name' => $driver->full_name,
            ])->all(),
        ];

        if ($arrivals !== []) {
            $this->dispatch('pill-draw-fly', arrivals: $arrivals);
        }
    }

    /**
     * Heat column titles from the Heat race type, or Heat 1–5 when none exist.
     *
     * @return Collection<int, string>
     */
    public function heatLabels(): Collection
    {
        $type = RaceType::query()
            ->where(function ($query): void {
                $query->whereRaw('lower(name) = ?', ['heat'])
                    ->orWhereRaw('lower(name) = ?', ['heats']);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($type !== null) {
            $type->loadMissing('races');
            $races = $type->racesInProgramOrder();

            if ($races->isNotEmpty()) {
                return $races->map(fn (Race $race): string => $race->name)->values();
            }
        }

        return collect(range(1, self::DefaultHeatCount))
            ->map(fn (int $number): string => 'Heat '.$number);
    }
};
?>

<div @class(['pill-draw-page', 'mx-auto max-w-[100rem] px-1 py-1 sm:px-2' => ! $displayOnly]) wire:poll.1s="refreshBoard">
    @if (! $displayOnly)
        <div class="mb-2 px-1">
            <x-tv-on-air-controls :active="Setting::tvBoardMode()" />
        </div>
    @endif

    <div
        x-data="pillDrawBoard()"
        x-on:fullscreenchange.document="sync()"
        x-on:webkitfullscreenchange.document="sync()"
        x-on:keydown.escape.window="if (full) exit()"
        x-on:pill-draw-fly.window="onFly($event)"
        x-on:pill-draw-fly="onFly($event)"
        class="pill-draw-board timing-tower flex min-h-[70vh] flex-col overflow-hidden rounded-lg bg-zinc-950 text-zinc-100 shadow-2xl ring-1 ring-zinc-800"
    >
        <div class="pill-draw-header flex shrink-0 flex-wrap items-center gap-4 border-b border-white/10 bg-black px-5 py-4 sm:px-6">
            <div class="min-w-0 flex-1">
                @if ($board['complete'])
                    <p class="text-[11px] font-semibold tracking-[0.28em] text-emerald-400 uppercase">Official</p>
                    <h1 class="truncate text-lg font-semibold tracking-wide uppercase sm:text-xl">Hotlaps / Heat Race Groups</h1>
                    <p class="mt-0.5 text-[11px] tracking-wide text-zinc-400 uppercase">
                        All cars drawn · {{ $board['drawn'] }} {{ $board['drawn'] === 1 ? 'driver' : 'drivers' }} · these groups are official
                    </p>
                @else
                    <p class="text-[11px] font-semibold tracking-[0.28em] text-amber-400 uppercase">Projected</p>
                    <h1 class="truncate text-lg font-semibold tracking-wide uppercase sm:text-xl">Hotlaps / Heat Race Groups</h1>
                    <p class="mt-0.5 text-[11px] tracking-wide text-zinc-500 uppercase">
                        {{ $board['drawn'] }} drawn · {{ count($board['undrawn']) }} waiting · becomes official when every car has drawn
                    </p>
                @endif
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

        @if ($board['drawing'] !== null)
            <div class="shrink-0 border-b border-amber-400/30 bg-amber-400/10 px-5 py-3 sm:px-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold tracking-[0.28em] text-amber-400 uppercase">Now drawing</p>
                        <p class="mt-1 truncate text-xl font-black tracking-wide text-amber-100 uppercase sm:text-2xl">
                            #{{ filled($board['drawing']['car']) ? $board['drawing']['car'] : '—' }}
                            {{ $board['drawing']['name'] }}
                        </p>
                    </div>
                    @if ($board['drawing']['pill'] !== null)
                        <div class="shrink-0 text-right">
                            <p class="text-[10px] font-semibold tracking-[0.28em] text-amber-400/80 uppercase">Pill</p>
                            <p class="mt-0.5 font-mono text-4xl font-black leading-none text-amber-300 sm:text-5xl">{{ $board['drawing']['pill'] }}</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <div class="timing-tower-table pill-draw-body flex min-h-0 flex-1 overflow-hidden">
            <div class="pill-draw-main flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                @if ($board['drawn'] === 0)
                    <div class="flex flex-1 items-center justify-center px-10 py-16 text-center">
                        <div class="pill-draw-empty">
                            <h2 class="text-2xl font-semibold tracking-tight">Waiting for the first pill</h2>
                            <p class="mt-4 text-sm text-zinc-400">Projected Hotlaps / Heat Race Groups update as pills are drawn. Lowest pill lands in Heat 1, then Heat 2, wrapping through every heat.</p>
                        </div>
                    </div>
                @else
                    @php
                        $heatCount = count($board['heats']);
                        $laneLayout = $heatCount <= 2;
                        $columnsPerRow = min(5, max(1, $heatCount));
                    @endphp
                    <div class="pill-draw-heats min-h-0 flex-1 overflow-hidden p-3 sm:p-4">
                        <div
                            class="pill-draw-grid grid h-full min-h-0 gap-3"
                            style="grid-template-columns: repeat({{ $columnsPerRow }}, minmax(0, 1fr)); grid-auto-rows: minmax(0, 1fr);"
                        >
                            @foreach ($board['heats'] as $heatIndex => $heat)
                                <section
                                    data-heat-index="{{ $heatIndex }}"
                                    @class([
                                        'tv-heat pill-draw-heat flex min-h-0 min-w-0 flex-col',
                                        'tv-heat--lanes' => $laneLayout,
                                        'tv-heat--stack' => ! $laneLayout,
                                    ])
                                >
                                    <header class="tv-heat__header pill-draw-heat-header shrink-0">
                                        <h2 class="tv-heat__title pill-draw-heat-title">{{ $heat['title'] }}</h2>
                                        <p class="tv-heat__count">
                                            {{ count($heat['drivers']) }} {{ count($heat['drivers']) === 1 ? 'driver' : 'drivers' }}
                                        </p>
                                        @if ($laneLayout)
                                            <div class="tv-heat__lanes">
                                                <span>Inside</span>
                                                <span>Outside</span>
                                            </div>
                                        @endif
                                    </header>
                                    <ol @class([
                                        'tv-heat__grid pill-draw-list min-h-0 flex-1 overflow-y-auto',
                                        'tv-heat__grid--lanes' => $laneLayout,
                                        'tv-heat__grid--stack' => ! $laneLayout,
                                    ])>
                                        @forelse ($heat['drivers'] as $index => $driver)
                                            @php($position = $index + 1)
                                            <x-tv-driver-tile
                                                wire:key="pill-driver-{{ $driver['id'] }}"
                                                data-heat-driver-id="{{ $driver['id'] }}"
                                                :position="$position"
                                                :car="$driver['car']"
                                                :name="$driver['name']"
                                                :meta="'Pill '.$driver['pill']"
                                                :side="$laneLayout ? ($position % 2 ? 'inside' : 'outside') : 'stack'"
                                                title="{{ $laneLayout ? 'Row '.(int) ceil($position / 2).' '.($position % 2 ? 'inside' : 'outside') : 'Start '.$position }}"
                                                @class(['pill-draw-row', 'pill-draw-row--awaiting' => $driver['fresh']])
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

            <aside
                data-undrawn-column
                class="pill-draw-undrawn flex w-64 shrink-0 flex-col border-l border-white/10 bg-zinc-900/90 sm:w-72 lg:w-80"
            >
                <div class="shrink-0 border-b border-white/10 px-3 py-2.5 sm:px-4">
                    <p class="text-[10px] font-semibold tracking-[0.22em] text-zinc-500 uppercase">Still to draw</p>
                    <h3 class="mt-1 text-sm font-semibold tracking-wide text-zinc-200 uppercase">
                        @if ($board['complete'])
                            Draw complete
                        @else
                            {{ count($board['undrawn']) }} {{ count($board['undrawn']) === 1 ? 'driver' : 'drivers' }}
                        @endif
                    </h3>
                </div>

                @if ($board['complete'])
                    <div class="flex flex-1 items-center px-4 py-6">
                        <p class="rounded-md bg-emerald-500/10 px-3 py-3 text-sm font-semibold text-emerald-300 ring-1 ring-emerald-500/20">
                            Every car has drawn. These Hotlaps / Heat Race Groups are now official.
                        </p>
                    </div>
                @elseif (count($board['undrawn']) === 0)
                    <div class="flex flex-1 items-center px-4 py-6">
                        <p class="text-sm text-zinc-500">No competitors yet.</p>
                    </div>
                @else
                    <ol class="pill-draw-undrawn-list grid min-h-0 flex-1 content-start gap-1 overflow-hidden p-2">
                        @foreach ($board['undrawn'] as $index => $driver)
                            @php($isDrawing = ($board['drawing']['id'] ?? null) === $driver['id'])
                            <li
                                wire:key="undrawn-{{ $driver['id'] }}"
                                data-undrawn-id="{{ $driver['id'] }}"
                                @class([
                                    'pill-draw-undrawn-row min-w-0 rounded px-1.5 py-1 ring-1',
                                    'bg-amber-400/15 ring-amber-400/50' => $isDrawing,
                                    'bg-zinc-950 ring-white/10' => ! $isDrawing,
                                ])
                                style="animation-delay: {{ min($index, 24) * 18 }}ms"
                            >
                                <p @class([
                                    'text-[11px] font-semibold leading-snug tracking-wide break-words sm:text-xs',
                                    'text-amber-100' => $isDrawing,
                                    'text-zinc-100' => ! $isDrawing,
                                ])>{{ $driver['name'] }}</p>
                                <p @class([
                                    'mt-0.5 font-mono text-[10px] leading-tight',
                                    'text-amber-300/80' => $isDrawing,
                                    'text-zinc-500' => ! $isDrawing,
                                ])>
                                    #{{ filled($driver['car']) ? $driver['car'] : '—' }}
                                    @if ($isDrawing)
                                        <span class="ml-1 tracking-wide uppercase">Drawing</span>
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </aside>
        </div>
    </div>
</div>
