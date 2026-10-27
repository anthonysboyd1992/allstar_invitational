<?php

namespace App\Models;

use Database\Factories\RaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['race_type_id', 'name', 'laps', 'transfers', 'sponsor', 'sort_order'])]
class Race extends Model
{
    /** @use HasFactory<RaceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<RaceType, $this>
     */
    public function raceType(): BelongsTo
    {
        return $this->belongsTo(RaceType::class);
    }

    /**
     * @return HasMany<RaceEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(RaceEntry::class)->orderBy('start_position');
    }

    /**
     * @return list<int>
     */
    public function lineupIds(): array
    {
        return $this->entries()->pluck('competitor_id')->all();
    }

    /**
     * Replace the lineup with the given competitors, in starting order.
     *
     * @param  list<int>  $competitorIds
     */
    public function syncLineup(array $competitorIds): void
    {
        $competitorIds = array_values(array_unique(array_map('intval', $competitorIds)));

        DB::transaction(function () use ($competitorIds): void {
            $this->entries()->whereNotIn('competitor_id', $competitorIds)->delete();

            foreach ($competitorIds as $index => $competitorId) {
                $this->entries()->updateOrCreate(
                    ['competitor_id' => $competitorId],
                    ['start_position' => $index + 1],
                );
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'laps' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
