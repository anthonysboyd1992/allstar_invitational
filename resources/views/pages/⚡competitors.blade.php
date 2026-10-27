<?php

use App\Models\Competitor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Competitors')] class extends Component
{
    use WithFileUploads;

    public const FormFields = ['car_number', 'first_name', 'last_name', 'hometown', 'weight', 'transponder', 'accolades'];

    public const PillMin = 1;

    public const PillMax = 100;

    /** @var array<string, string|null> */
    public array $form = [];

    public bool $showFormModal = false;

    public ?int $editingId = null;

    public ?TemporaryUploadedFile $headshot = null;

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $headshotUploads = [];

    public ?int $pillCompetitorId = null;

    public string $manualPill = '';

    public ?int $drawnPill = null;

    public string $search = '';

    public string $sortBy = 'car_number';

    /** @var list<int> */
    public array $selected = [];

    public function mount(): void
    {
        $this->resetForm();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fieldRules(): array
    {
        return [
            'car_number' => ['nullable', 'string', 'max:10'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'hometown' => ['nullable', 'string', 'max:150'],
            'weight' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'transponder' => ['nullable', 'string', 'max:50'],
            'accolades' => ['nullable', 'string', 'max:2000'],
        ];
    }

    #[Computed]
    public function competitors(): Collection
    {
        $direction = $this->sortBy === 'weight' ? 'desc' : 'asc';

        $competitors = Competitor::query()
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(fn ($inner) => $inner
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('car_number', 'like', $term)
                    ->orWhere('hometown', 'like', $term)
                    ->orWhere('transponder', 'like', $term));
            })
            ->when(in_array($this->sortBy, ['pill_number', 'weight'], true), fn ($query) => $query->orderByRaw("{$this->sortBy} is null"))
            ->when($this->sortBy !== 'car_number', fn ($query) => $query->orderBy($this->sortBy, $direction)->orderBy('last_name'))
            ->get();

        return $this->sortBy === 'car_number' ? Competitor::sortByCarNumber($competitors) : $competitors;
    }

    #[Computed]
    public function pillCompetitor(): ?Competitor
    {
        return $this->pillCompetitorId ? Competitor::query()->find($this->pillCompetitorId) : null;
    }

    #[Computed]
    public function drawnPillCount(): int
    {
        return Competitor::query()->whereBetween('pill_number', [self::PillMin, self::PillMax])->count();
    }

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->editingId = null;
        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        $competitor = Competitor::query()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $competitor->id;
        $this->form = collect(self::FormFields)->mapWithKeys(fn (string $field) => [$field => (string) $competitor->{$field}])->all();
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->editingId = null;
        $this->resetForm();
    }

    public function save(bool $addAnother = false): void
    {
        $this->form = $this->blankToNull($this->form);

        $rules = collect($this->fieldRules())->mapWithKeys(fn (array $rule, string $field) => ['form.'.$field => $rule])->all();
        $data = $this->validate($rules + ['headshot' => ['nullable', ...Competitor::HeadshotRules]], attributes: $this->attributeNames('form.'))['form'];

        if ($this->editingId) {
            $competitor = Competitor::query()->findOrFail($this->editingId);
            $competitor->update($data);
            $this->saveModalHeadshot($competitor);
            $this->closeFormModal();
            $this->dispatch('notify', message: "Saved {$competitor->full_name}.");

            return;
        }

        $competitor = Competitor::query()->create($data);
        $this->saveModalHeadshot($competitor);

        $this->resetForm();
        $this->showFormModal = $addAnother;
        $this->dispatch('notify', message: "Added {$competitor->full_name}.");
        $this->dispatch('competitor-added');
    }

    public function updateField(int $id, string $field, ?string $value): void
    {
        abort_unless(in_array($field, self::FormFields, true), 422);

        $competitor = Competitor::query()->findOrFail($id);
        $validator = Validator::make($this->blankToNull([$field => $value]), [$field => $this->fieldRules()[$field]]);

        if ($validator->fails()) {
            $this->dispatch('notify', message: $validator->errors()->first(), type: 'error');

            return;
        }

        $competitor->update($validator->validated());
    }

    /**
     * Upload straight from the table: the photo is cropped and saved as soon as it is picked.
     */
    public function updatedHeadshotUploads(mixed $photo, string $competitorId): void
    {
        if ($error = Competitor::query()->findOrFail((int) $competitorId)->replaceHeadshot($photo)) {
            $this->dispatch('notify', message: $error, type: 'error');
        }

        $this->headshotUploads = [];
    }

    public function removeHeadshot(int $id): void
    {
        $competitor = Competitor::query()->findOrFail($id);
        $competitor->deleteHeadshot();
        $competitor->save();
    }

    protected function saveModalHeadshot(Competitor $competitor): void
    {
        if ($this->headshot) {
            $this->storeHeadshotFor($competitor, $this->headshot);
        }
    }

    protected function storeHeadshotFor(Competitor $competitor, TemporaryUploadedFile $photo): void
    {
        try {
            $competitor->storeHeadshot($photo);
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', message: $exception->getMessage(), type: 'error');
        }
    }

    public function openPillModal(int $id): void
    {
        $this->pillCompetitorId = Competitor::query()->findOrFail($id)->id;
        $this->manualPill = '';
        $this->drawnPill = null;
        $this->resetValidation();
    }

    public function closePillModal(): void
    {
        $this->pillCompetitorId = null;
        $this->manualPill = '';
        $this->drawnPill = null;
        $this->resetValidation();
    }

    /**
     * Draw a random pill no other competitor holds. The screen updates in revealDrawnPill() once the animation ends.
     */
    #[Renderless]
    public function drawPill(): ?int
    {
        if ($this->drawnPill !== null) {
            return $this->drawnPill;
        }

        $competitor = Competitor::query()->findOrFail($this->pillCompetitorId);

        $taken = Competitor::query()
            ->whereKeyNot($competitor->id)
            ->whereNotNull('pill_number')
            ->pluck('pill_number')
            ->all();

        $available = array_values(array_diff(range(self::PillMin, self::PillMax), $taken));

        if ($available === []) {
            $this->dispatch('notify', message: 'All pills '.self::PillMin.'–'.self::PillMax.' have been drawn.', type: 'error');

            return null;
        }

        $this->drawnPill = Arr::random($available);

        return $this->drawnPill;
    }

    /**
     * Lock the drawn pill into the pill field.
     */
    public function revealDrawnPill(): void
    {
        if ($this->drawnPill !== null) {
            $this->manualPill = (string) $this->drawnPill;
            $this->resetValidation();
        }
    }

    public function savePill(): void
    {
        $competitor = Competitor::query()->findOrFail($this->pillCompetitorId);

        if ($this->drawnPill !== null) {
            $this->manualPill = (string) $this->drawnPill;
        }

        $this->validate([
            'manualPill' => ['required', 'integer', 'min:1', 'max:999', Rule::unique('competitors', 'pill_number')->ignore($competitor->id)],
        ], ['manualPill.unique' => 'Pill :input is already taken.'], ['manualPill' => 'pill']);

        $competitor->update(['pill_number' => (int) $this->manualPill]);
        $this->dispatch('notify', message: "{$competitor->full_name} has pill {$this->manualPill}.");
        $this->closePillModal();
    }

    public function clearPill(): void
    {
        Competitor::query()->whereKey($this->pillCompetitorId)->update(['pill_number' => null]);
        $this->closePillModal();
    }

    public function delete(int $id): void
    {
        Competitor::query()->whereKey($id)->get()->each->deleteHeadshot();
        Competitor::query()->whereKey($id)->delete();
        $this->selected = array_values(array_diff($this->selected, [$id]));
    }

    public function deleteSelected(): void
    {
        Competitor::query()->whereKey($this->selected)->get()->each->deleteHeadshot();
        $count = Competitor::query()->whereKey($this->selected)->delete();
        $this->selected = [];
        $this->dispatch('notify', message: "Deleted {$count} competitor(s).");
    }

    public function toggleAll(): void
    {
        $ids = $this->competitors->pluck('id')->all();
        $this->selected = count(array_diff($ids, $this->selected)) === 0 ? [] : $ids;
    }

    protected function resetForm(): void
    {
        $this->form = array_fill_keys(self::FormFields, '');
        $this->headshot = null;
        $this->resetValidation();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function blankToNull(array $data): array
    {
        return array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $data);
    }

    /**
     * @return array<string, string>
     */
    protected function attributeNames(string $prefix = ''): array
    {
        return collect(self::FormFields)->mapWithKeys(fn (string $field) => [$prefix.$field => str_replace('_', ' ', $field)])->all();
    }
};
?>

