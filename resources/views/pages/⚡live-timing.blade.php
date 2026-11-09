<?php

use App\Models\Competitor;
use App\Models\TimingFeedResult;
use App\Services\TimingFeedStats;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Live Timing')] class extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $board = [];

    public function mount(): void
    {
        $this->refreshBoard();
    }

    public function refreshBoard(): void
    {
        $session = TimingFeedResult::query()->live()->latest('updated_at')->first()
            ?? TimingFeedResult::query()->available()->latest('updated_at')->first();

        if ($session === null) {
            $this->board = [];

            return;
        }

        $stamp = $session->updated_at?->getTimestamp() ?? 0;

        if (
            ($this->board['id'] ?? null) === $session->id
            && ($this->board['stamp'] ?? null) === $stamp
            && ($this->board['is_live'] ?? null) === $session->is_live
        ) {
            return;
        }

        $standings = (new TimingFeedStats)->withIntervals($this->standingsFor($session));

        $this->board = array_merge([
            'id' => $session->id,
            'name' => $session->name,
            'flag' => $session->flag,
            'is_live' => $session->is_live,
            'updated' => $session->updated_at?->diffForHumans(),
            'stamp' => $stamp,
            'standings' => $standings->all(),
        ], (new TimingFeedStats)->summarize($session, $standings));
    }

    /**
     * @return Collection<int, array{position: int, number: string, transponder: string, registration: string, laps: ?int, total_time: string, first_name: string, last_name: string, last_lap: string, best_lap: string, start_position: int, passes: int}>
     */
    public function standingsFor(TimingFeedResult $session): Collection
    {
        $standings = $session->orderedStandings();
        $needsNames = $standings->contains(fn (array $row): bool => trim($row['first_name'].' '.$row['last_name']) === '');

        if (! $needsNames) {
            return $standings;
        }

        $competitors = Competitor::query()->get();

        return $standings->map(function (array $row) use ($competitors): array {
            if (trim($row['first_name'].' '.$row['last_name']) !== '') {
                return $row;
            }

            $match = $competitors->first(function (Competitor $competitor) use ($row): bool {
                if ($row['transponder'] !== '' && strcasecmp((string) $competitor->transponder, $row['transponder']) === 0) {
                    return true;
                }

                return $row['number'] !== '' && strcasecmp((string) $competitor->car_number, $row['number']) === 0;
            });

            if ($match === null) {
                return $row;
            }

            $row['first_name'] = (string) $match->first_name;
            $row['last_name'] = (string) $match->last_name;

            if ($row['number'] === '') {
                $row['number'] = (string) $match->car_number;
            }

            return $row;
        });
    }

    /**
     * @return Collection<int, TimingFeedResult>
     */
    #[Computed]
    public function recentSessions(): Collection
    {
        return TimingFeedResult::query()
            ->available()
            ->latest('updated_at')
            ->limit(20)
            ->get();
    }

    public function deleteSession(int $sessionId): void
    {
        $session = TimingFeedResult::query()->available()->findOrFail($sessionId);
        $session->ignore();
        unset($this->recentSessions);
        $this->board = [];
        $this->refreshBoard();
        $this->dispatch('notify', message: 'Deleted '.$session->name.'.');
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
};
?>

