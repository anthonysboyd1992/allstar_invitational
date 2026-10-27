<?php

use App\Models\Competitor;
use App\Models\Setting;
use App\Models\VmixField;
use App\Models\VmixGroup;
use App\Models\VmixSection;
use App\Services\VmixFeed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('vMix Data')] class extends Component
{
    use WithFileUploads;

    public const FieldKeyPattern = '/^[A-Za-z0-9_.\-]+$/';

    public const ImageRules = ['image', 'mimes:png,jpg,jpeg,gif,webp', 'max:10240'];

    #[Url(as: 'section')]
    public ?int $selectedSectionId = null;

    public ?int $expandedSectionId = null;

    public string $newSectionName = '';

    public string $newStandaloneName = '';

    public string $newItemTemplateId = '';

    public string $newItemName = '';

    public string $newBreakSeconds = '300';

    public string $newPlaceholderName = '';

    public string $breakSeconds = '';

    public string $newGroupName = '';

    /** @var array<int, array{key?: string, value?: string}> */
    public array $newField = [];

    public string $sectionName = '';

    /** @var list<string> */
    public array $sectionDescriptionLines = [];

    public string $sectionKey = '';

    /** @var array<int, string> */
    public array $groupNames = [];

    /** @var array<int, string> */
    public array $fieldKeys = [];

    /** @var array<int, string> */
    public array $fieldValues = [];

    /** @var array<int, string> */
    public array $fieldWidths = [];

    /** @var array<int, string> */
    public array $fieldHeights = [];

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $uploads = [];

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $headshotUploads = [];

    public function mount(): void
    {
        VmixSection::finishExpiredBreaks();

        if (! $this->selectedSectionId || ! VmixSection::query()->whereKey($this->selectedSectionId)->exists()) {
            $this->selectedSectionId = $this->firstSectionId();
        }

        $this->loadSection();
    }

    /**
     * Refill every bound input from the database so the screen always shows what is saved.
     */
    public function loadSection(): void
    {
        unset($this->section, $this->templates, $this->runOfShow, $this->standaloneSections, $this->liveBreak, $this->currentItemId, $this->currentEntry, $this->upNext);
        $section = $this->section;

        $this->sectionName = (string) $section?->name;
        $this->sectionDescriptionLines = $this->descriptionLinesForEditor($section);
        $this->sectionKey = (string) $section?->key;
        $this->breakSeconds = (string) $section?->break_seconds;
        $this->groupNames = $section?->groups->mapWithKeys(fn (VmixGroup $group) => [$group->id => $group->name])->all() ?? [];

        $fields = $section?->groups->flatMap->fields ?? collect();
        $this->fieldKeys = $fields->mapWithKeys(fn (VmixField $field) => [$field->id => $field->key])->all();
        $this->fieldValues = $fields->mapWithKeys(fn (VmixField $field) => [$field->id => (string) $field->value])->all();
        $this->fieldWidths = $fields->mapWithKeys(fn (VmixField $field) => [$field->id => (string) $field->width])->all();
        $this->fieldHeights = $fields->mapWithKeys(fn (VmixField $field) => [$field->id => (string) $field->height])->all();
        $this->uploads = [];
    }

    /**
     * @return Collection<int, VmixSection>
     */
    #[Computed]
    public function templates(): Collection
    {
        return VmixSection::query()->templates()->withCount('items')->get();
    }

    /**
     * @return Collection<int, VmixSection>
     */
    #[Computed]
    public function standaloneSections(): Collection
    {
        return VmixSection::query()->standalone()->get();
    }

    /**
     * @return Collection<int, VmixSection>
     */
    #[Computed]
    public function runOfShow(): Collection
    {
        return VmixSection::query()->runOfShow()->with('template')->get();
    }

    /**
     * The break counting down on the operator screen: the most recently started on-air break.
     */
    #[Computed]
    public function liveBreak(): ?VmixSection
    {
        return VmixSection::query()->whereNotNull('break_seconds')->where('on_air', true)->latest('on_air_at')->first();
    }

    #[Computed]
    public function currentItemId(): ?int
    {
        return ($id = Setting::get(Setting::ShowCurrentItem)) ? (int) $id : null;
    }

    #[Computed]
    public function currentEntry(): ?VmixSection
    {
        return $this->runOfShow->firstWhere('id', $this->currentItemId);
    }

    /**
     * The run-of-show entry Next will put on air: the one after the current entry, or the first if nothing is current.
     */
    #[Computed]
    public function upNext(): ?VmixSection
    {
        $entries = $this->runOfShow->values();
        $currentIndex = $entries->search(fn (VmixSection $entry) => $entry->id === $this->currentItemId);

        return $entries->get($currentIndex === false ? 0 : $currentIndex + 1);
    }

    #[Computed]
    public function drivers(): Collection
    {
        return Competitor::query()->orderBy('last_name')->orderBy('first_name')->get(['id', 'car_number', 'first_name', 'last_name', 'headshot_path']);
    }

    #[Computed]
    public function section(): ?VmixSection
    {
        return $this->selectedSectionId
            ? VmixSection::query()->with(['groups.fields', 'template'])->withCount('items')->find($this->selectedSectionId)
            : null;
    }

    public function selectSection(int $sectionId): void
    {
        if ($this->expandedSectionId !== $sectionId) {
            $this->expandedSectionId = null;
        }

        $this->selectedSectionId = $sectionId;
        $this->newField = [];
        $this->resetValidation();
        $this->loadSection();
    }

    /**
     * Open or close this run-of-show entry's accordion. Only the arrow should call this.
     */
    public function toggleSection(int $sectionId): void
    {
        if ($this->expandedSectionId === $sectionId) {
            $this->expandedSectionId = null;

            return;
        }

        $this->expandedSectionId = $sectionId;
        $this->selectSection($sectionId);
    }

    public function addTemplate(): void
    {
        $this->newSectionName = trim($this->newSectionName);

        $this->validate(
            ['newSectionName' => ['required', 'string', 'max:100', 'unique:vmix_sections,name']],
            ['newSectionName.unique' => 'That name is already used by a template or item.'],
            ['newSectionName' => 'template name'],
        );

        $name = $this->newSectionName;
        $template = VmixSection::query()->create([
            'is_template' => true,
            'name' => $name,
            'key' => VmixSection::uniqueKeyFor($name),
            'sort_order' => (int) VmixSection::query()->where('is_template', true)->max('sort_order') + 1,
        ]);

        $this->reset('newSectionName');
        $this->selectSection($template->id);
    }

    /**
     * Add a standalone section: its own structure, values and key in the master file.
     */
    public function addSection(): void
    {
        $this->newStandaloneName = trim($this->newStandaloneName);

        $this->validate(
            ['newStandaloneName' => ['required', 'string', 'max:100', 'unique:vmix_sections,name']],
            ['newStandaloneName.unique' => 'That name is already used by a section, template or item.'],
            ['newStandaloneName' => 'section name'],
        );

        $name = $this->newStandaloneName;
        $section = VmixSection::query()->create([
            'name' => $name,
            'key' => VmixSection::uniqueKeyFor($name),
            'sort_order' => (int) VmixSection::query()->standalone()->max('sort_order') + 1,
        ]);

        $this->reset('newStandaloneName');
        $this->selectSection($section->id);
    }

    /**
     * Add to the run of show: a new item from a template (e.g. "Interview 3", the name is optional),
     * or an existing standalone section when the picked value is "section:{id}".
     */
    public function addItem(): void
    {
        if (str_starts_with($this->newItemTemplateId, 'section:')) {
            $section = VmixSection::query()->standalone()->findOrFail((int) substr($this->newItemTemplateId, 8));
            $section->update(['show_order' => VmixSection::nextShowOrder()]);

            $this->reset('newItemTemplateId', 'newItemName');
            $this->resetValidation('newItemName');
            $this->selectSection($section->id);

            return;
        }

        $template = VmixSection::query()->templates()->find((int) $this->newItemTemplateId);

        if (! $template) {
            $this->addError('newItemName', 'Pick a template first.');

            return;
        }

        $name = trim($this->newItemName) ?: $template->nextItemName();

        if (VmixSection::query()->where('name', $name)->exists()) {
            $this->addError('newItemName', 'That name is already used by a template or item.');

            return;
        }

        $item = $template->createItem($name);

        $this->reset('newItemName');
        $this->resetValidation('newItemName');
        $this->selectSection($item->id);
    }

    /**
     * Called by the countdown when it reaches zero: end the break and put the next run-of-show entry on air.
     */
    public function finishBreak(): void
    {
        $previousItemId = $this->currentItemId;

        VmixSection::finishExpiredBreaks();
        unset($this->currentItemId);

        if ($this->currentItemId && $this->currentItemId !== $previousItemId) {
            $this->selectSection($this->currentItemId);
        } else {
            $this->loadSection();
        }
    }

    public function addBreak(): void
    {
        $this->validate(
            ['newBreakSeconds' => ['required', 'integer', 'min:1', 'max:86400']],
            attributes: ['newBreakSeconds' => 'break length'],
        );

        $break = VmixSection::createBreak((int) $this->newBreakSeconds);

        $this->selectSection($break->id);
    }

    public function addPlaceholder(): void
    {
        $this->newPlaceholderName = trim($this->newPlaceholderName) ?: VmixSection::nextPlaceholderName();

        $this->validate(
            ['newPlaceholderName' => ['required', 'string', 'max:100', 'unique:vmix_sections,name']],
            ['newPlaceholderName.unique' => 'That name is already used by a section, template or item.'],
            ['newPlaceholderName' => 'placeholder name'],
        );

        $placeholder = VmixSection::createPlaceholder($this->newPlaceholderName);

        $this->reset('newPlaceholderName');
        $this->selectSection($placeholder->id);
    }

    public function updatedBreakSeconds(string $seconds): void
    {
        $validator = Validator::make(['seconds' => $seconds], ['seconds' => ['required', 'integer', 'min:1', 'max:86400']]);

        if ($validator->fails() || ! $this->section?->isBreak()) {
            $this->dispatch('notify', message: 'The break length must be a number of seconds from 1 to 86400.', type: 'error');
        } else {
            $this->section->update(['break_seconds' => (int) $seconds]);
        }

        unset($this->liveBreak);
        $this->loadSection();
    }

    public function updatedSectionName(string $name): void
    {
        $name = trim($name);

        if (VmixSection::query()->where('name', $name)->whereKeyNot($this->selectedSectionId)->exists()) {
            $this->dispatch('notify', message: 'There is already a section with that name.', type: 'error');
        } elseif ($name !== '') {
            $this->section?->update(['name' => $name]);
        }

        $this->loadSection();
    }

    public function updatedSectionDescriptionLines(): void
    {
        $this->saveDescriptionLines();
    }

    public function addDescriptionLine(): void
    {
        if (count($this->filledDescriptionLines()) >= VmixSection::DescriptionLineLimit) {
            return;
        }

        if (trim((string) last($this->sectionDescriptionLines)) !== '') {
            $this->sectionDescriptionLines[] = '';
        }
    }

    public function removeDescriptionLine(int $index): void
    {
        if (! array_key_exists($index, $this->sectionDescriptionLines)) {
            return;
        }

        unset($this->sectionDescriptionLines[$index]);
        $this->sectionDescriptionLines = array_values($this->sectionDescriptionLines);
        $this->saveDescriptionLines();
    }

    /**
     * @return list<string>
     */
    protected function filledDescriptionLines(): array
    {
        return collect($this->sectionDescriptionLines)
            ->map(fn (mixed $line): string => trim((string) $line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    protected function descriptionLinesForEditor(?VmixSection $section): array
    {
        $lines = $section?->descriptionLines() ?? [];

        return count($lines) >= VmixSection::DescriptionLineLimit
            ? $lines
            : [...$lines, ''];
    }

    protected function saveDescriptionLines(): void
    {
        $lines = $this->filledDescriptionLines();

        $validator = Validator::make(
            ['sectionDescriptionLines' => $lines],
            [
                'sectionDescriptionLines' => ['array', 'max:'.VmixSection::DescriptionLineLimit],
                'sectionDescriptionLines.*' => ['string', 'max:'.VmixSection::DescriptionLineMax],
            ],
            attributes: ['sectionDescriptionLines' => 'description', 'sectionDescriptionLines.*' => 'description line'],
        );

        if ($validator->fails()) {
            $this->dispatch('notify', message: $validator->errors()->first(), type: 'error');
        } else {
            $this->section?->update(['description' => $lines === [] ? null : implode("\n", $lines)]);
        }

        $this->loadSection();
    }

    public function updatedSectionKey(string $key): void
    {
        $section = $this->section;
        $key = trim($key);

        $this->requireOwnStructure();

        $validator = Validator::make(['key' => $key], [
            'key' => ['required', 'max:100', 'regex:/^[A-Za-z0-9_]+$/', "unique:vmix_sections,key,{$section?->id}"],
        ], ['key.regex' => 'The JSON key can only use letters, numbers and underscores.']);

        if ($validator->fails()) {
            $this->dispatch('notify', message: $validator->errors()->first(), type: 'error');
        } else {
            $section?->update(['key' => $key]);
        }

        $this->loadSection();
    }

    /**
     * Put a standalone section or run-of-show item on air (an item takes its template's other items off) or take it off air.
     */
    public function toggleOnAir(int $sectionId): void
    {
        $section = VmixSection::query()->where('is_template', false)->findOrFail($sectionId);

        if ($section->on_air) {
            $section->update(['on_air' => false]);
        } else {
            $section->putOnAir();

            if ($section->isInRunOfShow()) {
                Setting::put(Setting::ShowCurrentItem, (string) $section->id);
            }
        }

        unset($this->runOfShow, $this->standaloneSections, $this->currentItemId, $this->section, $this->liveBreak, $this->currentEntry, $this->upNext);
    }

    /**
     * Double-click a run-of-show entry to put it on air (or make a placeholder current) and select it.
     */
    public function goLive(int $sectionId): void
    {
        $section = VmixSection::query()->where('is_template', false)->findOrFail($sectionId);
        $current = $this->currentItemId ? VmixSection::query()->find($this->currentItemId) : null;

        if ($current && $current->id !== $section->id && ($current->isItem() || $current->isBreak())) {
            $current->update(['on_air' => false]);
        }

        if (! $section->isPlaceholder()) {
            $section->putOnAir();
        }

        if ($section->isInRunOfShow()) {
            Setting::put(Setting::ShowCurrentItem, (string) $section->id);
        }

        unset($this->runOfShow, $this->standaloneSections, $this->currentItemId, $this->liveBreak, $this->currentEntry, $this->upNext);
        $this->selectSection($section->id);
    }

    public function showNext(): void
    {
        $this->step(1);
    }

    public function showPrevious(): void
    {
        $this->step(-1);
    }

    /**
     * Take a standalone section out of the run of show (it stays in the sections list and keeps its on-air state), or delete a break or placeholder.
     */
    public function removeFromRunOfShow(int $sectionId): void
    {
        $section = VmixSection::query()->whereNull('template_id')->where('is_template', false)->findOrFail($sectionId);

        if ($section->isBreak() || $section->isPlaceholder()) {
            $section->delete();

            if ($this->selectedSectionId === $section->id) {
                $this->selectedSectionId = $this->firstSectionId();
            }

            if ($this->expandedSectionId === $section->id) {
                $this->expandedSectionId = null;
            }
        } else {
            $section->update(['show_order' => null]);
        }

        unset($this->liveBreak);
        $this->loadSection();
    }

    /**
     * Put the next (or previous) run-of-show entry on air. The current entry goes off air if it is a template item or a break;
     * standalone sections stay live until they are turned off.
     */
    protected function step(int $direction): void
    {
        $items = VmixSection::query()->runOfShow()->get()->values();
        $currentIndex = $items->search(fn (VmixSection $item) => $item->id === $this->currentItemId);
        $target = $items->get($currentIndex === false ? ($direction > 0 ? 0 : $items->count() - 1) : $currentIndex + $direction);

        if (! $target) {
            $this->dispatch('notify', message: $direction > 0 ? 'That was the last item in the run of show.' : 'Already at the first item.', type: 'error');

            return;
        }

        if ($currentIndex !== false && ($items->get($currentIndex)->isItem() || $items->get($currentIndex)->isBreak())) {
            $items->get($currentIndex)->update(['on_air' => false]);
        }

        if (! $target->isPlaceholder()) {
            $target->putOnAir();
        }

        Setting::put(Setting::ShowCurrentItem, (string) $target->id);

        $this->selectSection($target->id);
    }

    public function deleteSection(): void
    {
        $this->section?->deleteWithFiles();
        $this->expandedSectionId = null;
        $this->selectedSectionId = $this->firstSectionId();
        $this->loadSection();
    }

    public function addGroup(): void
    {
        $this->requireOwnStructure();
        $this->newGroupName = trim($this->newGroupName);

        $this->validate(
            ['newGroupName' => ['required', 'string', 'max:100', "unique:vmix_groups,name,NULL,id,vmix_section_id,{$this->selectedSectionId}"]],
            ['newGroupName.unique' => 'This section already has a group with that name.'],
            ['newGroupName' => 'group name'],
        );

        $group = $this->section->groups()->create([
            'name' => $this->newGroupName,
            'sort_order' => (int) $this->section->groups()->max('sort_order') + 1,
        ]);

        $this->section->items->each(fn (VmixSection $item) => $item->mirrorGroup($group));

        $this->reset('newGroupName');
        $this->loadSection();
    }

    public function updatedGroupNames(string $name, string $groupId): void
    {
        $this->requireOwnStructure();
        $group = $this->sectionGroup((int) $groupId);
        $name = trim($name);

        if (VmixGroup::query()->where('vmix_section_id', $group->vmix_section_id)->where('name', $name)->whereKeyNot($group->id)->exists()) {
            $this->dispatch('notify', message: 'This template already has a group with that name.', type: 'error');
        } elseif ($name !== '') {
            $group->update(['name' => $name]);
            $group->copies()->update(['name' => $name]);
        }

        $this->loadSection();
    }

    /**
     * Drag-and-drop handler: reorder the run of show. Next / Previous follow this order.
     */
    /**
     * Drag-and-drop handler for the run of show. A standalone section dragged in from the sections list is added at the drop position.
     */
    public function sortItems(int|string $itemId, int $position): void
    {
        $entries = VmixSection::query()->runOfShow()->get();

        if (! $entries->contains('id', (int) $itemId)) {
            $entries->push(VmixSection::query()->standalone()->findOrFail((int) $itemId));
        }

        $this->moveToPosition($entries, (int) $itemId, $position, 'show_order');
        unset($this->standaloneSections);
        unset($this->runOfShow);
    }

    public function sortStandalone(int|string $sectionId, int $position): void
    {
        $this->moveToPosition(VmixSection::query()->standalone()->get(), (int) $sectionId, $position);
        unset($this->standaloneSections);
    }

    public function sortTemplates(int|string $templateId, int $position): void
    {
        $this->moveToPosition(VmixSection::query()->templates()->get(), (int) $templateId, $position);
        unset($this->templates);
    }

    /**
     * Drag-and-drop handler: move a group within the selected template. Its items follow.
     */
    public function sortGroups(int|string $groupId, int $position): void
    {
        $this->requireOwnStructure();
        $this->sectionGroup((int) $groupId);
        $this->moveToPosition($this->section->groups()->orderBy('sort_order')->orderBy('id')->get(), (int) $groupId, $position);
        $this->section->groups()->get()->each(fn (VmixGroup $group) => $group->copies()->update(['sort_order' => $group->sort_order]));
        $this->loadSection();
    }

    public function deleteGroup(int $groupId): void
    {
        $this->requireOwnStructure();
        $this->sectionGroup($groupId)->deleteWithCopies();
        $this->loadSection();
    }

    /**
     * Field names are unique across the whole section because every group is output together in one flat row.
     */
    public function addField(int $groupId): void
    {
        $this->requireOwnStructure();
        $group = $this->sectionGroup($groupId);
        $input = $this->newField[$groupId] ?? [];
        $key = trim($input['key'] ?? '');

        $type = $input['type'] ?? VmixField::TypeText;
        $isImage = $type === VmixField::TypeImage;
        $sizeRules = ['exclude_unless:type,'.VmixField::TypeImage, 'required', 'integer', 'min:1', 'max:'.VmixField::MaxDimension];

        $validator = Validator::make(
            [
                'key' => $key,
                'type' => $type,
                'value' => $input['value'] ?? null,
                'width' => filled($input['width'] ?? null) ? $input['width'] : VmixField::DefaultWidth,
                'height' => filled($input['height'] ?? null) ? $input['height'] : VmixField::DefaultHeight,
            ],
            [
                'key' => [
                    'required', 'max:100', 'regex:'.self::FieldKeyPattern,
                    fn (string $attribute, mixed $value, Closure $fail) => $this->section->hasFieldKey($key) ? $fail('This section already has a field with that name.') : null,
                ],
                'type' => ['required', 'in:'.implode(',', array_keys(VmixField::Types))],
                'value' => [
                    'nullable', 'string', 'max:5000',
                    ...($type === VmixField::TypeDriver ? ['exists:competitors,id'] : []),
                    ...($type === VmixField::TypeColor ? ['regex:'.VmixField::ColorPattern] : []),
                ],
                'width' => $sizeRules,
                'height' => $sizeRules,
            ],
            ['key.regex' => 'Field names can only use letters, numbers, _ . and -', 'value.regex' => 'Colors must be a hex value like #FF0000.'],
            ['key' => 'field name'],
        );

        if ($validator->fails()) {
            $this->addError("newField.{$groupId}.key", $validator->errors()->first());

            return;
        }

        $value = $isImage || ($input['value'] ?? '') === '' ? null : $input['value'];
        $value = $type === VmixField::TypeColor ? strtoupper($value ?? VmixField::DefaultColor) : $value;
        $needsUpdate = in_array($type, [VmixField::TypeImage, VmixField::TypeDriver], true);

        $field = $group->fields()->create([
            'key' => $key,
            'type' => $type,
            'value' => $value,
            'live_value' => $needsUpdate ? null : $value,
            'needs_update' => $needsUpdate,
            'width' => $isImage ? (int) $validator->validated()['width'] : null,
            'height' => $isImage ? (int) $validator->validated()['height'] : null,
            'sort_order' => (int) $group->fields()->max('sort_order') + 1,
        ]);

        $group->copies->each(fn (VmixGroup $copy) => $copy->mirrorField($field));

        unset($this->newField[$groupId]);
        $this->resetValidation("newField.{$groupId}.key");
        $this->loadSection();
    }

    public function updatedFieldValues(?string $value, string $fieldId): void
    {
        $field = $this->sectionField((int) $fieldId);
        $value = ($value ?? '') === '' ? null : $value;

        if ($field->isDriver() && $value !== null && ! Competitor::query()->whereKey((int) $value)->exists()) {
            $this->dispatch('notify', message: 'That driver no longer exists.', type: 'error');
        } elseif ($field->isColor() && $value !== null && ! preg_match(VmixField::ColorPattern, $value)) {
            $this->dispatch('notify', message: 'Colors must be a hex value like #FF0000.', type: 'error');
        } elseif (! $field->isImage()) {
            $field->saveValue($field->isColor() && $value !== null ? strtoupper($value) : $value);
        }

        unset($this->section);
        $this->fieldValues[$field->id] = (string) $field->refresh()->value;
    }

    public function updatedFieldWidths(string $width, string $fieldId): void
    {
        $this->resizeImageField((int) $fieldId);
    }

    public function updatedFieldHeights(string $height, string $fieldId): void
    {
        $this->resizeImageField((int) $fieldId);
    }

    /**
     * Save the new size and re-crop the original upload to it.
     */
    protected function resizeImageField(int $fieldId): void
    {
        $this->requireOwnStructure();
        $field = $this->sectionField($fieldId);
        $size = ['width' => $this->fieldWidths[$fieldId] ?? '', 'height' => $this->fieldHeights[$fieldId] ?? ''];
        $rule = ['required', 'integer', 'min:1', 'max:'.VmixField::MaxDimension];

        $validator = Validator::make($size, ['width' => $rule, 'height' => $rule]);

        if ($validator->fails() || ! $field->isImage()) {
            $this->dispatch('notify', message: $validator->errors()->first() ?: 'Only image fields have a size.', type: 'error');
        } elseif ((int) $size['width'] !== $field->width || (int) $size['height'] !== $field->height) {
            try {
                $field->resize((int) $size['width'], (int) $size['height']);
                $field->copies->each->resize((int) $size['width'], (int) $size['height']);
            } catch (RuntimeException $exception) {
                $this->dispatch('notify', message: $exception->getMessage(), type: 'error');
            }
        }

        $this->loadSection();
    }

    public function updatedUploads(mixed $upload, string $fieldId): void
    {
        $field = $this->sectionField((int) $fieldId);

        $validator = Validator::make(['image' => $upload], ['image' => ['required', ...self::ImageRules]], attributes: ['image' => 'image']);

        if ($validator->fails() || ! $field->isImage()) {
            $this->dispatch('notify', message: $validator->errors()->first() ?: 'That field is not an image field.', type: 'error');
            $this->loadSection();

            return;
        }

        try {
            $field->storeImage($upload);
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');
        }

        $this->loadSection();
    }

    /**
     * Upload a headshot for a driver picked in this section. It is saved on the competitor, so it changes everywhere.
     */
    public function updatedHeadshotUploads(mixed $photo, string $competitorId): void
    {
        $isPickedInSection = $this->section?->groups->flatMap->fields
            ->contains(fn (VmixField $field) => $field->isDriver() && in_array((int) $competitorId, [(int) $field->value, (int) $field->live_value], true));

        abort_unless($isPickedInSection, 404);

        if ($error = Competitor::query()->findOrFail((int) $competitorId)->replaceHeadshot($photo)) {
            $this->dispatch('notify', message: $error, type: 'error');
        }

        $this->headshotUploads = [];
        unset($this->drivers, $this->section);
    }

    public function removeImage(int $fieldId): void
    {
        $this->sectionField($fieldId)->clearImage();

        $this->loadSection();
    }

    public function toggleNeedsUpdate(int $fieldId): void
    {
        $this->requireOwnStructure();
        $field = $this->sectionField($fieldId);
        $field->setNeedsUpdate(! $field->needs_update);
        $field->copies->each->setNeedsUpdate($field->needs_update);

        $this->loadSection();
    }

    public function pushField(int $fieldId): void
    {
        $this->sectionField($fieldId)->pushLive();
        $this->loadSection();
    }

    public function pushGroup(int $groupId): void
    {
        $this->sectionGroup($groupId)->fields->filter->isPending()->each->pushLive();
        $this->loadSection();
    }

    public function pushSection(): void
    {
        $this->section?->groups->flatMap->fields->filter->isPending()->each->pushLive();
        $this->loadSection();
    }

    public function updatedFieldKeys(string $key, string $fieldId): void
    {
        $this->requireOwnStructure();
        $field = $this->sectionField((int) $fieldId);
        $key = trim($key);

        $validator = Validator::make(['key' => $key], [
            'key' => [
                'required', 'max:100', 'regex:'.self::FieldKeyPattern,
                fn (string $attribute, mixed $value, Closure $fail) => $this->section->hasFieldKey($key, $field->id) ? $fail('This section already has a field with that name.') : null,
            ],
        ], ['key.regex' => 'Field names can only use letters, numbers, _ . and -']);

        if ($validator->fails()) {
            $this->dispatch('notify', message: $validator->errors()->first(), type: 'error');
        } else {
            $field->update(['key' => $key]);
            $field->copies()->update(['key' => $key]);
        }

        $this->loadSection();
    }

    public function deleteField(int $fieldId): void
    {
        $this->requireOwnStructure();
        $this->sectionField($fieldId)->deleteWithCopies();
        $this->loadSection();
    }

    /**
     * Renumber sort_order so the given record lands at the position, keeping everything else in order.
     *
     * @param  Collection<int, Model>  $records
     */
    protected function moveToPosition(Collection $records, int $id, int $position, string $column = 'sort_order'): void
    {
        $moving = $records->firstWhere('id', $id);

        if (! $moving) {
            return;
        }

        $records = $records->reject(fn (Model $record) => $record->id === $id)->values();
        $records->splice(max(0, min($position, $records->count())), 0, [$moving]);

        $records->each(fn (Model $record, int $index) => $record->{$column} === $index ? null : $record->update([$column => $index]));
    }

    /**
     * Structure (groups, fields, names, sizes, AUTO/MANUAL) is only edited on templates; items follow it.
     */
    /**
     * Run-of-show items take their structure from the template, so only templates and standalone sections change it.
     */
    protected function requireOwnStructure(): void
    {
        abort_if(! $this->section || $this->section->isItem() || $this->section->isBreak() || $this->section->isPlaceholder(), 403, 'Change the structure on the template.');
    }

    protected function firstSectionId(): ?int
    {
        return VmixSection::query()->standalone()->value('id')
            ?? VmixSection::query()->runOfShow()->value('id')
            ?? VmixSection::query()->templates()->value('id');
    }

    protected function sectionGroup(int $groupId): VmixGroup
    {
        return VmixGroup::query()->where('vmix_section_id', $this->selectedSectionId)->findOrFail($groupId);
    }

    protected function sectionField(int $fieldId): VmixField
    {
        return VmixField::query()
            ->whereHas('group', fn ($query) => $query->where('vmix_section_id', $this->selectedSectionId))
            ->findOrFail($fieldId);
    }
};
?>

<div class="space-y-4">
    <div class="sticky top-14 z-20">
        <div class="overflow-hidden rounded-lg bg-slate-950 text-white shadow-sm ring-1 ring-slate-800">
            <div class="flex items-center gap-2 border-b border-slate-800 px-2 py-1">
                <span class="text-[9px] font-black tracking-[0.18em] text-amber-400 uppercase">vMix</span>
                <a href="{{ route('feeds.data') }}" target="_blank" class="min-w-0 flex-1 truncate font-mono text-[11px] text-slate-400 hover:text-white">{{ route('feeds.data') }}</a>
                <button
                    type="button"
                    x-on:click="copyText(@js(route('feeds.data')), 'vMix URL')"
                    class="rounded bg-amber-400 px-1.5 py-0.5 text-[10px] font-black text-slate-900"
                >Copy</button>
            </div>
    @php($liveBreak = $this->liveBreak)
    <div
        wire:key="clock-{{ $liveBreak?->id }}-{{ $liveBreak?->breakEndsAt()?->getTimestampMs() }}"
        x-data="{
            offset: {{ now()->getTimestampMs() }} - Date.now(),
            now: Date.now(),
            endsAt: @js($liveBreak?->breakEndsAt()?->getTimestampMs()),
            get time() { return new Date(this.now + this.offset).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' }) },
            get remaining() { return Math.round((this.endsAt - this.now - this.offset) / 1000) },
            get countdown() { const s = Math.abs(this.remaining); return (this.remaining < 0 ? '+' : '') + Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') },
            finished: false,
            init() {
                this.timer = setInterval(() => {
                    this.now = Date.now();
                    if (this.endsAt && ! this.finished && this.remaining <= 0) {
                        this.finished = true;
                        $wire.finishBreak();
                    }
                }, 250);
            },
            destroy() { clearInterval(this.timer) },
        }"
            class="grid grid-cols-1 gap-1 p-1 sm:grid-cols-2 xl:grid-cols-[auto_minmax(8rem,14rem)_minmax(12rem,1fr)_minmax(10rem,16rem)]"
    >
        <div class="flex items-baseline gap-2 rounded bg-slate-900 px-2 py-1 ring-1 ring-slate-800">
            <span class="text-[9px] font-black tracking-[0.16em] text-slate-500 uppercase">Time</span>
            <span x-text="time" class="font-mono text-lg leading-none font-black tabular-nums"></span>
        </div>

        @php($currentEntry = $this->currentEntry)
        @php($upNext = $this->upNext)
        <div @class([
            'flex min-w-0 items-center gap-2 rounded px-2 py-1',
            'bg-red-600 text-white' => $currentEntry,
            'bg-slate-900 ring-1 ring-slate-800' => ! $currentEntry,
        ])>
            <span @class(['shrink-0 text-[9px] font-black tracking-[0.16em] uppercase', 'text-red-100' => $currentEntry, 'text-slate-500' => ! $currentEntry])>Now</span>
            @if ($currentEntry)
                <button type="button" wire:click="selectSection({{ $currentEntry->id }})" class="min-w-0 truncate text-left text-sm font-black hover:text-red-100">{{ $currentEntry->name }}</button>
            @else
                <span class="truncate text-sm font-black text-slate-600">Nothing current</span>
            @endif
        </div>

        <div class="flex min-w-0 items-stretch gap-1 sm:col-span-2 xl:col-span-1">
            <div class="flex min-w-0 flex-1 items-center gap-2 rounded bg-amber-400 px-2 py-1 text-slate-950">
                <span class="shrink-0 text-[9px] font-black tracking-[0.16em] uppercase">Up next</span>
                @if ($upNext)
                    <button type="button" wire:click="selectSection({{ $upNext->id }})" class="min-w-0 truncate text-left text-sm font-black">{{ $upNext->name }}</button>
                @else
                    <span class="truncate text-sm font-black text-slate-700">End of show</span>
                @endif
            </div>
            <button
                type="button"
                wire:click="showNext"
                @disabled(! $upNext)
                title="Put the next entry on air"
                class="shrink-0 rounded bg-red-600 px-2.5 text-xs font-black text-white hover:bg-red-500 disabled:cursor-not-allowed disabled:bg-slate-800 disabled:text-slate-500"
            >Next &#9654;</button>
        </div>

        @if ($liveBreak)
            <div
                class="flex min-w-0 items-center justify-between gap-2 rounded px-2 py-1 transition-colors duration-300 sm:col-span-2 xl:col-span-1"
                x-bind:class="{
                    'bg-slate-900 text-white ring-1 ring-slate-800': remaining > 60,
                    'bg-lime-400 text-slate-950': remaining <= 60 && remaining > 45,
                    'bg-yellow-300 text-slate-950': remaining <= 45 && remaining > 30,
                    'bg-yellow-400 text-slate-950': remaining <= 30 && remaining > 15,
                    'bg-orange-400 text-slate-950': remaining <= 15 && remaining > 10,
                    'bg-orange-600 text-white': remaining <= 10 && remaining > 5,
                    'bg-red-600 text-white animate-pulse': remaining <= 5,
                }"
            >
                <span class="min-w-0 truncate text-[9px] font-black tracking-[0.16em] uppercase opacity-80">{{ $liveBreak->name }} · {{ VmixSection::formatSeconds($liveBreak->break_seconds) }} <span x-text="remaining < 0 ? 'Over' : 'Remaining'" class="font-bold tracking-normal normal-case"></span></span>
                <span x-text="countdown" class="shrink-0 font-mono text-xl leading-none font-black tabular-nums"></span>
            </div>
        @else
            <div class="flex min-w-0 items-center gap-2 rounded bg-slate-900 px-2 py-1 ring-1 ring-slate-800 sm:col-span-2 xl:col-span-1">
                <span class="text-[9px] font-black tracking-[0.16em] text-slate-500 uppercase">Break</span>
                <span class="text-sm font-black text-slate-600">Idle</span>
            </div>
        @endif
    </div>
        </div>
    </div>
    <div class="flex items-start gap-4">
        <aside class="w-72 shrink-0 space-y-4">
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 p-3">
                <h2 class="text-sm font-bold tracking-wide text-slate-500 uppercase">Add to run of show</h2>
            </div>
            <form wire:submit="addItem" class="space-y-2 p-3">
                <select wire:model="newItemTemplateId" class="w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                    <option value="">Template or section…</option>
                    @if ($this->templates->isNotEmpty())
                        <optgroup label="New item from template">
                            @foreach ($this->templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if (($sectionsOutOfShow = $this->standaloneSections->reject->isInRunOfShow())->isNotEmpty())
                        <optgroup label="Section (stays live until turned off)">
                            @foreach ($sectionsOutOfShow as $standalone)
                                <option value="section:{{ $standalone->id }}">{{ $standalone->name }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
                <input wire:model="newItemName" placeholder="Item name (optional), e.g. Interview 3" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                @error('newItemName') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="addItem" class="w-full rounded-md bg-slate-900 py-1.5 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-50">Add item</button>
            </form>
            <form wire:submit="addBreak" class="border-t border-slate-200 p-3">
                <div class="flex items-center gap-2">
                    <input type="number" min="1" max="86400" wire:model="newBreakSeconds" aria-label="Break length in seconds" class="w-24 rounded-md border border-slate-300 px-2 py-1.5 text-right text-sm focus:border-amber-500 focus:outline-none">
                    <span class="text-xs text-slate-500">sec</span>
                    <button type="submit" wire:loading.attr="disabled" wire:target="addBreak" class="flex-1 rounded-md bg-slate-100 py-1.5 text-sm font-semibold hover:bg-slate-200 disabled:opacity-50">Add break</button>
                </div>
                @error('newBreakSeconds') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
            </form>
            <form wire:submit="addPlaceholder" class="border-t border-slate-200 p-3">
                <input wire:model="newPlaceholderName" placeholder="Placeholder name, e.g. Feature winner" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                @error('newPlaceholderName') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="addPlaceholder" class="mt-2 w-full rounded-md bg-slate-100 py-1.5 text-sm font-semibold hover:bg-slate-200 disabled:opacity-50">Add placeholder</button>
            </form>
        </div>

        {{-- Standalone sections --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 p-3">
                <h2 class="text-sm font-bold tracking-wide text-slate-500 uppercase">Sections</h2>
            </div>

            <nav wire:sort="sortStandalone" wire:sort:config="{ group: { name: 'run-of-show', pull: 'clone', put: false } }" class="space-y-1 p-2">
                @forelse ($this->standaloneSections as $standalone)
                    <div
                        wire:key="standalone-{{ $standalone->id }}"
                        wire:sort:item="{{ $standalone->id }}"
                        @class([
                            'flex items-center gap-1 rounded-md pr-2',
                            'bg-amber-400 text-slate-900' => $selectedSectionId === $standalone->id,
                            'hover:bg-slate-100' => $selectedSectionId !== $standalone->id,
                        ])
                    >
                        <span wire:sort:handle title="Drag to reorder" class="cursor-grab pl-1.5 text-sm text-slate-400 select-none hover:text-slate-700">&#10303;</span>
                        <button type="button" wire:click="selectSection({{ $standalone->id }})" class="min-w-0 flex-1 py-2 pr-2 pl-1 text-left">
                            <span class="block truncate text-sm font-bold">{{ $standalone->name }}</span>
                            @include('pages.vmix-description-bullets', [
                                'lines' => $standalone->descriptionLines(),
                                'class' => $selectedSectionId === $standalone->id ? 'mt-1 text-xs font-normal text-slate-700' : 'mt-1 text-xs font-normal text-slate-400',
                            ])
                        </button>
                        <button
                            type="button"
                            wire:click="toggleOnAir({{ $standalone->id }})"
                            title="{{ $standalone->on_air ? 'Take off air' : 'Put on air' }}"
                            @class([
                                'shrink-0 rounded px-2 py-0.5 text-[10px] font-black tracking-wide',
                                'bg-red-600 text-white' => $standalone->on_air,
                                'bg-slate-200 text-slate-500 hover:bg-slate-300' => ! $standalone->on_air,
                            ])
                        >{{ $standalone->on_air ? 'ON AIR' : 'OFF' }}</button>
                    </div>
                @empty
                    <p class="px-2 py-4 text-center text-sm text-slate-400">No standalone sections.</p>
                @endforelse
            </nav>
            @if ($this->standaloneSections->isNotEmpty())
                <p class="px-3 pb-2 text-[11px] text-slate-400">Drag a section by &#10303; into the run of show to add it there.</p>
            @endif

            <form wire:submit="addSection" class="border-t border-slate-200 p-3">
                <input wire:model="newStandaloneName" placeholder="New section, e.g. Weather" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                @error('newStandaloneName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="addSection" class="mt-2 w-full rounded-md bg-slate-900 py-1.5 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-50">Add section</button>
            </form>
        </div>

        {{-- Templates --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 p-3">
                <h2 class="text-sm font-bold tracking-wide text-slate-500 uppercase">Templates</h2>
            </div>

            <nav wire:sort="sortTemplates" class="space-y-1 p-2">
                @forelse ($this->templates as $template)
                    <div
                        wire:key="template-{{ $template->id }}"
                        wire:sort:item="{{ $template->id }}"
                        @class([
                            'flex items-center gap-1 rounded-md pr-2',
                            'bg-amber-400 text-slate-900' => $selectedSectionId === $template->id,
                            'hover:bg-slate-100' => $selectedSectionId !== $template->id,
                        ])
                    >
                        <span wire:sort:handle title="Drag to reorder" class="cursor-grab pl-1.5 text-sm text-slate-400 select-none hover:text-slate-700">&#10303;</span>
                        <button type="button" wire:click="selectSection({{ $template->id }})" class="min-w-0 flex-1 py-2 pr-2 pl-1 text-left">
                            <span class="block truncate text-sm font-bold">{{ $template->name }}</span>
                            @include('pages.vmix-description-bullets', [
                                'lines' => $template->descriptionLines(),
                                'class' => $selectedSectionId === $template->id ? 'mt-1 text-xs font-normal text-slate-700' : 'mt-1 text-xs font-normal text-slate-400',
                            ])
                        </button>
                        <span class="shrink-0 font-mono text-[10px] text-slate-400">{{ $template->items_count }} {{ Str::plural('item', $template->items_count) }}</span>
                    </div>
                @empty
                    <p class="px-2 py-4 text-center text-sm text-slate-400">No templates yet.</p>
                @endforelse
            </nav>

            <form wire:submit="addTemplate" class="border-t border-slate-200 p-3">
                <input wire:model="newSectionName" placeholder="New template, e.g. Interview" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                @error('newSectionName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="addTemplate" class="mt-2 w-full rounded-md bg-slate-900 py-1.5 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-50">Add template</button>
            </form>
        </div>
    </aside>

    <div class="min-w-0 flex-1 space-y-4">
        {{-- Run of show accordion --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center gap-2 border-b border-slate-200 px-4 py-3">
                <h2 class="flex-1 text-sm font-bold tracking-wide text-slate-500 uppercase">Run of show</h2>
                <button type="button" wire:click="showPrevious" title="Take the current item off air and put the previous one on" class="rounded-md bg-slate-100 px-3 py-1.5 text-xs font-black hover:bg-slate-200">&#9664; Prev</button>
                <button type="button" wire:click="showNext" title="Take the current item off air and put the next one on" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-black text-white hover:bg-red-500">Next &#9654;</button>
            </div>

            @php($focusId = $expandedSectionId ?? ($this->runOfShow->contains('id', $selectedSectionId) ? $selectedSectionId : $this->currentItemId))
            <nav wire:sort="sortItems" wire:sort:config="{ group: { name: 'run-of-show', pull: false, put: true } }" class="min-h-40 space-y-1 p-3">
                @forelse ($this->runOfShow as $index => $item)
                    @php($isLive = $this->currentItemId === $item->id)
                    @php($isOpen = $expandedSectionId === $item->id)
                    <div
                        id="ros-{{ $item->id }}"
                        wire:key="item-{{ $item->id }}"
                        wire:sort:item="{{ $item->id }}"
                        @if ($focusId === $item->id) x-init="$nextTick(() => $el.scrollIntoView({ block: 'nearest' }))" @endif
                        @class([
                            'overflow-hidden rounded-lg',
                            'bg-red-50 ring-2 ring-red-600' => $isLive,
                            'bg-amber-50 ring-2 ring-amber-400' => $isOpen && ! $isLive,
                            'hover:bg-slate-50 ring-1 ring-transparent' => ! $isOpen && ! $isLive,
                        ])
                    >
                        <div @class([
                            'flex items-center gap-2 px-2 py-1',
                            'bg-red-600 text-white' => $isLive,
                            'bg-amber-400 text-slate-900' => $isOpen && ! $isLive,
                        ])>
                            <span wire:sort:handle title="Drag to reorder" @class(['cursor-grab px-1 text-base select-none', 'text-white/70 hover:text-white' => $isLive, 'text-slate-400 hover:text-slate-700' => ! $isLive])>&#10303;</span>
                            <button
                                type="button"
                                wire:click="toggleSection({{ $item->id }})"
                                title="{{ $isOpen ? 'Hide details' : 'Show details' }}"
                                @class(['shrink-0 rounded px-1 py-2', 'text-white/80 hover:bg-black/20 hover:text-white' => $isLive, 'text-slate-500 hover:bg-black/10 hover:text-slate-800' => ! $isLive])
                            >
                                <span @class(['inline-block text-[10px] leading-none transition-transform', 'rotate-90' => $isOpen])>&#9654;</span>
                            </button>
                            <span @class([
                                'w-8 shrink-0 text-center font-mono text-sm font-black tabular-nums',
                                'text-white' => $isLive,
                                'text-slate-900' => $isOpen && ! $isLive,
                                'text-slate-400' => ! $isOpen && ! $isLive,
                            ])>{{ sprintf('%02d', $index + 1) }}</span>
                            <button
                                type="button"
                                x-on:dblclick.prevent="$wire.goLive({{ $item->id }})"
                                title="Double-click to put on air. Use the arrow to open details."
                                class="min-w-0 flex-1 py-2 pr-2 text-left"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate text-base font-black">{{ $item->name }}</span>
                                    <span @class([
                                        'block truncate text-xs font-semibold uppercase',
                                        'text-red-100' => $isLive,
                                        'text-slate-700' => $isOpen && ! $isLive,
                                        'text-slate-400' => ! $isOpen && ! $isLive,
                                    ])>{{ $item->runOfShowLabel() }}</span>
                                    @include('pages.vmix-description-bullets', [
                                        'lines' => $item->descriptionLines(),
                                        'class' => $isLive
                                            ? 'mt-1 text-xs font-normal normal-case text-red-100'
                                            : ($isOpen ? 'mt-1 text-xs font-normal normal-case text-slate-700' : 'mt-1 text-xs font-normal normal-case text-slate-500'),
                                    ])
                                </span>
                            </button>
                            @unless ($item->isItem())
                                <button
                                    type="button"
                                    wire:click="removeFromRunOfShow({{ $item->id }})"
                                    @if ($item->isBreak() || $item->isPlaceholder()) wire:confirm="Delete {{ $item->name }}?" @endif
                                    title="{{ match (true) { $item->isBreak() => 'Delete this break', $item->isPlaceholder() => 'Delete this placeholder', default => 'Remove from the run of show (keeps the section and its on-air state)' } }}"
                                    @class(['shrink-0 rounded px-1', 'text-white/70 hover:bg-black/20 hover:text-white' => $isLive, 'text-slate-400 hover:bg-red-50 hover:text-red-600' => ! $isLive])
                                >&times;</button>
                            @endunless
                            @unless ($item->isPlaceholder())
                            <button
                                type="button"
                                wire:click="toggleOnAir({{ $item->id }})"
                                title="{{ $item->on_air ? 'Take off air' : ($item->isItem() ? 'Put on air (takes other '.$item->template->name.' items off)' : 'Put on air') }}"
                                @class([
                                    'shrink-0 rounded px-2 py-0.5 text-[10px] font-black tracking-wide',
                                    'bg-red-800 text-white hover:bg-red-950' => $item->on_air && $isLive,
                                    'bg-red-600 text-white' => $item->on_air && ! $isLive,
                                    'bg-black/20 text-white hover:bg-black/30' => ! $item->on_air && $isLive,
                                    'bg-slate-200 text-slate-500 hover:bg-slate-300' => ! $item->on_air && ! $isLive,
                                ])
                            >{{ $item->on_air ? ($item->isBreak() ? 'ON' : 'ON AIR') : 'OFF' }}</button>
                            @endunless
                        </div>
                        @if ($isOpen && ($section = $this->section) && $section->id === $item->id)
                            <div wire:sort:ignore @class(['border-t bg-white p-3', 'border-red-300' => $isLive, 'border-amber-300/60' => ! $isLive])>
                                @include('pages.vmix-section-editor', ['section' => $section])
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="px-2 py-8 text-center text-sm text-slate-400">Nothing in the run of show yet. Add an item or break on the left, or drag a section here.</p>
                @endforelse
            </nav>
        </div>

    {{-- Selected section --}}
    <section class="min-w-0 space-y-4">
        @if (($section = $this->section) && ! $section->isInRunOfShow())
            @include('pages.vmix-section-editor', ['section' => $section])
        @elseif (! $section)
            <div class="rounded-xl border-2 border-dashed border-slate-300 p-10 text-center text-slate-400">
                Add a standalone section on the left, e.g. “Weather”, or a template like “Interview”, then add items to the run of show.
            </div>
        @endif

            <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-2">
                    <h3 class="text-sm font-bold tracking-wide text-slate-500 uppercase">Master file preview</h3>
                    <a href="{{ route('feeds.data') }}" target="_blank" class="text-xs font-semibold text-slate-500 hover:text-slate-900">Open {{ route('feeds.data') }}</a>
                </div>
                <pre class="max-h-96 overflow-auto rounded-b-xl bg-slate-950 p-4 text-xs text-emerald-300">{{ json_encode((object) app(VmixFeed::class)->data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
    </section>
    </div>
    </div>
</div>
