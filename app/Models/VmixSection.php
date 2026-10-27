<?php

namespace App\Models;

use Database\Factories\VmixSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'key', 'on_air', 'sort_order'])]
class VmixSection extends Model
{
    /** @use HasFactory<VmixSectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_air' => 'boolean',
        ];
    }

    /**
     * @return HasMany<VmixGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(VmixGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Turn a name like "Track Facts" into a unique JSON key like "track_facts".
     */
    public static function uniqueKeyFor(string $name, ?int $ignoreId = null): string
    {
        $base = Str::snake(Str::of($name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', ' ')->trim()->lower()->value()) ?: 'section';
        $key = $base;
        $suffix = 2;

        while (static::query()->where('key', $key)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    /**
     * Every field from every group in this section as one flat row, in group then field order.
     *
     * @return array<string, string>
     */
    public function data(): array
    {
        $this->loadMissing('groups.fields');

        $fields = $this->groups->flatMap->fields;
        $driverIds = $fields->filter->isDriver()->pluck('live_value')->filter()->map(fn ($id) => (int) $id)->unique();
        $drivers = $driverIds->isEmpty() ? collect() : Competitor::query()->whereKey($driverIds)->get()->keyBy('id');

        return $fields
            ->flatMap(fn (VmixField $field) => $field->feedValues($drivers))
            ->all();
    }

    /**
     * Whether a field key is already used anywhere in this section.
     */
    public function hasFieldKey(string $key, ?int $ignoreFieldId = null): bool
    {
        return VmixField::query()
            ->where('key', $key)
            ->whereHas('group', fn ($query) => $query->where('vmix_section_id', $this->id))
            ->when($ignoreFieldId, fn ($query) => $query->whereKeyNot($ignoreFieldId))
            ->exists();
    }
}
