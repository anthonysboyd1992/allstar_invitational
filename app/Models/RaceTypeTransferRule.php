<?php

namespace App\Models;

use Database\Factories\RaceTypeTransferRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['race_type_id', 'position_from', 'position_to', 'destination_race_type_id', 'distribute', 'destination_start_slot', 'sort_order'])]
class RaceTypeTransferRule extends Model
{
    /** @use HasFactory<RaceTypeTransferRuleFactory> */
    use HasFactory;

    public const DistributeAppend = 'append';

    public const DistributeStagger = 'stagger';

    /**
     * @return BelongsTo<RaceType, $this>
     */
    public function raceType(): BelongsTo
    {
        return $this->belongsTo(RaceType::class);
    }

    /**
     * @return BelongsTo<RaceType, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(RaceType::class, 'destination_race_type_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'position_from' => 'integer',
            'position_to' => 'integer',
            'destination_start_slot' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
