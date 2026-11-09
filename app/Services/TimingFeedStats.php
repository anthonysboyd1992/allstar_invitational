<?php

namespace App\Services;

use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\Setting;
use App\Models\TimingFeedResult;
use Illuminate\Support\Collection;

class TimingFeedStats
{
    /**
     * Fastest laps, hard charger, passes, transfer line, and lap counts for a feed session.
     *
     * @param  Collection<int, array{position: int, number: string, transponder: string, registration: string, laps: ?int, total_time: string, first_name: string, last_name: string, last_lap: string, best_lap: string, start_position: int, passes: int}>  $standings
     * @return array{matched: bool, laps: ?int, race_laps: ?int, transfer_after: ?int, fastest_race: ?array{label: string, time: string, speed: string, number: string}, fastest_day: ?array{label: string, time: string, speed: string, number: string, session: string}, hard_charger: ?array{label: string, gain: int, number: string}, hard_charger_day: ?array{label: string, gain: int, number: string, session: string}, most_passes: ?array{label: string, passes: int, number: string}}
     */
    public function summarize(TimingFeedResult $session, Collection $standings): array
    {
        $race = $this->matchedRace($session);
        $standings = $this->withLineupStats($standings, $race);
        $all = TimingFeedResult::query()->visible()->orderBy('id')->get();
        $fastestRace = $this->fastestIn($standings);
        $hardCharger = $race === null ? null : $this->hardChargerIn($standings);

        return [
            'matched' => $race !== null,
            'laps' => $this->leaderLaps($standings),
            'race_laps' => $this->raceLaps($session, $standings, $race),
            'transfer_after' => $this->transferAfter($race),
            'fastest_race' => $fastestRace,
            'fastest_day' => $this->distinctSessionHighlight($this->fastestOfDay($all, $session), $session->name),
            'hard_charger' => $hardCharger,
            'hard_charger_day' => $this->distinctDayHighlight(
                $this->hardChargerOfDay($all, $session, $race),
                $hardCharger,
                $session->name,
            ),
            'most_passes' => $this->mostPassesOfDay($all),
        ];
    }

