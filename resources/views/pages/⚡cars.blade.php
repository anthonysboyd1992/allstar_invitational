<?php

use App\Models\Car;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cars')] class extends Component
{
    public string $newNumber = '';

    public string $newTime = '';

    /**
     * @return Collection<int, Car>
     */
    #[Computed]
    public function cars(): Collection
    {
        return Car::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function add(): void
    {
        $this->newNumber = trim($this->newNumber);
        $this->newTime = trim($this->newTime);

        $validated = $this->validate(
            [
                'newNumber' => ['required', 'string', 'max:10', Rule::unique('cars', 'number')],
                'newTime' => $this->timeRules(),
            ],
            [],
            ['newNumber' => 'car number', 'newTime' => 'time'],
        );

        $number = $validated['newNumber'];

        if ($this->sortMode() === 'manual') {
            Car::query()->create([
                'number' => $number,
                'time_ms' => Car::parseTime($validated['newTime']),
                'sort_order' => ((int) Car::query()->max('sort_order')) + 1,
            ]);
        } else {
            Car::query()->create([
                'number' => $number,
                'time_ms' => Car::parseTime($validated['newTime']),
                'sort_order' => 0,
            ]);
            $this->applyCurrentSort();
        }

        $this->newNumber = '';
        $this->newTime = '';
        unset($this->cars);
        $this->dispatch('notify', message: "Added car {$number}.");
    }

    public function updateNumber(int $carId, string $number): void
    {
        $car = Car::query()->findOrFail($carId);
        $number = trim($number);

        $validator = Validator::make(
            ['number' => $number],
            ['number' => ['required', 'string', 'max:10', Rule::unique('cars', 'number')->ignore($car->id)]],
            [],
            ['number' => 'car number'],
        );

        if ($validator->fails()) {
            $this->dispatch('notify', message: $validator->errors()->first(), type: 'error');

            return;
        }

        $car->update(['number' => $number]);

        if ($this->sortMode() === 'number') {
            Car::applyNumberOrder();
        }

        unset($this->cars);
    }

    public function updateTime(int $carId, string $time): void
    {
        $car = Car::query()->findOrFail($carId);
        $validator = Validator::make(
            ['time' => trim($time)],
            ['time' => $this->timeRules()],
            [],
            ['time' => 'time'],
        );

        if ($validator->fails()) {
            $this->dispatch('notify', message: $validator->errors()->first(), type: 'error');

            return;
        }

        $car->update(['time_ms' => Car::parseTime($validator->validated()['time'])]);

        if ($this->sortMode() === 'time') {
            Car::applyTimeOrder();
        }

        unset($this->cars);
    }

    /**
     * Drag-and-drop handler: position 0 is fastest.
     */
    public function sortCars(int|string $carId, int $position): void
    {
        $cars = Car::query()->orderBy('sort_order')->orderBy('id')->get();
        $moving = $cars->firstWhere('id', (int) $carId);

        if (! $moving) {
            return;
        }

        $cars = $cars->reject(fn (Car $car) => $car->id === $moving->id)->values();
        $cars->splice(max(0, min($position, $cars->count())), 0, [$moving]);
        $cars->each(fn (Car $car, int $index) => $car->sort_order === $index ? null : $car->update(['sort_order' => $index]));

        Setting::put(Setting::CarsSort, 'manual');
        unset($this->cars);
    }

    public function sortByNumber(): void
    {
        Setting::put(Setting::CarsSort, 'number');
        Car::applyNumberOrder();
        unset($this->cars);
    }

    public function sortByTime(): void
    {
        Setting::put(Setting::CarsSort, 'time');
        Car::applyTimeOrder();
        unset($this->cars);
    }

    public function deleteCar(int $carId): void
    {
        Car::query()->findOrFail($carId)->delete();
        Car::compactSortOrder();
        unset($this->cars);
    }

    /**
     * @return list<string|\Closure>
     */
    protected function timeRules(): array
    {
        return [
            'nullable',
            'string',
            'max:12',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && Car::parseTime((string) $value) === false) {
                    $fail('Use a time like 12.345 or 1:14.567.');
                }
            },
        ];
    }

    public function sortMode(): string
    {
        return Setting::get(Setting::CarsSort, 'number') ?: 'number';
    }

    protected function applyCurrentSort(): void
    {
        match ($this->sortMode()) {
            'time' => Car::applyTimeOrder(),
            default => Car::applyNumberOrder(),
        };
    }
};
?>

