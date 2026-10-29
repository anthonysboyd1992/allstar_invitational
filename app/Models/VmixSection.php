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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A template (is_template) defines groups and fields; its values are the defaults for new items.
 * A run-of-show item (template_id set) mirrors its template's structure and holds its own values.
 * A standalone section (neither) has its own structure, values and key in the master file.
 * A break (break_seconds set) is a run-of-show entry that counts down on the operator screen and is not in the master file.
 * A placeholder (is_placeholder) is a named run-of-show slot with no fields; it is not in the master file.
 * Allotted seconds start counting when the entry becomes current and advance the show when they run out.
 */
#[Fillable(['template_id', 'is_template', 'is_placeholder', 'name', 'description', 'key', 'on_air', 'on_air_at', 'sort_order', 'show_order', 'break_seconds', 'allotted_seconds'])]
class VmixSection extends Model
{
    /** @use HasFactory<VmixSectionFactory> */
    use HasFactory;

    public const DescriptionLineMax = 200;

    public const DescriptionLineLimit = 20;

    public const DefaultAllottedSeconds = 60;

    /**
     * Operator bump sizes for the current timer, in seconds.
     *
     * @var array<int, array{label: string, phrase: string}>
     */
    public const TimerBumps = [
        30 => ['label' => '30s', 'phrase' => '30 seconds'],
        60 => ['label' => '1m', 'phrase' => '1 minute'],
        300 => ['label' => '5m', 'phrase' => '5 minutes'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_template' => 'boolean',
            'is_placeholder' => 'boolean',
            'on_air' => 'boolean',
            'on_air_at' => 'datetime',
            'break_seconds' => 'integer',
            'allotted_seconds' => 'integer',
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
        $query->whereNull('template_id')->where('is_template', false)->where('is_placeholder', false)->whereNull('break_seconds')->orderBy('sort_order')->orderBy('id');
    }

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

    public function isPlaceholder(): bool
    {
        return $this->is_placeholder;
    }

    /**
     * Operator notes as a list of lines. An empty description is an empty list.
     *
     * @return list<string>
     */
    public function descriptionLines(): array
    {
        if (! filled($this->description)) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $this->description) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Seconds this entry should stay current. Breaks use their length; anything else without a time is 60 seconds.
     */
    public function allottedSeconds(): int
    {
        return $this->allotted_seconds ?? $this->break_seconds ?? self::DefaultAllottedSeconds;
    }

    /**
     * @return array{minutes: int, seconds: int}
     */
    public static function splitSeconds(int $total): array
    {
        return [
            'minutes' => intdiv($total, 60),
            'seconds' => $total % 60,
        ];
    }

    /**
     * When the current entry's allotted countdown reaches zero.
     */
    public function timerEndsAt(): ?CarbonInterface
    {
        return $this->on_air_at ? $this->on_air_at->copy()->addSeconds($this->allottedSeconds()) : null;
    }

    /**
     * When an on-air break's countdown reaches zero.
     */
    public function breakEndsAt(): ?CarbonInterface
    {
        return $this->isBreak() && $this->on_air ? $this->timerEndsAt() : null;
    }

    public function timerHasEnded(): bool
    {
        return $this->timerEndsAt()?->lessThanOrEqualTo(now()) ?? false;
    }

    /**
     * Stamp the allotted countdown. Placeholders stay off air; everything else goes on air.
     */
    public function startAllottedTimer(): void
    {
        if ($this->isPlaceholder()) {
            $this->update(['on_air_at' => now()]);

            return;
        }

        $this->putOnAir();
    }

    /**
     * Advance the current run-of-show entry when its allotted time has run out, and put the next entry on.
     */
    public static function finishExpiredBreaks(): void
    {
        for ($step = 0; $step < 50; $step++) {
            $currentId = Setting::get(Setting::ShowCurrentItem);
            $current = $currentId ? static::query()->find((int) $currentId) : null;

            if (! $current?->isInRunOfShow() || ! $current->timerHasEnded()) {
                break;
            }

            $entries = static::query()->runOfShow()->get()->values();
            $index = $entries->search(fn (VmixSection $entry): bool => $entry->id === $current->id);
            $next = $index === false ? null : $entries->get($index + 1);

            if (! $next) {
                $current->update(['on_air_at' => null]);

                return;
            }

            static::advanceCurrentTo($next, $current);
        }

        $expired = static::query()
            ->whereNotNull('break_seconds')
            ->where('on_air', true)
            ->get()
            ->filter(fn (VmixSection $break): bool => $break->breakEndsAt()?->lessThanOrEqualTo(now()) ?? false);

        foreach ($expired as $break) {
            if (! static::query()->whereKey($break->id)->where('on_air', true)->update(['on_air' => false]) || ! $break->isInRunOfShow()) {
                continue;
            }

            $entries = static::query()->runOfShow()->get()->values();
            $next = $entries->get($entries->search(fn (VmixSection $entry): bool => $entry->id === $break->id) + 1);

            if ($next) {
                static::advanceCurrentTo($next, $break);
            }
        }
    }

    /**
     * Make this entry current. Template items and breaks that were current go off air; standalone sections stay live.
     */
    public static function advanceCurrentTo(VmixSection $next, ?VmixSection $current): void
    {
        if ($current && $current->id !== $next->id && ($current->isItem() || $current->isBreak())) {
            static::query()->whereKey($current->id)->update(['on_air' => false]);
            $current->on_air = false;
        }

        $next->startAllottedTimer();
        Setting::put(Setting::ShowCurrentItem, (string) $next->id);
    }

    /**
     * A length in seconds as m:ss, e.g. 330 is "5:30".
     */
    public static function formatSeconds(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * A remaining show length as m:ss, or h:mm:ss when at least an hour. Overtime is prefixed with +.
     */
    public static function formatShowDuration(int $seconds): string
    {
        $over = $seconds < 0;
        $seconds = abs($seconds);
        $formatted = $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60)
            : static::formatSeconds($seconds);

        return $over ? '+'.$formatted : $formatted;
    }

    /**
     * Allotted seconds of every run-of-show entry after the current one, or the full planned length when nothing is current.
     *
     * @param  Collection<int, self>  $runOfShow
     */
    public static function allottedSecondsAfter(?self $current, Collection $runOfShow): int
    {
        $entries = $runOfShow->values();

        if ($current === null) {
            return (int) $entries->sum(fn (self $entry): int => $entry->allottedSeconds());
        }

        $index = $entries->search(fn (self $entry): bool => $entry->id === $current->id);

        if ($index === false) {
            return (int) $entries->sum(fn (self $entry): int => $entry->allottedSeconds());
        }

        return (int) $entries->slice($index + 1)->sum(fn (self $entry): int => $entry->allottedSeconds());
    }

    /**
     * Seconds left until the last run-of-show entry ends: leftover on the current entry plus allotted times after it.
     * With nothing current, this is the full planned length. After the last item finishes, this is 0.
     *
     * @param  Collection<int, self>  $runOfShow
     */
    public static function remainingUntilEndOfShow(?self $current, Collection $runOfShow): int
    {
        $laterSeconds = static::allottedSecondsAfter($current, $runOfShow);

        if ($current === null || $current->timerEndsAt() === null) {
            return $laterSeconds;
        }

        return $current->timerEndsAt()->getTimestamp() - now()->getTimestamp() + $laterSeconds;
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
            'allotted_seconds' => $seconds,
            'show_order' => static::nextShowOrder(),
        ]);
    }

    /**
     * Add a named slot to the end of the run of show, with no fields.
     */
    public static function createPlaceholder(string $name, ?int $seconds = null): VmixSection
    {
        return static::query()->create([
            'is_placeholder' => true,
            'name' => $name,
            'key' => static::uniqueKeyFor($name),
            'allotted_seconds' => $seconds,
            'show_order' => static::nextShowOrder(),
        ]);
    }

    /**
     * A name like "Placeholder 2" that isn't taken yet.
     */
    public static function nextPlaceholderName(): string
    {
        $number = 1;

        while (static::query()->where('name', "Placeholder {$number}")->exists()) {
            $number++;
        }

        return "Placeholder {$number}";
    }

    /**
     * What kind of run-of-show entry this is, e.g. the template name, "Break · 5:00" or "Section · stays live".
     */
    public function runOfShowLabel(): string
    {
        $kind = match (true) {
            $this->isItem() => $this->template->name,
            $this->isBreak() => 'Break',
            $this->isPlaceholder() => 'Placeholder',
            default => 'Section · stays live',
        };

        return $kind.' · '.static::formatSeconds($this->allottedSeconds());
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
        $raceId = (int) Setting::get(Setting::ActiveRace);
        $drivers = $driverIds->isEmpty() ? collect() : Competitor::query()
            ->whereKey($driverIds)
            ->when($raceId > 0, fn ($query) => $query->with([
                'entries' => fn ($entries) => $entries->where('race_id', $raceId)->with('car'),
            ]))
            ->get()
            ->keyBy('id');

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