    public function matchedRace(TimingFeedResult $session): ?Race
    {
        $relations = ['entries.competitor', 'raceType.transferRules'];
        $attached = Race::query()
            ->with($relations)
            ->where('live_feed_id', $session->id)
            ->first();

        if ($attached !== null) {
            return $attached;
        }

        $name = trim($session->name);

        if ($name === '') {
            return null;
        }

        return Race::query()
            ->with($relations)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * Starting spots and net passes come from our lineup, not Race Manager.
     *
     * @param  Collection<int, array<string, mixed>>  $standings
     * @return Collection<int, array<string, mixed>>
     */
    public function withLineupStats(Collection $standings, ?Race $race): Collection
    {
        return $standings->map(function (array $row) use ($race): array {
            $entry = $race === null ? null : $this->matchingEntry($race, $row);
            $start = (int) ($entry?->start_position ?? 0);
            $position = (int) ($row['position'] ?? 0);
            $charge = $entry?->liveCharge($position);

            $row['start_position'] = $start;
            $row['charge'] = $charge ?? 0;
            $row['passes'] = $charge !== null && $charge > 0 ? $charge : 0;

            return $row;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $standings
     * @return array{label: string, time: string, speed: string, number: string}|null
     */
    public function fastestIn(Collection $standings): ?array
    {
        $best = null;
        $bestMs = null;

        foreach ($standings as $row) {
            $ms = $this->lapMs((string) ($row['best_lap'] ?? ''));

            if ($ms === null || ($bestMs !== null && $ms >= $bestMs)) {
                continue;
            }

            $bestMs = $ms;
            $best = $this->lapHighlight($row, $this->formatLap((string) $row['best_lap']));
        }

        return $best;
    }

    /**
     * @param  Collection<int, TimingFeedResult>  $sessions
     * @return array{label: string, time: string, speed: string, number: string, session: string}|null
     */
    public function fastestOfDay(Collection $sessions, TimingFeedResult $current): ?array
    {
        $best = null;
        $bestMs = null;

        foreach ($sessions as $session) {
            foreach ($session->orderedStandings() as $row) {
                $ms = $this->lapMs((string) ($row['best_lap'] ?? ''));

                if ($ms === null || ($bestMs !== null && $ms >= $bestMs)) {
                    continue;
                }

                $bestMs = $ms;
                $best = $this->lapHighlight($row, $this->formatLap((string) $row['best_lap'])) + [
                    'session' => $session->name,
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        if ($best['session'] === $current->name) {
            return $best;
        }

        return $best;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $standings
     * @return array{label: string, gain: int, number: string}|null
     */
    public function hardChargerIn(Collection $standings): ?array
    {
        $best = null;
        $bestGain = 0;

        foreach ($standings as $row) {
            $start = (int) ($row['start_position'] ?? 0);
            $position = (int) ($row['position'] ?? 0);
            $gain = array_key_exists('charge', $row)
                ? (int) $row['charge']
                : ($start > 0 && $position > 0 ? $start - $position : 0);

            if ($gain <= $bestGain) {
                continue;
            }

            $bestGain = $gain;
            $best = [
                'label' => $this->driverLabel($row),
                'gain' => $gain,
                'number' => $this->carNumber($row),
            ];
        }

        return $best;
    }

    /**
     * @param  Collection<int, TimingFeedResult>  $sessions
     * @return array{label: string, gain: int, number: string, session: string}|null
     */
    public function hardChargerOfDay(Collection $sessions, TimingFeedResult $current, ?Race $currentRace): ?array
    {
        $best = null;
        $bestGain = 0;

        foreach ($sessions as $session) {
            $race = $session->is($current) ? $currentRace : $this->matchedRace($session);

            if ($race === null) {
                continue;
            }

            $standings = $this->withLineupStats($session->orderedStandings(), $race);
            $charger = $this->hardChargerIn($standings);

            if ($charger === null || $charger['gain'] <= $bestGain) {
                continue;
            }

            $bestGain = $charger['gain'];
            $best = $charger + ['session' => $session->name];
        }

        return $best;
    }

    /**
     * @param  Collection<int, TimingFeedResult>  $sessions
     * @return array{label: string, passes: int, number: string}|null
     */
    public function mostPassesOfDay(Collection $sessions): ?array
    {
        $totals = [];

        foreach ($sessions as $session) {
            $race = $this->matchedRace($session);

            if ($race === null) {
                continue;
            }

            foreach ($this->withLineupStats($session->orderedStandings(), $race) as $row) {
                $passes = (int) ($row['passes'] ?? 0);

                if ($passes < 1) {
                    continue;
                }

                $key = $this->driverKey($row);

                if (! isset($totals[$key])) {
                    $totals[$key] = [
                        'passes' => 0,
                        'row' => $row,
                    ];
                }

                $totals[$key]['passes'] += $passes;
                $totals[$key]['row'] = $row;
            }
        }

        $best = collect($totals)->sortByDesc('passes')->first();

        if ($best === null || $best['passes'] < 1) {
            return null;
        }

        return [
            'label' => $this->driverLabel($best['row']),
            'passes' => $best['passes'],
            'number' => $this->carNumber($best['row']),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $standings
     */
    public function leaderLaps(Collection $standings): ?int
    {
        $laps = $standings
            ->map(fn (array $row): int => (int) ($row['laps'] ?? 0))
            ->filter(fn (int $laps): bool => $laps > 0);

        return $laps->isEmpty() ? null : $laps->max();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $standings
     */
    public function raceLaps(TimingFeedResult $session, Collection $standings, ?Race $race): ?int
    {
        if ($race?->laps) {
            return (int) $race->laps;
        }

        if ($session->race_laps) {
            return (int) $session->race_laps;
        }

        $leader = $this->leaderLaps($standings);

        if ($leader && $session->laps_to_go !== null) {
            return $leader + (int) $session->laps_to_go;
        }

        return null;
    }

    /**
     * Diff is time behind the leader. Gap is time behind the car ahead.
     * Speed is last-lap mph from Settings track length.
     *
     * @param  Collection<int, array<string, mixed>>  $standings
     * @return Collection<int, array<string, mixed>>
     */
    public function withIntervals(Collection $standings): Collection
    {
        $rows = $standings->values();
        $leader = $rows->first();
        $previous = null;
        $miles = (float) Setting::trackLength();

        return $rows->map(function (array $row) use ($leader, $miles, &$previous): array {
            $row['last_lap'] = $this->usableLap((string) ($row['last_lap'] ?? ''));
            $row['best_lap'] = $this->fastestLapTime((string) ($row['best_lap'] ?? ''), $row['last_lap']);
            $row['differential'] = $previous === null ? '' : $this->intervalBehind($row, $leader);
            $row['gap'] = $this->intervalBehind($row, $previous);
            $row['speed'] = $this->speedMph($row['last_lap'], $miles);
            $previous = $row;

            return $row;
        });
    }

    /**
     * Keep each car’s last valid lap times when Race Manager sends 0s.
     *
     * @param  Collection<int, array<string, mixed>>  $standings
     * @param  Collection<int, array<string, mixed>>  $previous
     * @return Collection<int, array<string, mixed>>
     */
    public function withTrackedLaps(Collection $standings, Collection $previous): Collection
    {
        $previousByKey = $previous->keyBy(fn (array $row): string => $this->driverKey($row));

        return $standings->map(function (array $row) use ($previousByKey): array {
            $prior = $previousByKey->get($this->driverKey($row), []);
            $last = $this->usableLap((string) ($row['last_lap'] ?? ''))
                ?: $this->usableLap((string) ($prior['last_lap'] ?? ''));
            $row['last_lap'] = $last;
            $row['best_lap'] = $this->fastestLapTime(
                (string) ($row['best_lap'] ?? ''),
                $last,
                (string) ($prior['best_lap'] ?? ''),
            );

            return $row;
        });
    }

    public function usableLap(string $time): string
    {
        return $this->lapMs($time) === null ? '' : $time;
    }

    public function fastestLapTime(string ...$times): string
    {
        $best = '';
        $bestMs = null;

        foreach ($times as $time) {
            $ms = $this->lapMs($time);

            if ($ms === null || ($bestMs !== null && $ms >= $bestMs)) {
                continue;
            }

            $bestMs = $ms;
            $best = $time;
        }

        return $best;
    }

    /**
     * Miles per hour for one lap, using the configured track length.
     */
    public function speedMph(string $lastLap, ?float $miles = null): string
    {
        $ms = $this->lapMs($lastLap);
        $miles ??= (float) Setting::trackLength();

        if ($ms === null || $miles <= 0) {
            return '';
        }

        return number_format($miles / ($ms / 3_600_000), 3, '.', '');
    }

    public function transferAfter(?Race $race): ?int
    {
        if ($race === null) {
            return null;
        }

        $fromRules = $race->raceType?->transferLinePosition();

        if ($fromRules !== null) {
            return $fromRules;
        }

        if (preg_match('/top\s+(\d+)/i', (string) $race->transfers, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    public function lapMs(string $time): ?int
    {
        $ms = $this->clockMs($time);

        if ($ms === null || $ms < 1000 || $ms > 180000) {
            return null;
        }

        return $ms;
    }

    /**
     * @param  array<string, mixed>|null  $ahead
     * @param  array<string, mixed>  $row
     */
    private function intervalBehind(array $row, ?array $ahead): string
    {
        if ($ahead === null) {
            return '';
        }

        $aheadLaps = (int) ($ahead['laps'] ?? 0);
        $rowLaps = (int) ($row['laps'] ?? 0);

        if ($aheadLaps > 0 && $rowLaps > 0 && $rowLaps < $aheadLaps) {
            $down = $aheadLaps - $rowLaps;

            return $down === 1 ? '1 lap' : $down.' laps';
        }

        $aheadMs = $this->clockMs((string) ($ahead['total_time'] ?? ''));
        $rowMs = $this->clockMs((string) ($row['total_time'] ?? ''));

        if ($aheadMs === null || $rowMs === null || $rowMs < $aheadMs) {
            return '';
        }

        return $this->formatInterval($rowMs - $aheadMs);
    }

    public function clockMs(string $time): ?int
    {
        $time = trim($time);

        if ($time === '' || $time === '--') {
            return null;
        }

        if (preg_match('/^(?:(\d+):)?(?:(\d+):)?(\d+(?:\.\d+)?)$/', $time, $match) !== 1) {
            return null;
        }

        $seconds = (float) $match[3];
        $minutes = 0;
        $hours = 0;

        if (($match[1] ?? '') !== '' && ($match[2] ?? '') !== '') {
            $hours = (int) $match[1];
            $minutes = (int) $match[2];
        } elseif (($match[1] ?? '') !== '') {
            $minutes = (int) $match[1];
        }

        return (int) round((($hours * 3600) + ($minutes * 60) + $seconds) * 1000);
    }

    public function formatInterval(int $milliseconds): string
    {
        $minutes = intdiv($milliseconds, 60000);
        $seconds = intdiv($milliseconds % 60000, 1000);
        $fraction = $milliseconds % 1000;

        if ($minutes > 0) {
            return sprintf('+%d:%02d.%03d', $minutes, $seconds, $fraction);
        }

        return sprintf('+%d.%03d', $seconds, $fraction);
    }

    public function formatLap(string $time): string
    {
        $ms = $this->lapMs($time);

        if ($ms === null) {
            return $time;
        }

        $minutes = intdiv($ms, 60000);
        $seconds = intdiv($ms % 60000, 1000);
        $frac = $ms % 1000;

        if ($minutes > 0) {
            return sprintf('%d:%02d.%03d', $minutes, $seconds, $frac);
        }

        return sprintf('%d.%03d', $seconds, $frac);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function driverLabel(array $row): string
    {
        $number = $this->carNumber($row);
        $name = trim((string) ($row['first_name'] ?? '').' '.(string) ($row['last_name'] ?? ''));
        $hash = $number !== '' ? '#'.$number : '';

        return trim($hash.' '.$name);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function carNumber(array $row): string
    {
        $number = trim((string) ($row['number'] ?? ''));

        return $number !== '' ? $number : trim((string) ($row['registration'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function driverKey(array $row): string
    {
        $transponder = strtolower(trim((string) ($row['transponder'] ?? '')));

        if ($transponder !== '') {
            return 't:'.$transponder;
        }

        $number = strtolower($this->carNumber($row));

        return $number !== '' ? 'n:'.$number : 'r:'.strtolower(trim((string) ($row['registration'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{label: string, time: string, speed: string, number: string}
     */
    private function lapHighlight(array $row, string $time): array
    {
        return [
            'label' => $this->driverLabel($row),
            'time' => $time,
            'speed' => $this->speedMph($time),
            'number' => $this->carNumber($row),
        ];
    }

    /**
     * @param  array{label: string, gain: int, number: string, session: string}|null  $day
     * @param  array{label: string, gain: int, number: string}|null  $race
     * @return array{label: string, gain: int, number: string, session: string}|null
     */
    private function distinctDayHighlight(?array $day, ?array $race, string $sessionName): ?array
    {
        if ($day === null) {
            return null;
        }

        if ($race !== null && $day['number'] === $race['number'] && $day['gain'] === $race['gain'] && $day['session'] === $sessionName) {
            return null;
        }

        return $day;
    }

    /**
     * @template T of array{session: string}
     *
     * @param  T|null  $highlight
     * @return T|null
     */
    private function distinctSessionHighlight(?array $highlight, string $sessionName): ?array
    {
        if ($highlight === null || $highlight['session'] === $sessionName) {
            return null;
        }

        return $highlight;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchingEntry(Race $race, array $row): ?RaceEntry
    {
        return $race->entries->first(function (RaceEntry $entry) use ($row): bool {
            $competitor = $entry->competitor;

            if ($competitor === null) {
                return false;
            }

            if (($row['transponder'] ?? '') !== '' && strcasecmp((string) $competitor->transponder, (string) $row['transponder']) === 0) {
                return true;
            }

            return ($row['number'] ?? '') !== '' && strcasecmp((string) $competitor->car_number, (string) $row['number']) === 0;
        });
    }
}