<div class="mx-auto max-w-lg">
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <form wire:submit="add" class="flex items-center gap-1.5 border-b border-slate-200 px-2 py-1.5">
            <input
                wire:model="newNumber"
                placeholder="#"
                aria-label="Car number"
                class="w-16 rounded border border-slate-300 px-1.5 py-1 font-mono text-sm font-bold focus:border-amber-500 focus:outline-none"
            >
            <input
                wire:model="newTime"
                placeholder="12.345"
                aria-label="Time"
                inputmode="decimal"
                class="w-20 rounded border border-slate-300 px-1.5 py-1 font-mono text-sm tabular-nums focus:border-amber-500 focus:outline-none"
            >
            <button type="submit" class="rounded bg-amber-400 px-2.5 py-1 text-xs font-black text-slate-900 hover:bg-amber-300">Add</button>
            <span class="ml-auto text-[11px] font-semibold text-slate-400">{{ $this->cars->count() }}</span>
        </form>
        @if ($errors->has('newNumber') || $errors->has('newTime'))
            <p class="px-2 py-1 text-[11px] text-red-600">{{ $errors->first('newNumber') ?: $errors->first('newTime') }}</p>
        @endif

        <div class="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-2 py-1 text-[10px] font-black tracking-wide text-slate-400 uppercase">
            <span class="w-4"></span>
            <span class="w-6">Pos</span>
            <span class="w-14">Car</span>
            <span class="w-20">Time</span>
            <span class="ml-auto flex gap-1">
                <button
                    type="button"
                    wire:click="sortByNumber"
                    @class(['rounded px-1.5 py-0.5', 'bg-slate-900 text-white' => $this->sortMode() === 'number', 'hover:bg-slate-200' => $this->sortMode() !== 'number'])
                >By #</button>
                <button
                    type="button"
                    wire:click="sortByTime"
                    @class(['rounded px-1.5 py-0.5', 'bg-slate-900 text-white' => $this->sortMode() === 'time', 'hover:bg-slate-200' => $this->sortMode() !== 'time'])
                >By time</button>
            </span>
        </div>

        <nav wire:sort="sortCars" class="divide-y divide-slate-100">
            @forelse ($this->cars as $index => $car)
                <div
                    wire:key="car-{{ $car->id }}"
                    wire:sort:item="{{ $car->id }}"
                    class="flex items-center gap-1.5 px-2 py-0.5 hover:bg-slate-50"
                >
                    <span wire:sort:handle title="Drag to rank fastest to slowest" class="w-4 cursor-grab text-center text-slate-300 select-none hover:text-slate-600">&#10303;</span>
                    <span class="w-6 text-right font-mono text-[11px] tabular-nums text-slate-400">{{ $index + 1 }}</span>
                    <input
                        value="{{ $car->number }}"
                        wire:change="updateNumber({{ $car->id }}, $event.target.value)"
                        wire:sort:ignore
                        aria-label="Car number"
                        class="w-14 rounded border border-transparent bg-transparent px-1 py-0.5 font-mono text-sm font-black hover:border-slate-300 focus:border-amber-500 focus:bg-white focus:outline-none"
                    >
                    <input
                        value="{{ $car->formattedTime() }}"
                        wire:change="updateTime({{ $car->id }}, $event.target.value)"
                        wire:sort:ignore
                        placeholder="—"
                        aria-label="Time"
                        inputmode="decimal"
                        class="w-20 rounded border border-transparent bg-transparent px-1 py-0.5 font-mono text-sm tabular-nums text-slate-700 placeholder:text-slate-300 hover:border-slate-300 focus:border-amber-500 focus:bg-white focus:outline-none"
                    >
                    <button
                        type="button"
                        wire:click="deleteCar({{ $car->id }})"
                        wire:confirm="Delete car {{ $car->number }}?"
                        title="Delete {{ $car->number }}"
                        class="ml-auto rounded px-1 text-sm leading-none text-slate-300 hover:bg-red-50 hover:text-red-600"
                    >&times;</button>
                </div>
            @empty
                <p class="px-2 py-6 text-center text-xs text-slate-400">Add a car # and optional time (12.345).</p>
            @endforelse
        </nav>
    </div>
</div>
