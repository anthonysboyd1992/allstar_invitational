<?php

use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceType;
use App\Models\TimingFeedResult;
use App\TimingStatus;
use App\Services\TimingFeedStats;
use App\Services\TransferCalculator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Results')] class extends Component
{
    public int $version = 0;

    public bool $printPickerOpen = false;

    /** @var array<string, string> */
    public array $feedResultByRace = [];

    /** @var list<string> */
    public array $printRaceIds = [];

    public ?int $invertRaceId = null;

    public int $invertTop = 8;

    public bool $invertFullField = false;

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
        $entry->race->setTimingStatus(TimingStatus::Complete);
        unset($this->raceTypes);
        $this->version++;
    }

    public function updateTimingStatus(int $raceId, string $status): void
    {
        $timingStatus = TimingStatus::tryFrom($status);

        if ($timingStatus === null) {
            return;
        }

        Race::query()->with('raceType')->findOrFail($raceId)->setTimingStatus($timingStatus);
        unset($this->raceTypes);
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

    /**
     * @return Collection<int, TimingFeedResult>
     */
    #[Computed]
    public function feedResults(): Collection
    {
        return TimingFeedResult::query()
            ->available()
            ->latest()
            ->limit(25)
            ->get();
    }

    #[Computed]
    public function liveFeed(): ?TimingFeedResult
    {
        return TimingFeedResult::query()->live()->latest('updated_at')->first();
    }

    public function deleteSession(int $sessionId): void
    {
        $session = TimingFeedResult::query()->available()->findOrFail($sessionId);
        $session->ignore();

        foreach ($this->feedResultByRace as $raceId => $resultId) {
            if ((int) $resultId === $sessionId) {
                $this->feedResultByRace[$raceId] = '';
            }
        }

        unset($this->feedResults, $this->liveFeed, $this->liveSummary);
        $this->dispatch('notify', message: 'Deleted '.$session->name.'.');
    }

    /**
     * @return array{laps: ?int, race_laps: ?int, transfer_after: ?int, fastest_race: ?array{label: string, time: string, speed: string, number: string}, fastest_day: ?array{label: string, time: string, speed: string, number: string, session: string}, hard_charger: ?array{label: string, gain: int, number: string}, hard_charger_day: ?array{label: string, gain: int, number: string, session: string}, most_passes: ?array{label: string, passes: int, number: string}}|null
     */
    #[Computed]
    public function liveSummary(): ?array
    {
        $feed = $this->liveFeed;

        if ($feed === null) {
            return null;
        }

        return (new TimingFeedStats)->summarize($feed, $feed->orderedStandings());
    }

    public function applyFeedResult(int $raceId): void
    {
        $resultId = (int) ($this->feedResultByRace[(string) $raceId] ?? $this->feedResultByRace[$raceId] ?? 0);

        if ($resultId < 1) {
            $this->dispatch('notify', message: 'Select a timing feed result first.', type: 'error');

            return;
        }

        $race = Race::query()->with('entries.competitor')->findOrFail($raceId);
        $result = TimingFeedResult::query()->available()->findOrFail($resultId);
        $summary = $result->applyTo($race);

        unset($this->raceTypes, $this->feedResults, $this->liveFeed, $this->liveSummary);
        $this->version++;

        if ($summary['applied'] === 0) {
            $this->dispatch('notify', message: 'No drivers in '.$race->name.' matched that feed result.', type: 'error');

            return;
        }

        $this->feedResultByRace[(string) $raceId] = '';

        $message = 'Applied '.$summary['applied'].' finish'.($summary['applied'] === 1 ? '' : 'es').' to '.$race->name.'.';

        if ($summary['unmatched'] > 0) {
            $message .= ' '.$summary['unmatched'].' from the feed did not match.';
        }

        $this->dispatch('notify', message: $message);
    }

    public function openInvert(int $raceId): void
    {
        if ($this->liveFeed === null) {
            $this->dispatch('notify', message: 'Need a live timing board to invert.', type: 'error');

            return;
        }

        $this->invertRaceId = $raceId;
        $this->invertTop = 8;
        $this->invertFullField = false;
        unset($this->invertPreview);
    }

    public function closeInvert(): void
    {
        $this->invertRaceId = null;
        unset($this->invertPreview);
    }

    /**
     * @return array{restart: Collection<int, RaceEntry>, unmatched: int}|null
     */
    #[Computed]
    public function invertPreview(): ?array
    {
        if ($this->invertRaceId === null || $this->liveFeed === null) {
            return null;
        }

        $race = Race::query()->with('entries.competitor')->find($this->invertRaceId);

        if ($race === null || $race->isInverted()) {
            return null;
        }

        $count = $this->invertFullField ? 0 : max(1, $this->invertTop);
        $plan = $race->restartOrderFrom($this->liveFeed, $count);

        return [
            'restart' => $plan['restart'],
            'unmatched' => $plan['unmatched'],
        ];
    }

    public function invertFromLive(int $raceId): void
    {
        $race = Race::query()->with('entries.competitor')->findOrFail($raceId);

        if ($race->isInverted()) {
            $this->dispatch('notify', message: 'Undo invert on '.$race->name.' first.', type: 'error');

            return;
        }

        $feed = $this->liveFeed;

        if ($feed === null) {
            $this->dispatch('notify', message: 'Need a live timing board to invert.', type: 'error');

            return;
        }

        if (! $this->invertFullField) {
            $this->validate([
                'invertTop' => ['required', 'integer', 'min:1', 'max:50'],
            ], attributes: ['invertTop' => 'invert count']);
        }

        $summary = $race->invertFrom($feed, $this->invertFullField ? 0 : $this->invertTop);

        if ($summary['inverted'] === 0) {
            $this->dispatch('notify', message: 'No drivers in '.$race->name.' matched the live board.', type: 'error');

            return;
        }

        $this->invertRaceId = null;
        unset($this->raceTypes, $this->liveFeed, $this->liveSummary, $this->invertPreview, $this->feedResults);
        $this->version++;

        $message = 'Inverted '.$race->name.' for the restart.';

        if ($summary['unmatched'] > 0) {
            $message .= ' '.$summary['unmatched'].' from the feed did not match.';
        }

        $this->dispatch('notify', message: $message);
    }

    public function undoInvert(int $raceId): void
    {
        $race = Race::query()->findOrFail($raceId);

        if (! $race->isInverted()) {
            return;
        }

        $race->undoInvert();
        $this->invertRaceId = null;
        unset($this->raceTypes, $this->liveFeed, $this->liveSummary, $this->invertPreview);
        $this->version++;
        $this->dispatch('notify', message: 'Cleared invert on '.$race->name.'.');
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

    public function flagClasses(?string $flag): string
    {
        $flag = strtolower((string) $flag);

        return match (true) {
            str_contains($flag, 'green') => 'bg-emerald-500 text-black',
            str_contains($flag, 'yellow') || str_contains($flag, 'p-start') => 'bg-amber-400 text-black',
            str_contains($flag, 'red') => 'bg-red-600 text-white',
            str_contains($flag, 'white') => 'bg-white text-black',
            str_contains($flag, 'finish') || str_contains($flag, 'check') => 'bg-zinc-100 text-black',
            default => 'bg-zinc-700 text-zinc-100',
        };
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

<div class="mx-auto max-w-7xl space-y-8" wire:poll.5s>
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

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black uppercase">Results</h1>
            <p class="mt-2 text-sm text-slate-500">Click a driver in the lineup to score them, or pick a timing feed result the puller sent. Drag the results list to fix a mistake.</p>
        </div>
        <button type="button" wire:click="openPrintPicker" class="rounded-md bg-white px-3 py-1.5 text-sm font-semibold ring-1 ring-slate-300 hover:bg-slate-50">Print results</button>
    </div>

    @island
    <div class="contents" wire:poll.500ms>
    @if ($this->liveFeed)
        @php
            $liveStandings = (new TimingFeedStats)->withIntervals($this->liveFeed->orderedStandings());
            $liveSummary = $this->liveSummary;
        @endphp
        <div
            x-data="timingFullscreen()"
            x-on:fullscreenchange.document="sync()"
            x-on:webkitfullscreenchange.document="sync()"
            x-on:keydown.escape.window="if (full) exit()"
            class="timing-tower flex flex-col overflow-hidden rounded-lg bg-zinc-950 text-zinc-100 shadow-2xl ring-1 ring-zinc-800"
        >
            <div class="flex flex-wrap items-center gap-4 border-b border-white/10 bg-black px-5 py-4 sm:px-6">
                @if (filled($this->liveFeed->flag))
                    <span @class(['shrink-0 rounded px-2.5 py-1 text-[11px] font-bold tracking-[0.18em] uppercase', $this->flagClasses($this->liveFeed->flag)])>
                        {{ $this->liveFeed->flag }}
                    </span>
                @endif
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-semibold tracking-[0.2em] text-zinc-500 uppercase">Live timing</p>
                    <h2 class="truncate text-lg font-semibold tracking-wide uppercase">{{ $this->liveFeed->name }}</h2>
                    <p class="mt-0.5 text-[11px] tracking-wide text-zinc-500 uppercase">{{ $liveStandings->count() }} {{ $liveStandings->count() === 1 ? 'car' : 'cars' }}</p>
                </div>
                @if (($liveSummary['laps'] ?? null) && ($liveSummary['race_laps'] ?? null))
                    <span class="shrink-0 font-mono text-2xl font-semibold tracking-tight text-amber-400">{{ $liveSummary['laps'] }}<span class="text-zinc-500">/{{ $liveSummary['race_laps'] }}</span></span>
                @elseif ($liveSummary['laps'] ?? null)
                    <span class="shrink-0 font-mono text-2xl font-semibold tracking-tight text-amber-400">{{ $liveSummary['laps'] }}</span>
                @endif
                <span class="inline-flex shrink-0 items-center gap-2 rounded bg-red-600 px-2.5 py-1 text-[10px] font-bold tracking-[0.2em] text-white uppercase">
                    <span class="size-1.5 animate-pulse rounded-full bg-white"></span>
                    Live
                </span>
                <button
                    type="button"
                    x-on:click="toggle()"
                    class="shrink-0 rounded px-2.5 py-1 text-[11px] font-semibold text-zinc-400 ring-1 ring-zinc-700 hover:bg-zinc-800 hover:text-white"
                >
                    <span x-show="! full">Full screen</span>
                    <span x-show="full" x-cloak>Exit</span>
                </button>
                <button
                    type="button"
                    wire:click="deleteSession({{ $this->liveFeed->id }})"
                    class="shrink-0 rounded px-2.5 py-1 text-[11px] font-semibold text-zinc-400 ring-1 ring-zinc-700 hover:bg-zinc-800 hover:text-white"
                    x-show="! full"
                >Delete</button>
            </div>
            @if ($liveSummary['fastest_race'] ?? $liveSummary['hard_charger'] ?? $liveSummary['most_passes'] ?? $liveSummary['fastest_day'] ?? $liveSummary['transfer_after'] ?? null)
                <div class="flex flex-wrap gap-x-8 gap-y-3 border-b border-white/10 bg-zinc-900 px-5 py-3 sm:px-6">
                    @if ($liveSummary['fastest_race'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-amber-400 uppercase">Fastest lap</p>
                            <p class="mt-1 text-sm font-medium">{{ $liveSummary['fastest_race']['label'] }} <span class="inline-flex rounded bg-black px-1.5 py-0.5 font-mono text-amber-400">{{ $liveSummary['fastest_race']['time'] }}{{ ($liveSummary['fastest_race']['speed'] ?? '') !== '' ? ' · '.$liveSummary['fastest_race']['speed'].' mph' : '' }}</span></p>
                        </div>
                    @endif
                    @if ($liveSummary['fastest_day'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Fastest today</p>
                            <p class="mt-1 text-sm font-medium">{{ $liveSummary['fastest_day']['label'] }} <span class="font-mono text-zinc-200">{{ $liveSummary['fastest_day']['time'] }}{{ ($liveSummary['fastest_day']['speed'] ?? '') !== '' ? ' · '.$liveSummary['fastest_day']['speed'].' mph' : '' }}</span></p>
                            <p class="mt-0.5 text-[11px] text-zinc-500">{{ $liveSummary['fastest_day']['session'] }}</p>
                        </div>
                    @endif
                    @if ($liveSummary['hard_charger'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Hard charger</p>
                            <p class="mt-1 text-sm font-medium">{{ $liveSummary['hard_charger']['label'] }} <span class="font-mono text-emerald-400">+{{ $liveSummary['hard_charger']['gain'] }}</span></p>
                        </div>
                    @endif
                    @if ($liveSummary['hard_charger_day'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Hard charger today</p>
                            <p class="mt-1 text-sm font-medium">{{ $liveSummary['hard_charger_day']['label'] }} <span class="font-mono text-emerald-400">+{{ $liveSummary['hard_charger_day']['gain'] }}</span></p>
                            <p class="mt-0.5 text-[11px] text-zinc-500">{{ $liveSummary['hard_charger_day']['session'] }}</p>
                        </div>
                    @endif
                    @if ($liveSummary['most_passes'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Most passes today</p>
                            <p class="mt-1 text-sm font-medium">{{ $liveSummary['most_passes']['label'] }} <span class="font-mono">{{ $liveSummary['most_passes']['passes'] }}</span></p>
                        </div>
                    @endif
                    @if ($liveSummary['transfer_after'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Transfer</p>
                            <p class="mt-1 text-sm font-medium">Top {{ $liveSummary['transfer_after'] }}</p>
                        </div>
                    @endif
                </div>
            @endif
            <div class="timing-tower-table min-h-0 flex-1 overflow-y-auto" :class="full ? 'max-h-none' : 'max-h-[32rem]'">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-black text-[10px] font-semibold tracking-[0.2em] text-zinc-500 uppercase">
                        <tr>
                            <th class="px-5 py-3 text-right sm:px-6">Pos</th>
                            <th class="px-3 py-3">No</th>
                            <th class="px-3 py-3">Driver</th>
                            <th class="px-3 py-3 text-right">Laps</th>
                            <th class="px-3 py-3 text-right">Last</th>
                            <th class="px-3 py-3 text-right">Best</th>
                            <th class="px-3 py-3 text-right">Speed</th>
                            <th class="px-3 py-3 text-right">Gap</th>
                            <th class="px-5 py-3 text-right sm:px-6">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($liveStandings as $index => $row)
                            @php
                                $carNumber = $row['number'] !== '' ? $row['number'] : $row['registration'];
                                $isFastest = ($liveSummary['fastest_race']['number'] ?? null) === $carNumber;
                                $isPersonalBest = ($row['last_lap'] ?? '') !== '' && ($row['best_lap'] ?? '') !== '' && $row['last_lap'] === $row['best_lap'];
                                $next = $liveStandings[$index + 1] ?? null;
                                $cutoff = $liveSummary['transfer_after'] ?? null;
                                $showTransfer = $cutoff !== null
                                    && (int) $row['position'] > 0
                                    && (int) $row['position'] <= $cutoff
                                    && ($next === null || (int) data_get($next, 'position') > $cutoff);
                            @endphp
                            <tr
                                wire:key="results-live-{{ $this->liveFeed->id }}-{{ $row['position'] }}-{{ $row['transponder'] }}-{{ $row['number'] }}"
                                @class([
                                    'border-b border-white/5',
                                    'bg-amber-400 text-slate-950' => $isFastest,
                                    'bg-zinc-900' => ! $isFastest && $index % 2 === 0,
                                    'bg-zinc-950' => ! $isFastest && $index % 2 === 1,
                                ])
                            >
                                <td class="px-5 py-3 text-right font-mono text-base font-semibold tabular-nums sm:px-6">{{ $row['position'] }}</td>
                                <td class="px-3 py-3">
                                    <span @class(['inline-flex min-w-10 justify-center px-1.5 py-0.5 font-mono text-sm font-bold', 'bg-black text-amber-400' => $isFastest, 'bg-amber-400 text-slate-900' => ! $isFastest])>{{ $carNumber }}</span>
                                </td>
                                <td class="px-3 py-3 font-medium tracking-wide">{{ trim($row['first_name'].' '.$row['last_name']) }}</td>
                                <td @class(['px-3 py-3 text-right font-mono font-semibold tabular-nums', 'text-slate-900' => $isFastest, 'text-amber-400' => ! $isFastest])>
                                    @if ($row['laps'] !== null && ($liveSummary['race_laps'] ?? null))
                                        {{ $row['laps'] }}/{{ $liveSummary['race_laps'] }}
                                    @else
                                        {{ $row['laps'] ?? '' }}
                                    @endif
                                </td>
                                <td @class(['px-3 py-3 text-right font-mono tabular-nums', 'text-emerald-400' => ! $isFastest && $isPersonalBest])>{{ $row['last_lap'] ?? '' }}</td>
                                <td class="px-3 py-3 text-right">
                                    @if (($row['best_lap'] ?? '') !== '')
                                        <span @class(['inline-flex justify-center px-1.5 py-0.5 font-mono text-sm font-bold tabular-nums', 'rounded bg-black text-amber-400' => $isFastest, 'text-amber-400' => ! $isFastest])>{{ $row['best_lap'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right font-mono tabular-nums">{{ $row['speed'] ?? '' }}</td>
                                <td class="px-3 py-3 text-right font-mono tabular-nums">{{ $row['gap'] ?? '' }}</td>
                                <td class="px-5 py-3 text-right font-mono tabular-nums sm:px-6">{{ $row['differential'] ?? '' }}</td>
                            </tr>
                            @if ($showTransfer)
                                <tr wire:key="results-transfer-{{ $this->liveFeed->id }}">
                                    <td colspan="9" class="bg-red-600 py-1.5 text-center text-[10px] font-bold tracking-[0.28em] text-white uppercase">Transfer · Top {{ $cutoff }}</td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="9" class="px-5 py-10 text-center text-zinc-500">Race Manager is connected. Waiting for cars.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    </div>
    @endisland

    @if ($this->feedResults->isNotEmpty())
        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Timing sessions</h2>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach ($this->feedResults as $session)
                    @php($cars = $session->orderedStandings()->count())
                    <li wire:key="results-session-{{ $session->id }}" class="flex flex-wrap items-center gap-4 px-6 py-4">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold">{{ $session->name }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $cars }} {{ $cars === 1 ? 'car' : 'cars' }}@if (filled($session->flag)) · {{ $session->flag }}@endif</p>
                        </div>
                        @if ($session->is_live)
                            <span class="shrink-0 rounded bg-red-600 px-2.5 py-1 text-[10px] font-bold tracking-wide text-white uppercase">Live</span>
                        @endif
                        <button
                            type="button"
                            wire:click="deleteSession({{ $session->id }})"
                            class="shrink-0 rounded-md px-2.5 py-1 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-300 hover:bg-slate-50 hover:text-slate-900"
                        >Delete</button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

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
                        <div class="flex items-center justify-between gap-2 bg-slate-900 px-3 py-2 text-white">
                            <h3 class="text-sm font-black uppercase">{{ $race->name }}</h3>
                            <span class="flex items-center gap-2">
                                <select
                                    wire:change="updateTimingStatus({{ $race->id }}, $event.target.value)"
                                    class="rounded bg-white px-1.5 py-0.5 text-[11px] font-bold text-slate-900"
                                >
                                    @foreach (TimingStatus::cases() as $status)
                                        <option value="{{ $status->value }}" @selected($race->timing_status === $status)>{{ $status->label() }}</option>
                                    @endforeach
                                </select>
                                <span class="bg-[#ee6426] px-1.5 py-0.5 font-mono text-xs font-black text-white">{{ $race->scoredEntries()->count() }}/{{ $race->entries->count() }}</span>
                            </span>
                        </div>
                        @if ($race->entries->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2">
                                <select
                                    wire:model="feedResultByRace.{{ $race->id }}"
                                    class="min-w-0 flex-1 rounded border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-800"
                                >
                                    <option value="">{{ $this->feedResults->isEmpty() ? 'Waiting for timing feed…' : 'Timing feed…' }}</option>
                                    @foreach ($this->feedResults as $result)
                                        <option wire:key="feed-result-{{ $race->id }}-{{ $result->id }}" value="{{ $result->id }}">{{ $result->label() }}</option>
                                    @endforeach
                                </select>
                                <button
                                    type="button"
                                    wire:click="applyFeedResult({{ $race->id }})"
                                    @disabled($this->feedResults->isEmpty())
                                    class="rounded bg-slate-900 px-2.5 py-1 text-xs font-bold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                                >Apply</button>
                                @if ($race->isInverted())
                                    <button
                                        type="button"
                                        wire:click="undoInvert({{ $race->id }})"
                                        class="rounded px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-white"
                                    >Undo invert</button>
                                @elseif ($this->liveFeed)
                                    <button
                                        type="button"
                                        wire:click="openInvert({{ $race->id }})"
                                        class="rounded px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-white"
                                    >Invert</button>
                                @endif
                            </div>
                            @if ($this->invertRaceId === $race->id)
                                <div class="space-y-2 border-b border-slate-200 bg-white px-3 py-3">
                                    <p class="text-xs font-black tracking-wide text-slate-500 uppercase">Invert from live board</p>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-700">
                                            <input type="checkbox" wire:model.live="invertFullField" class="rounded border-slate-300">
                                            Full field
                                        </label>
                                        @unless ($this->invertFullField)
                                            <label class="flex items-center gap-1 text-xs font-semibold text-slate-700">
                                                Top
                                                <input type="number" min="1" max="50" wire:model.live="invertTop" class="w-14 rounded border border-slate-300 px-1.5 py-1 text-xs">
                                            </label>
                                        @endunless
                                        <button type="button" wire:click="invertFromLive({{ $race->id }})" class="rounded bg-slate-900 px-2.5 py-1 text-xs font-bold text-white hover:bg-slate-700">Confirm invert</button>
                                        <button type="button" wire:click="closeInvert" class="rounded px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</button>
                                    </div>
                                    @error('invertTop') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                    @if ($this->invertPreview)
                                        <ol class="grid grid-cols-2 gap-1 text-xs">
                                            @foreach ($this->invertPreview['restart'] as $index => $entry)
                                                <li class="flex items-center gap-2 bg-slate-50 px-2 py-1 ring-1 ring-slate-200">
                                                    <span class="font-mono font-black">{{ $index + 1 }}</span>
                                                    <span class="font-mono">{{ $entry->competitor->car_number }}</span>
                                                    <span class="truncate">{{ $entry->competitor->full_name }}</span>
                                                </li>
                                            @endforeach
                                        </ol>
                                        @if ($this->invertPreview['unmatched'] > 0)
                                            <p class="text-xs text-slate-500">{{ $this->invertPreview['unmatched'] }} from the live board did not match.</p>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        @endif
                        @if ($race->entries->isEmpty())
                            <p class="px-3 py-4 text-sm text-slate-400">No drivers in this race.</p>
                        @else
                            <div class="grid grid-cols-2">
                                <div class="bg-slate-100">
                                    <p class="bg-slate-800 px-3 py-1.5 text-xs font-black tracking-wide text-white uppercase">{{ $race->isInverted() ? 'Restart' : 'Lineup' }}</p>
                                    <div class="flex flex-col gap-1 p-1.5">
                                        @foreach ($race->isInverted() ? $race->entries->sortBy('invert_start_position') : $race->entries->sortBy('start_position') as $entry)
                                            @php($lineupSpot = $race->isInverted() ? $entry->invert_start_position : $entry->start_position)
                                            @if ($entry->finish_position === null)
                                                <button
                                                    type="button"
                                                    wire:key="lineup-{{ $entry->id }}-{{ $version }}"
                                                    wire:click="scoreNext({{ $entry->id }})"
                                                    class="flex w-full items-center gap-2 bg-white px-2 py-2 text-left text-sm ring-1 ring-slate-300 hover:bg-sky-50 hover:ring-sky-500"
                                                >
                                                    <span class="flex size-6 shrink-0 items-center justify-center bg-slate-800 font-mono text-xs font-black text-white">{{ $lineupSpot }}</span>
                                                    <span class="w-10 shrink-0 bg-slate-900 py-0.5 text-center font-mono text-xs font-black text-white">{{ filled($entry->competitor->car_number) ? $entry->competitor->car_number : '—' }}</span>
                                                    <span class="min-w-0 truncate font-semibold">{{ $entry->competitor->full_name }}</span>
                                                </button>
                                            @else
                                                <div wire:key="lineup-{{ $entry->id }}-{{ $version }}" class="flex items-center gap-2 bg-slate-200/80 px-2 py-2 text-sm text-slate-400">
                                                    <span class="flex size-6 shrink-0 items-center justify-center bg-slate-300 font-mono text-xs font-black text-white">{{ $lineupSpot }}</span>
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
