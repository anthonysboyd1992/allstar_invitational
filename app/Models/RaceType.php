<?php

namespace App\Models;

use Database\Factories\RaceTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order'])]
class RaceType extends Model
{
    /** @use HasFactory<RaceTypeFactory> */
    use HasFactory;

    /**
     * @return HasMany<Race, $this>
     */
    public function races(): HasMany
    {
        return $this->hasMany(Race::class)->orderBy('sort_order')->orderBy('id');
    }
}
