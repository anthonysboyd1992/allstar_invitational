<?php

use App\Models\Competitor;
use App\Models\RaceType;
use App\Models\Setting;
use App\Services\VmixFeed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('vMix')] class extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $driverHeadshot = null;

    public string $activeRaceId = '';

    public string $activeDriverId = '';

    public string $className = '';

    public string $driverMode = 'name_only';

    public string $driversPerDataset = '';

    public function mount(): void
    {
        $this->activeRaceId = (string) Setting::get(Setting::ActiveRace);
        $this->activeDriverId = (string) Setting::get(Setting::ActiveDriver);
        $this->className = (string) Setting::get(Setting::ClassName);
        $this->driverMode = (string) Setting::get(Setting::DriverMode, 'name_only');
        $this->driversPerDataset = (string) Setting::driversPerDataset();
    }

    public function updatedActiveRaceId(string $value): void
    {
        Setting::put(Setting::ActiveRace, $value ?: null);
    }

    public function updatedActiveDriverId(string $value): void
    {
        Setting::put(Setting::ActiveDriver, $value ?: null);
    }

    /**
     * Replace the on-screen driver's headshot. It is saved on the competitor, so the lineup and every other feed get it too.
     */
    public function updatedDriverHeadshot(): void
    {
        $driver = Competitor::query()->findOrFail((int) $this->activeDriverId);

        if ($error = $driver->replaceHeadshot($this->driverHeadshot)) {
            $this->dispatch('notify', message: $error, type: 'error');
        } else {
            $this->dispatch('notify', message: "Updated {$driver->full_name}'s headshot.");
        }

        $this->driverHeadshot = null;
        unset($this->drivers);
    }

    public function updatedClassName(string $value): void
    {
        Setting::put(Setting::ClassName, trim($value));
    }

    public function updatedDriverMode(string $value): void
    {
        Setting::put(Setting::DriverMode, trim($value) ?: 'name_only');
    }

    public function updatedDriversPerDataset(string $value): void
    {
        $validator = Validator::make(['count' => $value], ['count' => ['required', 'integer', 'min:1', 'max:'.Setting::MaxDriversPerDataset]]);

        if ($validator->fails()) {
            $this->dispatch('notify', message: 'Drivers per dataset must be between 1 and '.Setting::MaxDriversPerDataset.'.', type: 'error');
        } else {
            Setting::put(Setting::DriversPerDataset, (string) (int) $value);
        }

        $this->driversPerDataset = (string) Setting::driversPerDataset();
    }

    #[Computed]
    public function raceTypes(): Collection
    {
        return RaceType::query()->with('races')->orderBy('sort_order')->orderBy('id')->get();
    }

    #[Computed]
    public function drivers(): Collection
    {
        return Competitor::query()->get()->sortBy('car_number', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
};
?>

<div class="mx-auto grid max-w-7xl gap-4 lg:grid-cols-2">
    @foreach ([
        ['title' => 'Lineup feed', 'route' => 'feeds.lineup', 'data' => app(VmixFeed::class)->lineup()],
        ['title' => 'Driver feed', 'route' => 'feeds.driver', 'data' => app(VmixFeed::class)->driver()],
    ] as $index => $feed)
        <div wire:key="feed-{{ $index }}" class="flex flex-col rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="space-y-3 border-b border-slate-200 p-4">
                <h2 class="text-lg font-black uppercase">{{ $feed['title'] }}</h2>

                @if ($index === 0)
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">Active race (current lineup)</span>
                        <select wire:model.live="activeRaceId" class="mt-0.5 block w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">— None —</option>
                            @foreach ($this->raceTypes as $raceType)
                                <optgroup label="{{ $raceType->name }}" wire:key="opt-type-{{ $raceType->id }}">
                                    @foreach ($raceType->races as $race)
                                        <option value="{{ $race->id }}" wire:key="opt-race-{{ $race->id }}">{{ $race->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <div>
                        <span class="text-xs font-semibold text-slate-500">Drivers per dataset</span>
                        <div class="mt-0.5 flex items-center gap-1">
                            @foreach ([6, 12] as $preset)
                                <button
                                    type="button"
                                    wire:click="$set('driversPerDataset', '{{ $preset }}')"
                                    @class([
                                        'rounded-md px-3 py-2 text-sm font-bold',
                                        'bg-amber-400 text-slate-900' => $driversPerDataset === (string) $preset,
                                        'bg-slate-100 text-slate-600 hover:bg-slate-200' => $driversPerDataset !== (string) $preset,
                                    ])
                                >{{ $preset }}</button>
                            @endforeach
                            <input
                                type="number"
                                min="1"
                                max="{{ Setting::MaxDriversPerDataset }}"
                                wire:model.blur="driversPerDataset"
                                x-on:keydown.enter.prevent="$el.blur()"
                                aria-label="Custom drivers per dataset"
                                class="w-20 rounded-md border border-slate-300 px-2 py-2 text-sm"
                            >
                        </div>
                        <span class="mt-0.5 block text-xs text-slate-400">Each dataset has this many driver slots (Number1&ndash;Number{{ $driversPerDataset }}). Bigger fields roll onto the next dataset.</span>
                    </div>
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">Class (used in Class.Text, Event.Text and DriverKey)</span>
                        <input wire:model.live.debounce.400ms="className" placeholder="e.g. IMCA Modified" class="mt-0.5 block w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                    </label>
                @else
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">Driver on screen</span>
                        <select wire:model.live="activeDriverId" class="mt-0.5 block w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">— None —</option>
                            @foreach ($this->drivers as $driver)
                                <option value="{{ $driver->id }}" wire:key="opt-driver-{{ $driver->id }}">#{{ $driver->car_number }} — {{ $driver->full_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($activeDriver = $this->drivers->firstWhere('id', (int) $activeDriverId))
                        <div class="flex items-center gap-3">
                            <img src="{{ app(VmixFeed::class)->headshotFor($activeDriver) }}" alt="{{ $activeDriver->full_name }}" class="size-16 rounded-lg bg-slate-100 object-cover ring-1 ring-slate-200">
                            <div class="space-y-1">
                                <label class="inline-block cursor-pointer rounded-md bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-700">
                                    {{ $activeDriver->headshotUrl() ? 'Replace headshot' : 'Upload headshot' }}
                                    <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" wire:model="driverHeadshot" class="hidden">
                                </label>
                                <span wire:loading wire:target="driverHeadshot" class="text-xs font-semibold text-amber-600">Uploading…</span>
                                <span class="block text-xs text-slate-400">Saved to {{ $activeDriver->full_name }}'s competitor record ({{ config('vmix.headshot_width') }}&times;{{ config('vmix.headshot_height') }}).</span>
                            </div>
                        </div>
                    @endif
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">Mode</span>
                        <input wire:model.live.debounce.400ms="driverMode" list="driver-modes" class="mt-0.5 block w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                        <datalist id="driver-modes">
                            <option value="name_only"></option>
                            <option value="hometown"></option>
                            <option value="accolades"></option>
                        </datalist>
                    </label>
                @endif

                <div class="flex items-center gap-2">
                    <code class="flex-1 truncate rounded bg-slate-100 px-2 py-1.5 text-xs select-all">{{ route($feed['route']) }}</code>
                    <button
                        type="button"
                        x-on:click="copyText(@js(route($feed['route'])), @js(strtolower($feed['title']).' URL'))"
                        class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white"
                    >Copy URL</button>
                    <a href="{{ route($feed['route']) }}" target="_blank" class="rounded-md px-3 py-1.5 text-xs font-semibold ring-1 ring-slate-300">Open</a>
                </div>
            </div>

            <pre class="max-h-[60vh] flex-1 overflow-auto rounded-b-xl bg-slate-950 p-4 text-xs text-emerald-300">{{ $this->json($feed['data']) }}</pre>
        </div>
    @endforeach
</div>
