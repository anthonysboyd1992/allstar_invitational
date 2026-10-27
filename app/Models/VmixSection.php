<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\VmixSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A template (is_template) defines groups and fields; its values are the defaults for new items.
 * A run-of-show item (template_id set) mirrors its template's structure and holds its own values.
 * A standalone section (neither) has its own structure, values and key in the master file.
 * A break (break_seconds set) is a run-of-show entry that counts down on the operator screen and is not in the master file.
 */
#[Fillable(['template_id', 'is_template', 'name', 'key', 'on_air', 'on_air_at', 'sort_order', 'show_order', 'break_seconds'])]
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
            'is_template' => 'boolean',
            'on_air' => 'boolean',
            'on_air_at' => 'datetime',
            'break_seconds' => 'integer',
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
     * @return BelongsTo<VmixSection, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(VmixSection::class, 'template_id');
    }

    /**
     * @return HasMany<VmixSection, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(VmixSection::class, 'template_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<VmixSection>  $query
     */
    public function scopeTemplates(Builder $query): void
    {
        $query->where('is_template', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<VmixSection>  $query
     */
    public function scopeStandalone(Builder $query): void
    {
        $query->whereNull('template_id')->where('is_template', false)->whereNull('break_seconds')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<VmixSection>  $query
     */
    /**
     * Template items and standalone sections in the run of show, in show order.
     *
     * @param  Builder<VmixSection>  $query
     */
    public function scopeRunOfShow(Builder $query): void
    {
        $query->whereNotNull('show_order')->orderBy('show_order')->orderBy('id');
    }

    public function isTemplate(): bool
    {
        return $this->is_template;
    }

    public function isItem(): bool
    {
        return $this->template_id !== null;
    }

    public function isBreak(): bool
    {
        return $this->break_seconds !== null;
    }

    /**
     * When an on-air break's countdown reaches zero.
     */
    public function breakEndsAt(): ?CarbonInterface
    {
        return $this->isBreak() && $this->on_air && $this->on_air_at ? $this->on_air_at->copy()->addSeconds($this->break_seconds) : null;
    }

    /**
     * Take every on-air break whose countdown has run out off air, and put the run-of-show entry after it on air
     * (if it isn't already) as the current entry.
     */
    public static function finishExpiredBreaks(): void
    {
        $expired = static::query()
            ->whereNotNull('break_seconds')
            ->where('on_air', true)
            ->get()
            ->filter(fn (VmixSection $break) => $break->breakEndsAt()?->lessThanOrEqualTo(now()));

        foreach ($expired as $break) {
            if (! static::query()->whereKey($break->id)->where('on_air', true)->update(['on_air' => false]) || ! $break->isInRunOfShow()) {
                continue;
            }

            $entries = static::query()->runOfShow()->get()->values();
            $next = $entries->get($entries->search(fn (VmixSection $entry) => $entry->id === $break->id) + 1);

            if ($next) {
                if (! $next->on_air) {
                    $next->putOnAir();
                }

                Setting::put(Setting::ShowCurrentItem, (string) $next->id);
            }
        }
    }

    /**
     * A length in seconds as m:ss, e.g. 330 is "5:30".
     */
    public static function formatSeconds(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * Add a break to the end of the run of show, named like "Break 2".
     */
    public static function createBreak(int $seconds): VmixSection
    {
        $number = 1;

        while (static::query()->where('name', "Break {$number}")->exists()) {
            $number++;
        }

        return static::query()->create([
            'name' => "Break {$number}",
            'key' => static::uniqueKeyFor("Break {$number}"),
            'break_seconds' => $seconds,
            'show_order' => static::nextShowOrder(),
        ]);
    }

    /**
     * What kind of run-of-show entry this is, e.g. the template name, "Break · 5:00" or "Section · stays live".
     */
    public function runOfShowLabel(): string
    {
        return match (true) {
            $this->isItem() => $this->template->name,
            $this->isBreak() => 'Break · '.static::formatSeconds($this->break_seconds),
            default => 'Section · stays live',
        };
    }

    public function isInRunOfShow(): bool
    {
        return $this->show_order !== null;
    }

    /**
     * The next free run-of-show position.
     */
    public static function nextShowOrder(): int
    {
        return (int) static::query()->max('show_order') + 1;
    }

    /**
     * Create a run-of-show item with this template's structure and default values.
     */
    public function createItem(string $name): VmixSection
    {
        $item = static::query()->create([
            'template_id' => $this->id,
            'name' => $name,
            'key' => static::uniqueKeyFor($name),
            'sort_order' => (int) static::query()->whereNotNull('template_id')->max('sort_order') + 1,
            'show_order' => static::nextShowOrder(),
        ]);

        $this->groups()->with('fields')->get()->each(fn (VmixGroup $group) => $item->mirrorGroup($group));

        return $item;
    }

    /**
     * A name like "Interview 3" that isn't taken yet.
     */
    public function nextItemName(): string
    {
        $number = $this->items()->count() + 1;

        while (static::query()->where('name', "{$this->name} {$number}")->exists()) {
            $number++;
        }

        return "{$this->name} {$number}";
    }

    /**
     * Copy a template group (and its fields) into this item.
     */
    public function mirrorGroup(VmixGroup $templateGroup): VmixGroup
    {
        $group = $this->groups()->create([
            'template_group_id' => $templateGroup->id,
            'name' => $templateGroup->name,
            'sort_order' => $templateGroup->sort_order,
        ]);

        $templateGroup->fields->each(fn (VmixField $field) => $group->mirrorField($field));

        return $group;
    }

    /**
     * Put this section on air. Only one item per template is on air, since they share the template's key in the master file.
     */
    public function putOnAir(): void
    {
        if ($this->isItem()) {
            static::query()->where('template_id', $this->template_id)->whereKeyNot($this->id)->update(['on_air' => false]);
        }

        $this->update(['on_air' => true, 'on_air_at' => now()]);
    }

    /**
     * Delete this section with every image file its fields (and, for a template, its items' fields) use.
     */
    public function deleteWithFiles(): void
    {
        $this->items->each->deleteWithFiles();
        $this->groups->flatMap->fields->each->deleteImageFiles();
        $this->delete();
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