<div class="mx-auto max-w-7xl space-y-4">
    @if ($showFormModal)
        <div
            class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-20"
            x-on:keydown.escape.window="$wire.closeFormModal()"
            wire:click.self="closeFormModal"
        >
            <form
                wire:submit="save(true)"
                x-init="$nextTick(() => $el.querySelector('input')?.focus())"
                x-on:competitor-added.window="$nextTick(() => $el.querySelector('input')?.focus())"
                class="w-full max-w-2xl rounded-xl bg-white p-5 shadow-2xl"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-black uppercase">{{ $editingId ? 'Edit competitor' : 'Add competitor' }}</h2>
                    <button type="button" wire:click="closeFormModal" class="rounded px-2 text-2xl leading-none text-slate-400 hover:text-slate-900">&times;</button>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        'car_number' => 'Car #',
                        'first_name' => 'First name',
                        'last_name' => 'Last name',
                        'weight' => 'Weight',
                        'hometown' => 'Hometown',
                        'transponder' => 'Transponder',
                    ] as $field => $label)
                        <label @class(['block', 'col-span-2' => in_array($field, ['hometown', 'transponder'])])>
                            <span class="text-xs font-semibold text-slate-500">{{ $label }}</span>
                            <input
                                type="{{ $field === 'weight' ? 'number' : 'text' }}"
                                wire:model="form.{{ $field }}"
                                @class([
                                    'mt-0.5 w-full rounded-md border px-2 py-2 text-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 focus:outline-none',
                                    'border-red-500' => $errors->has('form.'.$field),
                                    'border-slate-300' => ! $errors->has('form.'.$field),
                                ])
                            >
                            @error('form.'.$field)
                                <span class="text-xs text-red-600">{{ $message }}</span>
                            @enderror
                        </label>
                    @endforeach

                    @php($savedHeadshotUrl = $editingId ? Competitor::query()->find($editingId)?->headshotUrl() : null)
                    <div class="col-span-2 flex items-center gap-3 sm:col-span-4">
                        @if ($headshot && ! $errors->has('headshot'))
                            <img src="{{ $headshot->temporaryUrl() }}" alt="New headshot" class="size-20 rounded-lg bg-slate-100 object-cover">
                        @elseif ($savedHeadshotUrl)
                            <img src="{{ $savedHeadshotUrl }}" alt="Headshot" class="size-20 rounded-lg bg-slate-100 object-cover">
                        @else
                            <div class="flex size-20 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-400">No photo</div>
                        @endif
                        <div class="space-y-1">
                            <span class="block text-xs font-semibold text-slate-500">Headshot</span>
                            <div class="flex items-center gap-2">
                                <label class="cursor-pointer rounded-md bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-700">
                                    {{ $headshot || $savedHeadshotUrl ? 'Choose another photo' : 'Choose photo' }}
                                    <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" wire:model="headshot" class="hidden">
                                </label>
                                @if ($savedHeadshotUrl && ! $headshot)
                                    <button type="button" wire:click="removeHeadshot({{ $editingId }})" wire:confirm="Remove this headshot?" class="rounded-md px-2 py-1.5 text-xs font-bold text-red-600 hover:bg-red-50">Remove</button>
                                @endif
                                <span wire:loading wire:target="headshot" class="text-xs font-semibold text-amber-600">Uploading…</span>
                            </div>
                            <span class="block text-xs text-slate-400">Cropped from the center to {{ config('vmix.headshot_width') }}&times;{{ config('vmix.headshot_height') }} when saved.</span>
                            @error('headshot') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <label class="col-span-2 block sm:col-span-4">
                        <span class="text-xs font-semibold text-slate-500">Accolades</span>
                        <textarea
                            wire:model="form.accolades"
                            rows="3"
                            placeholder="e.g. 2025 Track Champion, 12 career wins"
                            class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-2 text-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 focus:outline-none"
                        ></textarea>
                        @error('form.accolades')
                            <span class="text-xs text-red-600">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeFormModal" class="rounded-md px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</button>
                    @if ($editingId)
                        <button type="button" wire:click="save" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Save changes</button>
                    @else
                        <button type="button" wire:click="save" class="rounded-md px-4 py-2 text-sm font-semibold ring-1 ring-slate-300 hover:bg-slate-50">Save &amp; close</button>
                        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Save &amp; add another</button>
                    @endif
                </div>
            </form>
        </div>
    @endif

    @if ($this->pillCompetitor)
        @php($pillCompetitor = $this->pillCompetitor)
        <div
            class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-20"
            x-on:keydown.escape.window="$wire.closePillModal()"
            wire:click.self="closePillModal"
        >
            <div
                x-data="{
                    display: @js($pillCompetitor->pill_number ?? '?'),
                    rolling: false,
                    async draw() {
                        this.rolling = true;
                        const ticker = setInterval(() => this.display = Math.floor(Math.random() * {{ $this::PillMax }}) + 1, 60);
                        const [pill] = await Promise.all([$wire.drawPill(), new Promise(resolve => setTimeout(resolve, 1200))]);
                        clearInterval(ticker);
                        this.display = pill ?? @js($pillCompetitor->pill_number ?? '?');
                        if (pill !== null) {
                            await $wire.revealDrawnPill();
                        }
                        this.rolling = false;
                    },
                }"
                class="w-full max-w-md rounded-xl bg-white p-5 shadow-2xl"
            >
                <div class="mb-1 flex items-center justify-between">
                    <h2 class="text-lg font-black uppercase">Pill draw</h2>
                    <button type="button" wire:click="closePillModal" class="rounded px-2 text-2xl leading-none text-slate-400 hover:text-slate-900">&times;</button>
                </div>
                <p class="text-sm text-slate-500">
                    <span class="font-bold text-slate-900">#{{ $pillCompetitor->car_number }} {{ $pillCompetitor->full_name }}</span>
                    · {{ $this->drawnPillCount }} of {{ $this::PillMax }} pills drawn
                </p>

                <div class="my-5 flex justify-center">
                    <div
                        class="flex size-36 items-center justify-center rounded-full bg-slate-900 text-6xl font-black text-amber-400 shadow-inner tabular-nums"
                        :class="rolling && 'animate-pulse'"
                        x-text="display"
                    ></div>
                </div>

                <button
                    type="button"
                    x-on:click="draw()"
                    x-bind:disabled="rolling || $wire.drawnPill !== null"
                    class="w-full rounded-lg bg-amber-400 py-3 text-lg font-black text-slate-900 uppercase hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ $drawnPill !== null ? 'Pill drawn' : ($pillCompetitor->pill_number ? 'Redraw pill' : 'Draw pill') }}
                </button>

                <form wire:submit="savePill" class="mt-4 border-t border-slate-200 pt-4">
                    <label class="text-xs font-semibold text-slate-500">
                        {{ $drawnPill !== null ? 'Drawn pill — click Save to assign it' : 'Pill # (draw above or type one in)' }}
                    </label>
                    <div class="mt-1 flex gap-2">
                        <input
                            type="number"
                            min="1"
                            wire:model="manualPill"
                            placeholder="Pill #"
                            @readonly($drawnPill !== null)
                            @class([
                                'w-full rounded-md border px-2 py-2 text-sm focus:outline-none',
                                'cursor-not-allowed border-amber-400 bg-amber-50 text-lg font-black' => $drawnPill !== null,
                                'border-slate-300 focus:border-amber-500' => $drawnPill === null,
                            ])
                        >
                        <button type="submit" x-bind:disabled="rolling" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-50">Save</button>
                    </div>
                    @error('manualPill')
                        <span class="text-xs text-red-600">{{ $message }}</span>
                    @enderror
                </form>

                <div class="mt-4 flex justify-between text-sm">
                    @if ($pillCompetitor->pill_number)
                        <button type="button" wire:click="clearPill" wire:confirm="Clear pill {{ $pillCompetitor->pill_number }}?" class="font-semibold text-red-600 hover:text-red-800">Clear pill</button>
                    @else
                        <span></span>
                    @endif
                    <button type="button" wire:click="closePillModal" class="rounded-md px-4 py-2 font-semibold ring-1 ring-slate-300 hover:bg-slate-50">Cancel</button>
                </div>
            </div>
        </div>
    @endif

    <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-3">
            <button type="button" wire:click="openAddModal" class="rounded-md bg-amber-400 px-4 py-1.5 text-sm font-bold text-slate-900 hover:bg-amber-300">
                + Add competitor
            </button>

            <input
                type="search"
                wire:model.live.debounce.250ms="search"
                placeholder="Search name, car, hometown, transponder…"
                class="w-72 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            >

            <label class="flex items-center gap-2 text-sm text-slate-600">
                Sort
                <select wire:model.live="sortBy" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="car_number">Car #</option>
                    <option value="pill_number">Pill #</option>
                    <option value="last_name">Last name</option>
                    <option value="weight">Weight</option>
                </select>
            </label>

            <span class="text-sm text-slate-500">{{ $this->competitors->count() }} competitors</span>
            @if (count($selected))
                <button
                    type="button"
                    wire:click="deleteSelected"
                    wire:confirm="Delete {{ count($selected) }} selected competitor(s)?"
                    class="ml-auto rounded-md bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-500"
                >
                    Delete selected ({{ count($selected) }})
                </button>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-bold tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th class="w-8 px-3 py-2">
                            <input type="checkbox" wire:click="toggleAll" @checked($this->competitors->isNotEmpty() && count($selected) === $this->competitors->count())>
                        </th>
                        <th class="w-14 px-1 py-2">Photo</th>
                        <th class="w-24 px-1 py-2">Pill</th>
                        <th class="w-20 px-1 py-2">Car</th>
                        <th class="px-1 py-2">First</th>
                        <th class="px-1 py-2">Last</th>
                        <th class="px-1 py-2">Hometown</th>
                        <th class="w-20 px-1 py-2">Weight</th>
                        <th class="px-1 py-2">Transponder</th>
                        <th class="px-1 py-2">Accolades</th>
                        <th class="w-28 px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->competitors as $competitor)
                        <tr wire:key="competitor-{{ $competitor->id }}">
                            <td class="px-3 py-1">
                                <input type="checkbox" wire:model.live="selected" value="{{ $competitor->id }}">
                            </td>
                            <td class="px-1 py-1">
                                <label
                                    title="{{ $competitor->headshot_path ? 'Replace headshot' : 'Upload headshot' }}"
                                    class="relative flex size-10 cursor-pointer items-center justify-center overflow-hidden rounded-md bg-slate-100 text-lg text-slate-400 ring-1 ring-slate-200 hover:ring-amber-500"
                                >
                                    @if ($url = $competitor->headshotUrl())
                                        <img src="{{ $url }}" alt="{{ $competitor->full_name }}" class="size-full object-cover">
                                    @else
                                        +
                                    @endif
                                    <span wire:loading wire:target="headshotUploads.{{ $competitor->id }}" class="absolute inset-0 animate-pulse bg-amber-400/60"></span>
                                    <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" wire:model="headshotUploads.{{ $competitor->id }}" class="hidden">
                                </label>
                            </td>
                            <td class="px-1 py-1">
                                @if ($competitor->pill_number)
                                    <button
                                        type="button"
                                        wire:click="openPillModal({{ $competitor->id }})"
                                        title="Redraw or change pill"
                                        class="min-w-10 rounded-full bg-slate-900 px-2.5 py-1 text-xs font-black text-amber-400 hover:bg-slate-700"
                                    >{{ $competitor->pill_number }}</button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="openPillModal({{ $competitor->id }})"
                                        class="rounded-md bg-amber-400 px-2 py-1 text-xs font-bold whitespace-nowrap text-slate-900 hover:bg-amber-300"
                                    >Draw pill</button>
                                @endif
                            </td>
                            @foreach (['car_number', 'first_name', 'last_name', 'hometown', 'weight', 'transponder', 'accolades'] as $field)
                                <td class="px-1 py-1">
                                    <input
                                        type="{{ $field === 'weight' ? 'number' : 'text' }}"
                                        value="{{ $competitor->{$field} }}"
                                        wire:change="updateField({{ $competitor->id }}, '{{ $field }}', $event.target.value)"
                                        @class([
                                            'w-full rounded border border-transparent bg-transparent px-1.5 py-1 hover:border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none',
                                            'font-bold' => $field === 'car_number',
                                        ])
                                    >
                                </td>
                            @endforeach
                            <td class="px-3 py-1 text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    wire:click="openEditModal({{ $competitor->id }})"
                                    class="rounded px-2 py-1 text-xs font-bold text-slate-600 hover:bg-slate-100"
                                >Edit</button>
                                <button
                                    type="button"
                                    wire:click="delete({{ $competitor->id }})"
                                    wire:confirm="Delete {{ $competitor->full_name }}?"
                                    class="rounded px-2 py-1 text-xs font-bold text-red-600 hover:bg-red-50"
                                >Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-3 py-10 text-center text-slate-400">No competitors yet. Click “+ Add competitor”.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
