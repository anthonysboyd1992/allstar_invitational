<?php

use App\Models\Competitor;
use App\Models\VmixField;
use App\Models\VmixGroup;
use App\Models\VmixSection;
use App\Services\VmixFeed;
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

    public string $newSectionName = '';

    public string $newGroupName = '';

    /** @var array<int, array{key?: string, value?: string}> */
    public array $newField = [];

    public string $sectionName = '';

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
        if (! $this->selectedSectionId || ! VmixSection::query()->whereKey($this->selectedSectionId)->exists()) {
            $this->selectedSectionId = VmixSection::query()->orderBy('sort_order')->orderBy('id')->value('id');
        }

        $this->loadSection();
    }

    /**
     * Refill every bound input from the database so the screen always shows what is saved.
     */
    public function loadSection(): void
    {
        unset($this->section, $this->sections);
        $section = $this->section;

        $this->sectionName = (string) $section?->name;
        $this->sectionKey = (string) $section?->key;
        $this->groupNames = $section?->groups->mapWithKeys(fn (VmixGroup $group) => [$group->id => $group->name])->all() ?? [];

        $fields = $section?->groups->flatMap->fields ?? collect();
        $this->fieldKeys = $fields->mapWithKeys(fn (VmixField $field) => [$field->id => $field->key])->all();
        $this->fieldValues = $fields->mapWithKeys(fn (VmixField $field) => [$field->id => (string) $field->value])->all();
        $this->fieldWidths = $fields->mapWithKeys(fn (VmixField $field) => [$field->id => (string) $field->width])->all();
        $this->fieldHeights = $fields->mapWithKeys(fn (VmixField $field) => [$field->id => (string) $field->height])->all();
        $this->uploads = [];
    }

    #[Computed]
    public function sections(): Collection
    {
        return VmixSection::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    #[Computed]
    public function drivers(): Collection
    {
        return Competitor::sortByCarNumber(Competitor::query()->get(['id', 'car_number', 'first_name', 'last_name', 'headshot_path']));
    }

    #[Computed]
    public function section(): ?VmixSection
    {
        return $this->selectedSectionId
            ? VmixSection::query()->with('groups.fields')->find($this->selectedSectionId)
            : null;
    }

    public function selectSection(int $sectionId): void
    {
        $this->selectedSectionId = $sectionId;
        $this->newField = [];
        $this->resetValidation();
        $this->loadSection();
    }

    public function addSection(): void
    {
        $this->newSectionName = trim($this->newSectionName);

        $this->validate(
            ['newSectionName' => ['required', 'string', 'max:100', 'unique:vmix_sections,name']],
            ['newSectionName.unique' => 'There is already a section with that name.'],
            ['newSectionName' => 'section name'],
        );

        $name = $this->newSectionName;
        $section = VmixSection::query()->create([
            'name' => $name,
            'key' => VmixSection::uniqueKeyFor($name),
            'sort_order' => (int) VmixSection::query()->max('sort_order') + 1,
        ]);

        $this->reset('newSectionName');
        $this->selectSection($section->id);
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

    public function updatedSectionKey(string $key): void
    {
        $section = $this->section;
        $key = trim($key);

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

    public function toggleOnAir(int $sectionId): void
    {
        $section = VmixSection::query()->findOrFail($sectionId);
        $section->update(['on_air' => ! $section->on_air]);
    }

    public function deleteSection(): void
    {
        $this->section?->groups->flatMap->fields->each->deleteImageFiles();
        $this->section?->delete();
        $this->selectedSectionId = VmixSection::query()->orderBy('sort_order')->orderBy('id')->value('id');
        $this->loadSection();
    }

    public function addGroup(): void
    {
        $this->newGroupName = trim($this->newGroupName);

        $this->validate(
            ['newGroupName' => ['required', 'string', 'max:100', "unique:vmix_groups,name,NULL,id,vmix_section_id,{$this->selectedSectionId}"]],
            ['newGroupName.unique' => 'This section already has a group with that name.'],
            ['newGroupName' => 'group name'],
        );

        $this->section->groups()->create([
            'name' => $this->newGroupName,
            'sort_order' => (int) $this->section->groups()->max('sort_order') + 1,
        ]);

        $this->reset('newGroupName');
        $this->loadSection();
    }

    public function updatedGroupNames(string $name, string $groupId): void
    {
        $group = $this->sectionGroup((int) $groupId);
        $name = trim($name);

        if (VmixGroup::query()->where('vmix_section_id', $group->vmix_section_id)->where('name', $name)->whereKeyNot($group->id)->exists()) {
            $this->dispatch('notify', message: 'This section already has a group with that name.', type: 'error');
        } elseif ($name !== '') {
            $group->update(['name' => $name]);
        }

        $this->loadSection();
    }

    public function deleteGroup(int $groupId): void
    {
        $group = $this->sectionGroup($groupId);
        $group->fields->each->deleteImageFiles();
        $group->delete();
        $this->loadSection();
    }

    /**
     * Field names are unique across the whole section because every group is output together in one flat row.
     */
    public function addField(int $groupId): void
    {
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

        $group->fields()->create([
            'key' => $key,
            'type' => $type,
            'value' => $value,
            'live_value' => $needsUpdate ? null : $value,
            'needs_update' => $needsUpdate,
            'width' => $isImage ? (int) $validator->validated()['width'] : null,
            'height' => $isImage ? (int) $validator->validated()['height'] : null,
            'sort_order' => (int) $group->fields()->max('sort_order') + 1,
        ]);

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
        $field = $this->sectionField($fieldId);
        $size = ['width' => $this->fieldWidths[$fieldId] ?? '', 'height' => $this->fieldHeights[$fieldId] ?? ''];
        $rule = ['required', 'integer', 'min:1', 'max:'.VmixField::MaxDimension];

        $validator = Validator::make($size, ['width' => $rule, 'height' => $rule]);

        if ($validator->fails() || ! $field->isImage()) {
            $this->dispatch('notify', message: $validator->errors()->first() ?: 'Only image fields have a size.', type: 'error');
        } elseif ((int) $size['width'] !== $field->width || (int) $size['height'] !== $field->height) {
            try {
                $field->resize((int) $size['width'], (int) $size['height']);
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
        $field = $this->sectionField($fieldId);
        $field->setNeedsUpdate(! $field->needs_update);

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
        }

        $this->loadSection();
    }

    public function deleteField(int $fieldId): void
    {
        $field = $this->sectionField($fieldId);
        $field->deleteImageFiles();
        $field->delete();
        $this->loadSection();
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

<div class="flex items-start gap-4">
    {{-- Sections --}}
    <aside class="w-64 shrink-0 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-200 p-3">
            <h2 class="text-sm font-bold tracking-wide text-slate-500 uppercase">Sections</h2>
        </div>

        <nav class="space-y-1 p-2">
            @forelse ($this->sections as $section)
                <div
                    wire:key="section-{{ $section->id }}"
                    @class([
                        'flex items-center gap-2 rounded-md pr-2',
                        'bg-amber-400 text-slate-900' => $selectedSectionId === $section->id,
                        'hover:bg-slate-100' => $selectedSectionId !== $section->id,
                    ])
                >
                    <button type="button" wire:click="selectSection({{ $section->id }})" class="min-w-0 flex-1 truncate px-3 py-2 text-left text-sm font-bold">
                        {{ $section->name }}
                    </button>
                    <button
                        type="button"
                        wire:click="toggleOnAir({{ $section->id }})"
                        title="{{ $section->on_air ? 'Take off air' : 'Put on air' }}"
                        @class([
                            'shrink-0 rounded px-2 py-0.5 text-[10px] font-black tracking-wide',
                            'bg-red-600 text-white' => $section->on_air,
                            'bg-slate-200 text-slate-500 hover:bg-slate-300' => ! $section->on_air,
                        ])
                    >{{ $section->on_air ? 'ON AIR' : 'OFF' }}</button>
                </div>
            @empty
                <p class="px-2 py-4 text-center text-sm text-slate-400">No sections yet.</p>
            @endforelse
        </nav>

        <form wire:submit="addSection" class="border-t border-slate-200 p-3">
            <input wire:model="newSectionName" placeholder="New section, e.g. Track Facts" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
            @error('newSectionName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <button type="submit" wire:loading.attr="disabled" wire:target="addSection" class="mt-2 w-full rounded-md bg-slate-900 py-1.5 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-50">Add section</button>
        </form>
    </aside>

    {{-- Selected section --}}
    <section class="min-w-0 flex-1 space-y-4">
        <div x-data="{ copied: false }" class="flex flex-wrap items-center gap-3 rounded-xl bg-slate-900 p-3 text-white shadow-sm">
            <span class="text-xs font-bold tracking-wide text-amber-400 uppercase">Master vMix file</span>
            <code class="min-w-0 flex-1 truncate rounded bg-slate-800 px-2 py-1.5 text-xs">{{ route('feeds.data') }}</code>
            <button
                type="button"
                x-on:click="navigator.clipboard.writeText(@js(route('feeds.data'))); copied = true; setTimeout(() => copied = false, 1500)"
                class="rounded-md bg-amber-400 px-3 py-1.5 text-xs font-bold text-slate-900"
                x-text="copied ? 'Copied' : 'Copy URL'"
            ></button>
            <p class="w-full text-xs text-slate-400">Only sections that are <span class="font-bold text-red-400">ON AIR</span> are in the file. Edits go out as soon as they save.</p>
        </div>

        @if ($section = $this->section)
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-end gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">Section name</span>
                        <input
                            wire:model.blur="sectionName"
                            x-on:keydown.enter.prevent="$el.blur()"
                            class="mt-0.5 block w-56 rounded-md border border-slate-300 px-2 py-1.5 text-lg font-black focus:border-amber-500 focus:outline-none"
                        >
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">JSON key</span>
                        <input
                            wire:model.blur="sectionKey"
                            x-on:keydown.enter.prevent="$el.blur()"
                            class="mt-0.5 block w-48 rounded-md border border-slate-300 px-2 py-2 font-mono text-sm focus:border-amber-500 focus:outline-none"
                        >
                    </label>

                    <div class="flex-1"></div>

                    <button
                        type="button"
                        wire:click="toggleOnAir({{ $section->id }})"
                        @class([
                            'rounded-md px-4 py-2 text-sm font-black',
                            'bg-red-600 text-white hover:bg-red-500' => $section->on_air,
                            'bg-slate-200 text-slate-600 hover:bg-slate-300' => ! $section->on_air,
                        ])
                    >{{ $section->on_air ? 'ON AIR — take off' : 'OFF AIR — put on air' }}</button>
                    @if ($pendingInSection = $section->groups->flatMap->fields->filter->isPending()->count())
                        <button type="button" wire:click="pushSection" class="rounded-md bg-red-600 px-4 py-2 text-sm font-black text-white hover:bg-red-500">Update all pending ({{ $pendingInSection }})</button>
                    @endif
                    <button
                        type="button"
                        wire:click="deleteSection"
                        wire:confirm="Delete {{ $section->name }} with all its groups and fields?"
                        class="rounded-md px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-50"
                    >Delete section</button>
                </div>

                <form wire:submit="addGroup" class="mt-4 flex items-start gap-2 border-t border-slate-100 pt-4">
                    <div>
                        <input wire:model="newGroupName" placeholder="New group, e.g. Heading" class="w-56 rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                        @error('newGroupName') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="addGroup" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-50">Add group</button>
                    <p class="self-center text-xs text-slate-400">Groups organize fields on this screen. All fields in the section go out together, so each field name is used once per section.</p>
                </form>
            </div>

            <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                @forelse ($section->groups as $group)
                    <div wire:key="group-{{ $group->id }}" class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                        <div class="flex items-center gap-2 border-b border-slate-100 p-2">
                            <input
                                wire:model.blur="groupNames.{{ $group->id }}"
                                x-on:keydown.enter.prevent="$el.blur()"
                                class="min-w-0 flex-1 rounded border border-transparent px-1 py-0.5 font-bold hover:border-slate-200 focus:border-amber-500 focus:outline-none"
                            >
                            @if ($pendingInGroup = $group->fields->filter->isPending()->count())
                                <button type="button" wire:click="pushGroup({{ $group->id }})" class="rounded bg-red-600 px-2 py-0.5 text-xs font-black text-white hover:bg-red-500">Update all ({{ $pendingInGroup }})</button>
                            @endif
                            <button
                                type="button"
                                wire:click="deleteGroup({{ $group->id }})"
                                wire:confirm="Delete group {{ $group->name }}?"
                                class="rounded px-2 py-0.5 text-xs font-bold text-red-600 hover:bg-red-50"
                            >Delete</button>
                        </div>

                        <div class="space-y-1 p-2">
                            @foreach ($group->fields as $field)
                                <div
                                    wire:key="field-{{ $field->id }}"
                                    @class(['rounded', 'bg-amber-50 p-1 ring-1 ring-amber-300' => $field->isPending()])
                                >
                                <div class="flex items-center gap-1">
                                    <input
                                        wire:model.blur="fieldKeys.{{ $field->id }}"
                                        x-on:keydown.enter.prevent="$el.blur()"
                                        class="w-36 shrink-0 rounded border border-slate-200 bg-slate-50 px-1.5 py-1 font-mono text-xs focus:border-amber-500 focus:outline-none"
                                    >
                                    @if ($field->isImage())
                                        <div class="flex min-w-0 flex-1 items-center gap-2 rounded border border-slate-200 px-1.5 py-1">
                                            @if ($url = $field->imageUrl())
                                                <a href="{{ $url }}" target="_blank" class="shrink-0">
                                                    <img src="{{ $url }}" alt="{{ $field->key }}" class="h-8 w-14 rounded bg-[repeating-conic-gradient(#e2e8f0_0_25%,#fff_0_50%)] bg-[length:10px_10px] object-contain">
                                                </a>
                                                <span class="min-w-0 flex-1"></span>
                                            @else
                                                <span class="min-w-0 flex-1 text-xs text-slate-400">No image</span>
                                            @endif
                                            <div class="flex shrink-0 items-center gap-0.5 text-xs text-slate-400" title="Images are resized and cropped from the center to this size. Changing it re-crops the uploaded image.">
                                                <input type="number" min="1" max="{{ VmixField::MaxDimension }}" wire:model.blur="fieldWidths.{{ $field->id }}" x-on:keydown.enter.prevent="$el.blur()" aria-label="Width" class="w-14 rounded border border-slate-200 px-1 py-0.5 text-right text-xs text-slate-700 focus:border-amber-500 focus:outline-none">
                                                &times;
                                                <input type="number" min="1" max="{{ VmixField::MaxDimension }}" wire:model.blur="fieldHeights.{{ $field->id }}" x-on:keydown.enter.prevent="$el.blur()" aria-label="Height" class="w-14 rounded border border-slate-200 px-1 py-0.5 text-right text-xs text-slate-700 focus:border-amber-500 focus:outline-none">
                                            </div>
                                            <span wire:loading wire:target="uploads.{{ $field->id }}, fieldWidths.{{ $field->id }}, fieldHeights.{{ $field->id }}" class="text-xs font-semibold text-amber-600">Working…</span>
                                            <label class="shrink-0 cursor-pointer rounded bg-slate-900 px-2 py-0.5 text-xs font-bold text-white hover:bg-slate-700">
                                                {{ $field->imageUrl() ? 'Replace' : 'Upload' }}
                                                <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" wire:model="uploads.{{ $field->id }}" class="hidden">
                                            </label>
                                            @if ($field->imageUrl())
                                                <button type="button" wire:click="removeImage({{ $field->id }})" class="shrink-0 rounded px-1.5 text-xs font-bold text-slate-500 hover:bg-slate-100">Clear</button>
                                            @endif
                                        </div>
                                    @elseif ($field->isDriver())
                                        @php($driver = $this->drivers->firstWhere('id', (int) $field->value))
                                        <div class="flex min-w-0 flex-1 items-center gap-2 rounded border border-slate-200 px-1.5 py-1">
                                            <label
                                                title="{{ $driver ? 'Upload a new headshot for '.$driver->full_name.' (updates the competitor)' : 'Pick a driver first' }}"
                                                @class([
                                                    'relative flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-slate-100 text-slate-400 ring-1 ring-slate-200',
                                                    'cursor-pointer hover:ring-amber-500' => $driver,
                                                ])
                                            >
                                                @if ($driver?->headshotUrl())
                                                    <img src="{{ $driver->headshotUrl() }}" alt="{{ $driver->full_name }}" class="size-full object-cover">
                                                @else
                                                    <span class="text-sm">{{ $driver ? '+' : '–' }}</span>
                                                @endif
                                                <span wire:loading wire:target="headshotUploads.{{ $driver?->id }}" class="absolute inset-0 animate-pulse bg-amber-400/60"></span>
                                                @if ($driver)
                                                    <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" wire:model="headshotUploads.{{ $driver->id }}" class="hidden">
                                                @endif
                                            </label>
                                            <select
                                                wire:model.live="fieldValues.{{ $field->id }}"
                                                class="min-w-0 flex-1 rounded border border-slate-200 bg-white px-1 py-0.5 text-sm focus:border-amber-500 focus:outline-none"
                                            >
                                                <option value="">— No driver —</option>
                                                @foreach ($this->drivers as $option)
                                                    <option value="{{ $option->id }}">{{ $option->car_number ? '#'.$option->car_number.' ' : '' }}{{ $option->full_name }}</option>
                                                @endforeach
                                            </select>
                                            <span class="shrink-0 font-mono text-[10px] text-slate-400" title="{{ collect(VmixField::DriverKeySuffixes)->map(fn ($suffix) => $field->key.'_'.$suffix)->implode(', ') }}">{{ $field->key }}_*</span>
                                        </div>
                                    @elseif ($field->isColor())
                                        <div class="flex min-w-0 flex-1 items-center gap-2 rounded border border-slate-200 px-1.5 py-1">
                                            <input
                                                type="color"
                                                wire:model.live.debounce.150ms="fieldValues.{{ $field->id }}"
                                                aria-label="Pick a color for {{ $field->key }}"
                                                class="h-7 w-10 shrink-0 cursor-pointer rounded border border-slate-200 bg-white p-0.5"
                                            >
                                            <input
                                                wire:model.blur="fieldValues.{{ $field->id }}"
                                                x-on:keydown.enter.prevent="$el.blur()"
                                                placeholder="#FFFFFF"
                                                maxlength="7"
                                                aria-label="Hex value for {{ $field->key }}"
                                                class="w-24 rounded border border-slate-200 px-1.5 py-0.5 font-mono text-sm uppercase focus:border-amber-500 focus:outline-none"
                                            >
                                        </div>
                                    @else
                                        <input
                                            wire:model.live.debounce.250ms="fieldValues.{{ $field->id }}"
                                            placeholder="(blank)"
                                            class="min-w-0 flex-1 rounded border border-slate-200 px-1.5 py-1 text-sm focus:border-amber-500 focus:outline-none"
                                        >
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="toggleNeedsUpdate({{ $field->id }})"
                                        title="{{ $field->needs_update ? 'Changes wait for Update. Click to go live as you type.' : 'Changes go live as you type. Click to make changes wait for Update.' }}"
                                        @class([
                                            'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-black tracking-wide',
                                            'bg-slate-800 text-white' => $field->needs_update,
                                            'bg-emerald-100 text-emerald-800' => ! $field->needs_update,
                                        ])
                                    >{{ $field->needs_update ? 'MANUAL' : 'AUTO' }}</button>
                                    @if ($field->isPending())
                                        <button type="button" wire:click="pushField({{ $field->id }})" class="shrink-0 rounded bg-red-600 px-2 py-0.5 text-xs font-black text-white hover:bg-red-500">Update</button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="deleteField({{ $field->id }})"
                                        wire:confirm="Delete field {{ $field->key }}?"
                                        title="Remove {{ $field->key }} from this group"
                                        class="rounded px-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                                    >&times;</button>
                                </div>
                                @if ($field->isPending())
                                    <div class="mt-1 flex items-center gap-2 pl-1 text-xs text-slate-500">
                                        <span class="rounded bg-red-600 px-1 text-[10px] font-black text-white">ON AIR</span>
                                        @if ($field->isImage())
                                            @if ($liveUrl = $field->imageUrl($field->live_value))
                                                <img src="{{ $liveUrl }}" alt="On air" class="h-6 w-10 rounded bg-slate-100 object-contain">
                                            @else
                                                <span class="italic">no image</span>
                                            @endif
                                        @elseif ($field->isDriver())
                                            @php($liveDriver = $this->drivers->firstWhere('id', (int) $field->live_value))
                                            <span class="font-semibold text-slate-700">{{ $liveDriver ? ($liveDriver->car_number ? '#'.$liveDriver->car_number.' ' : '').$liveDriver->full_name : 'No driver' }}</span>
                                        @elseif ($field->isColor() && filled($field->live_value))
                                            <span class="size-4 rounded ring-1 ring-slate-300" style="background-color: {{ $field->live_value }}"></span>
                                            <span class="font-mono font-semibold text-slate-700">{{ $field->live_value }}</span>
                                        @else
                                            <span class="truncate font-semibold text-slate-700">{{ filled($field->live_value) ? $field->live_value : '(blank)' }}</span>
                                        @endif
                                        <span class="text-amber-700">→ pending change</span>
                                    </div>
                                @endif
                                </div>
                            @endforeach

                            <form wire:submit="addField({{ $group->id }})" class="flex items-start gap-1 pt-1">
                                <div class="w-36 shrink-0">
                                    <input wire:model="newField.{{ $group->id }}.key" placeholder="field_name" class="w-full rounded border border-dashed border-slate-300 px-1.5 py-1 font-mono text-xs focus:border-amber-500 focus:outline-none">
                                </div>
                                <select wire:model.live="newField.{{ $group->id }}.type" class="shrink-0 rounded border border-dashed border-slate-300 bg-white px-1 py-1 text-xs focus:border-amber-500 focus:outline-none">
                                    @foreach (VmixField::Types as $type => $label)
                                        <option value="{{ $type }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @if (($newField[$group->id]['type'] ?? VmixField::TypeText) === VmixField::TypeImage)
                                    <div class="flex min-w-0 flex-1 items-center gap-0.5 text-xs text-slate-400" title="Uploads are resized and cropped from the center to this size">
                                        <input type="number" min="1" max="{{ VmixField::MaxDimension }}" wire:model="newField.{{ $group->id }}.width" placeholder="{{ VmixField::DefaultWidth }}" aria-label="Width" class="w-16 rounded border border-dashed border-slate-300 px-1 py-1 text-right text-xs text-slate-700 focus:border-amber-500 focus:outline-none">
                                        &times;
                                        <input type="number" min="1" max="{{ VmixField::MaxDimension }}" wire:model="newField.{{ $group->id }}.height" placeholder="{{ VmixField::DefaultHeight }}" aria-label="Height" class="w-16 rounded border border-dashed border-slate-300 px-1 py-1 text-right text-xs text-slate-700 focus:border-amber-500 focus:outline-none">
                                        <span class="ml-1">px</span>
                                    </div>
                                @elseif (($newField[$group->id]['type'] ?? VmixField::TypeText) === VmixField::TypeDriver)
                                    <select wire:model="newField.{{ $group->id }}.value" class="min-w-0 flex-1 rounded border border-dashed border-slate-300 bg-white px-1 py-1 text-sm focus:border-amber-500 focus:outline-none">
                                        <option value="">— No driver —</option>
                                        @foreach ($this->drivers as $option)
                                            <option value="{{ $option->id }}">{{ $option->car_number ? '#'.$option->car_number.' ' : '' }}{{ $option->full_name }}</option>
                                        @endforeach
                                    </select>
                                @elseif (($newField[$group->id]['type'] ?? VmixField::TypeText) === VmixField::TypeColor)
                                    <div class="flex min-w-0 flex-1 items-center gap-1">
                                        <input type="color" wire:model.live="newField.{{ $group->id }}.value" value="{{ VmixField::DefaultColor }}" aria-label="Color" class="h-7 w-10 shrink-0 cursor-pointer rounded border border-dashed border-slate-300 bg-white p-0.5">
                                        <input wire:model="newField.{{ $group->id }}.value" placeholder="{{ VmixField::DefaultColor }}" maxlength="7" aria-label="Hex value" class="w-24 rounded border border-dashed border-slate-300 px-1.5 py-1 font-mono text-sm uppercase focus:border-amber-500 focus:outline-none">
                                    </div>
                                @else
                                    <input wire:model="newField.{{ $group->id }}.value" placeholder="Value" class="min-w-0 flex-1 rounded border border-dashed border-slate-300 px-1.5 py-1 text-sm focus:border-amber-500 focus:outline-none">
                                @endif
                                <button type="submit" wire:loading.attr="disabled" wire:target="addField" class="rounded bg-slate-100 px-2 py-1 text-xs font-bold hover:bg-slate-200 disabled:opacity-50">Add field</button>
                            </form>
                            @error("newField.{$group->id}.key") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border-2 border-dashed border-slate-300 p-8 text-center text-slate-400 xl:col-span-2">
                        Add a group above, e.g. “Heading”, then give it fields like <code>track_name</code>.
                    </div>
                @endforelse
            </div>

            <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-2">
                    <h3 class="text-sm font-bold tracking-wide text-slate-500 uppercase">Master file preview</h3>
                    <a href="{{ route('feeds.data') }}" target="_blank" class="text-xs font-semibold text-slate-500 hover:text-slate-900">Open {{ route('feeds.data') }}</a>
                </div>
                <pre class="max-h-96 overflow-auto rounded-b-xl bg-slate-950 p-4 text-xs text-emerald-300">{{ json_encode((object) app(VmixFeed::class)->data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        @else
            <div class="rounded-xl border-2 border-dashed border-slate-300 p-10 text-center text-slate-400">
                Add a section on the left, e.g. “Track Facts”.
            </div>
        @endif
    </section>
</div>