<div class="mx-auto max-w-7xl space-y-6 px-1 py-1 sm:px-2" wire:poll.500ms="refreshBoard">
    @if (($board['id'] ?? null) === null)
        <div class="rounded-lg bg-zinc-950 px-10 py-16 text-center text-zinc-100 ring-1 ring-zinc-800">
            <p class="text-[11px] font-semibold tracking-[0.28em] text-zinc-500 uppercase">Live Timing</p>
            <h1 class="mt-3 text-2xl font-semibold tracking-tight">Waiting for Race Manager</h1>
            <p class="mt-4 text-sm text-zinc-400">Copy this site’s Timing File Feed URL into the puller, press Listen, and leave this page open.</p>
        </div>
    @else
        <div
            x-data="timingFullscreen()"
            x-on:fullscreenchange.document="sync()"
            x-on:webkitfullscreenchange.document="sync()"
            x-on:keydown.escape.window="if (full) exit()"
            class="timing-tower flex flex-col overflow-hidden rounded-lg bg-zinc-950 text-zinc-100 shadow-2xl ring-1 ring-zinc-800"
            wire:key="live-session-{{ $board['id'] }}"
        >
            <div class="flex flex-wrap items-center gap-4 border-b border-white/10 bg-black px-5 py-4 sm:px-6">
                @if (filled($board['flag']))
                    <span @class(['shrink-0 rounded px-2.5 py-1 text-[11px] font-bold tracking-[0.18em] uppercase', $this->flagClasses($board['flag'])])>
                        {{ $board['flag'] }}
                    </span>
                @endif
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-lg font-semibold tracking-wide uppercase">{{ $board['name'] }}</h1>
                    <p class="mt-0.5 text-[11px] tracking-wide text-zinc-500 uppercase">{{ count($board['standings']) }} {{ count($board['standings']) === 1 ? 'car' : 'cars' }}</p>
                </div>
                @if (($board['laps'] ?? null) && ($board['race_laps'] ?? null))
                    <span class="shrink-0 font-mono text-2xl font-semibold tracking-tight text-amber-400">{{ $board['laps'] }}<span class="text-zinc-500">/{{ $board['race_laps'] }}</span></span>
                @elseif ($board['laps'] ?? null)
                    <span class="shrink-0 font-mono text-2xl font-semibold tracking-tight text-amber-400">{{ $board['laps'] }}</span>
                @endif
                @if ($board['is_live'])
                    <span class="inline-flex shrink-0 items-center gap-2 rounded bg-red-600 px-2.5 py-1 text-[10px] font-bold tracking-[0.2em] text-white uppercase">
                        <span class="size-1.5 animate-pulse rounded-full bg-white"></span>
                        Live
                    </span>
                @else
                    <span class="shrink-0 rounded bg-zinc-800 px-2.5 py-1 text-[10px] font-bold tracking-[0.2em] text-zinc-400 uppercase">Idle</span>
                @endif
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
                    wire:click="deleteSession({{ $board['id'] }})"
                    class="shrink-0 rounded px-2.5 py-1 text-[11px] font-semibold text-zinc-400 ring-1 ring-zinc-700 hover:bg-zinc-800 hover:text-white"
                    x-show="! full"
                >Delete</button>
            </div>

            @if (($board['fastest_race'] ?? null) || ($board['fastest_day'] ?? null) || ($board['hard_charger'] ?? null) || ($board['hard_charger_day'] ?? null) || ($board['most_passes'] ?? null) || ($board['transfer_after'] ?? null))
                <div class="flex flex-wrap gap-x-8 gap-y-3 border-b border-white/10 bg-zinc-900 px-5 py-3 sm:px-6">
                    @if ($board['fastest_race'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-amber-400 uppercase">Fastest lap</p>
                            <p class="mt-1 text-sm font-medium">{{ $board['fastest_race']['label'] }} <span class="inline-flex rounded bg-black px-1.5 py-0.5 font-mono text-amber-400">{{ $board['fastest_race']['time'] }}{{ ($board['fastest_race']['speed'] ?? '') !== '' ? ' · '.$board['fastest_race']['speed'].' mph' : '' }}</span></p>
                        </div>
                    @endif
                    @if ($board['fastest_day'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Fastest today</p>
                            <p class="mt-1 text-sm font-medium">{{ $board['fastest_day']['label'] }} <span class="font-mono text-zinc-200">{{ $board['fastest_day']['time'] }}{{ ($board['fastest_day']['speed'] ?? '') !== '' ? ' · '.$board['fastest_day']['speed'].' mph' : '' }}</span></p>
                            <p class="mt-0.5 text-[11px] text-zinc-500">{{ $board['fastest_day']['session'] }}</p>
                        </div>
                    @endif
                    @if ($board['hard_charger'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Hard charger</p>
                            <p class="mt-1 text-sm font-medium">{{ $board['hard_charger']['label'] }} <span class="font-mono text-emerald-400">+{{ $board['hard_charger']['gain'] }}</span></p>
                        </div>
                    @endif
                    @if ($board['hard_charger_day'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Hard charger today</p>
                            <p class="mt-1 text-sm font-medium">{{ $board['hard_charger_day']['label'] }} <span class="font-mono text-emerald-400">+{{ $board['hard_charger_day']['gain'] }}</span></p>
                            <p class="mt-0.5 text-[11px] text-zinc-500">{{ $board['hard_charger_day']['session'] }}</p>
                        </div>
                    @endif
                    @if ($board['most_passes'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Most passes today</p>
                            <p class="mt-1 text-sm font-medium">{{ $board['most_passes']['label'] }} <span class="font-mono">{{ $board['most_passes']['passes'] }}</span></p>
                        </div>
                    @endif
                    @if ($board['transfer_after'] ?? null)
                        <div>
                            <p class="text-[10px] font-semibold tracking-[0.18em] text-zinc-500 uppercase">Transfer</p>
                            <p class="mt-1 text-sm font-medium">Top {{ $board['transfer_after'] }}</p>
                        </div>
                    @endif
                </div>
            @endif

            <div class="timing-tower-table min-h-0 flex-1 overflow-y-auto">
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
                    @forelse ($board['standings'] as $index => $row)
                        @php
                            $carNumber = $row['number'] !== '' ? $row['number'] : $row['registration'];
                            $isFastest = ($board['fastest_race']['number'] ?? null) === $carNumber;
                            $isPersonalBest = ($row['last_lap'] ?? '') !== '' && ($row['best_lap'] ?? '') !== '' && $row['last_lap'] === $row['best_lap'];
                            $next = $board['standings'][$index + 1] ?? null;
                            $cutoff = $board['transfer_after'] ?? null;
                            $showTransfer = $cutoff !== null
                                && (int) $row['position'] > 0
                                && (int) $row['position'] <= $cutoff
                                && ($next === null || (int) $next['position'] > $cutoff);
                        @endphp
                        <tr
                            wire:key="standing-{{ $board['id'] }}-{{ $row['position'] }}-{{ $row['transponder'] }}-{{ $row['number'] }}"
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
                                @if ($row['laps'] !== null && ($board['race_laps'] ?? null))
                                    {{ $row['laps'] }}/{{ $board['race_laps'] }}
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
                            <tr wire:key="transfer-line-{{ $board['id'] }}-{{ $cutoff }}">
                                <td colspan="9" class="bg-red-600 py-1.5 text-center text-[10px] font-bold tracking-[0.28em] text-white uppercase">Transfer · Top {{ $cutoff }}</td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-14 text-center text-zinc-500">No cars in this session yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    @endif

    @if ($this->recentSessions->isNotEmpty())
        <div class="rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-6 py-4 sm:px-8">
                <h2 class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Sessions</h2>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach ($this->recentSessions as $session)
                    @php($cars = $session->orderedStandings()->count())
                    <li wire:key="recent-session-{{ $session->id }}" class="flex flex-wrap items-center gap-4 px-6 py-4 sm:px-8">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-bold">{{ $session->name }}</p>
                            <p class="mt-0.5 text-sm text-slate-500">{{ $cars }} {{ $cars === 1 ? 'car' : 'cars' }}@if (filled($session->flag)) · {{ $session->flag }}@endif</p>
                        </div>
                        @if (($board['id'] ?? null) === $session->id)
                            <span class="shrink-0 rounded-full bg-slate-900 px-3 py-1 text-[10px] font-black uppercase tracking-wide text-white">Showing</span>
                        @endif
                        @if ($session->is_live)
                            <span class="shrink-0 rounded-full bg-red-600 px-3 py-1 text-[10px] font-black uppercase tracking-wide text-white">Live</span>
                        @endif
                        <button
                            type="button"
                            wire:click="deleteSession({{ $session->id }})"
                            class="shrink-0 rounded-md px-3 py-1.5 text-xs font-bold text-slate-600 ring-1 ring-slate-300 hover:bg-slate-50 hover:text-slate-900"
                        >Delete</button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
