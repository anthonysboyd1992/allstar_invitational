<?php

namespace App\Models;

use Database\Factories\VmixGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['vmix_section_id', 'name', 'sort_order'])]
class VmixGroup extends Model
{
    /** @use HasFactory<VmixGroupFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<VmixSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(VmixSection::class, 'vmix_section_id');
    }

    /**
     * @return HasMany<VmixField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(VmixField::class)->orderBy('sort_order')->orderBy('id');
    }
}
