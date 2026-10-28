<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['race_id', 'competitor_id', 'car_id', 'start_position', 'finish_position', 'source_race_id'])]
class RaceEntry extends Model
{
    /**
     * @return BelongsTo<Race, $this>
     */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /**
     * @return BelongsTo<Competitor, $this>
     */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    /**
     * @return BelongsTo<Car, $this>
     */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /**
     * Race this driver transferred from, when the seat was filled by Calculate.
     *
     * @return BelongsTo<Race, $this>
     */
    public function sourceRace(): BelongsTo
    {
        return $this->belongsTo(Race::class, 'source_race_id');
    }

    /**
     * Positions gained (positive) or lost from start to finish.
     */
    public function charge(): ?int
    {
        if ($this->start_position === null || $this->finish_position === null) {
            return null;
        }

        return (int) $this->start_position - (int) $this->finish_position;
    }

    /**
     * Charge for display, e.g. "+3", "-2", or empty when unchanged.
     */
    public function formattedCharge(): string
    {
        $charge = $this->charge();

        if ($charge === null || $charge === 0) {
            return '';
        }

        return sprintf('%+d', $charge);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_position' => 'integer',
            'finish_position' => 'integer',
            'source_race_id' => 'integer',
        ];
    }
}
