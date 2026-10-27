<?php

namespace App\Models;

use Database\Factories\VmixGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['vmix_section_id', 'template_group_id', 'name', 'sort_order'])]
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

    /**
     * The same group in every run-of-show item made from this template group.
     *
     * @return HasMany<VmixGroup, $this>
     */
    public function copies(): HasMany
    {
        return $this->hasMany(VmixGroup::class, 'template_group_id');
    }

    /**
     * Copy a template field into this item group, starting from the template's default value.
     */
    public function mirrorField(VmixField $templateField): VmixField
    {
        $value = $templateField->isImage() ? $templateField->duplicateImageFiles() : ['value' => $templateField->value, 'original_path' => null];

        return $this->fields()->create([
            'template_field_id' => $templateField->id,
            'key' => $templateField->key,
            'type' => $templateField->type,
            'value' => $value['value'],
            'live_value' => $value['value'],
            'original_path' => $value['original_path'],
            'needs_update' => $templateField->needs_update,
            'width' => $templateField->width,
            'height' => $templateField->height,
            'sort_order' => $templateField->sort_order,
        ]);
    }

    /**
     * Delete this group, its copies in every item, and their image files.
     */
    public function deleteWithCopies(): void
    {
        $this->copies->each->deleteWithCopies();
        $this->fields->each->deleteImageFiles();
        $this->delete();
    }
}
