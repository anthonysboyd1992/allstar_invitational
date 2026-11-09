<?php

namespace App\Models;

use App\TimingStatus;
use Database\Factories\TimingFeedResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['source_key', 'name', 'flag', 'laps_to_go', 'race_laps', 'is_live', 'standings', 'applied_to_race_id', 'applied_at', 'ignored_at'])]
class TimingFeedResult extends Model
{
    /** @use HasFactory<TimingFeedResultFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Race, $this>
     */
    public function appliedToRace(): BelongsTo
    {
        return $this->belongsTo(Race::class, 'applied_to_race_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereNull('applied_at')->latest();
    }

    /**
     * Sessions that still belong on Live Timing and the Results picker.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull('ignored_at')->whereNull('applied_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('ignored_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIgnored(Builder $query): Builder
    {
        return $query->whereNotNull('ignored_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->available()->where('is_live', true);
    }

    public function isAvailable(): bool
    {
        return $this->ignored_at === null && $this->applied_at === null;
    }

    /**
     * Hide this session from Live Timing and the Results picker.
     */
    public function ignore(): void
    {
        $this->update([
            'ignored_at' => now(),
            'is_live' => false,
        ]);
    }

    /**
     * The puller stopped listening, so no session is live.
     */
    public static function clearLive(): void
    {
        static::query()->where('is_live', true)->update(['is_live' => false]);
    }

    /**
     * Only one feed session can be live at a time.
     */
    public function markLive(bool $live): void
    {
        if ($live) {
            static::query()->whereKeyNot($this->id)->where('is_live', true)->update(['is_live' => false]);
        }

        if ($this->is_live !== $live) {
            $this->update(['is_live' => $live]);
        }
    }

    /**
     * Map feed standings onto this race's lineup by transponder, then car number.
     *
     * @return array{applied: int, unmatched: int}
     */
    public function applyTo(Race $race): array
    {
        $race->loadMissing('entries.competitor');

        $available = $race->entries->values();
        $matched = new Collection;

        foreach ($this->orderedStandings() as $standing) {
            $entry = $this->matchEntry($available, $standing);

            if ($entry === null) {
                continue;
            }

            $available = $available->reject(fn (RaceEntry $row): bool => $row->id === $entry->id)->values();
            $matched->push($entry);
        }

        if ($matched->isNotEmpty()) {
            DB::transaction(function () use ($race, $matched): void {
                foreach ($race->entries as $entry) {
                    $entry->update(['finish_position' => null]);
                }

                foreach ($matched->values() as $index => $entry) {
                    $entry->update(['finish_position' => $index + 1]);
                }

                $race->setTimingStatus(TimingStatus::Complete);

                $this->update([
                    'applied_to_race_id' => $race->id,
                    'applied_at' => now(),
                    'is_live' => false,
                ]);
            });
        }

        return [
            'applied' => $matched->count(),
            'unmatched' => max(0, $this->orderedStandings()->count() - $matched->count()),
        ];
    }

    /**
     * @return Collection<int, array{position: int, number: string, transponder: string, registration: string, laps: ?int, total_time: string, first_name: string, last_name: string, last_lap: string, best_lap: string, start_position: int, passes: int}>
     */
    public function orderedStandings(): Collection
    {
        return collect($this->standings)
            ->map(fn (mixed $row): array => [
                'position' => (int) data_get($row, 'position', 0),
                'number' => trim((string) data_get($row, 'number', '')),
                'transponder' => trim((string) data_get($row, 'transponder', '')),
                'registration' => trim((string) data_get($row, 'registration', '')),
                'laps' => data_get($row, 'laps') === null || data_get($row, 'laps') === '' ? null : (int) data_get($row, 'laps'),
                'total_time' => trim((string) data_get($row, 'total_time', '')),
                'first_name' => trim((string) data_get($row, 'first_name', '')),
                'last_name' => trim((string) data_get($row, 'last_name', '')),
                'last_lap' => trim((string) data_get($row, 'last_lap', '')),
                'best_lap' => trim((string) data_get($row, 'best_lap', '')),
                'start_position' => (int) data_get($row, 'start_position', 0),
                'passes' => (int) data_get($row, 'passes', 0),
            ])
            ->filter(fn (array $row): bool => $row['position'] > 0 || $row['number'] !== '' || $row['transponder'] !== '' || $row['registration'] !== '')
            ->sortBy(fn (array $row): int => $row['position'] > 0 ? $row['position'] : 1000)
            ->values();
    }

    public function label(): string
    {
        $count = $this->orderedStandings()->count();
        $flag = filled($this->flag) ? ' · '.$this->flag : '';
        $used = $this->applied_at ? ' (used)' : '';

        return $this->name.$flag.' · '.$count.' car'.($count === 1 ? '' : 's').$used;
    }

    /**
     * @param  Collection<int, RaceEntry>  $entries
     * @param  array{position: int, number: string, transponder: string, registration: string, laps: ?int, total_time: string, first_name: string, last_name: string, last_lap: string, best_lap: string, start_position: int, passes: int}  $standing
     */
    private function matchEntry(Collection $entries, array $standing): ?RaceEntry
    {
        if ($standing['transponder'] !== '') {
            $match = $entries->first(
                fn (RaceEntry $entry): bool => strcasecmp((string) $entry->competitor->transponder, $standing['transponder']) === 0,
            );

            if ($match !== null) {
                return $match;
            }
        }

        if ($standing['number'] !== '') {
            return $entries->first(
                fn (RaceEntry $entry): bool => strcasecmp((string) $entry->competitor->car_number, $standing['number']) === 0,
            );
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'standings' => 'array',
            'is_live' => 'boolean',
            'applied_at' => 'datetime',
            'ignored_at' => 'datetime',
        ];
    }
}
