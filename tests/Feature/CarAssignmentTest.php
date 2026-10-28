<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\Setting;
use App\Models\User;
use App\Models\VmixField;
use App\Models\VmixGroup;
use App\Models\VmixSection;
use App\Services\VmixFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CarAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_heaviest_driver_gets_the_fastest_car_and_lightest_gets_the_slowest(): void
    {
        $type = RaceType::factory()->create();
        $race = Race::factory()->for($type)->create();
        $fast = Car::factory()->create(['number' => '7', 'time_ms' => 10000]);
        $middle = Car::factory()->create(['number' => '11', 'time_ms' => 11000]);
        $slow = Car::factory()->create(['number' => '99', 'time_ms' => 12000]);
        $this->attachPool($type, [$fast, $middle, $slow]);

        $heavy = Competitor::factory()->create(['weight' => 210.0]);
        $mid = Competitor::factory()->create(['weight' => 175.0]);
        $light = Competitor::factory()->create(['weight' => 140.0]);

        $race->syncLineup([$light->id, $heavy->id, $mid->id]);

        $assigned = $this->assignedCars($race);
        $this->assertSame('7', $assigned[$heavy->id]);
        $this->assertSame('11', $assigned[$mid->id]);
        $this->assertSame('99', $assigned[$light->id]);
    }

    public function test_drivers_without_weight_get_the_remaining_slower_cars(): void
    {
        $type = RaceType::factory()->create();
        $race = Race::factory()->for($type)->create();
        $fast = Car::factory()->create(['number' => '1', 'time_ms' => 10000]);
        $slow = Car::factory()->create(['number' => '2', 'time_ms' => 12000]);
        $this->attachPool($type, [$fast, $slow]);

        $weighed = Competitor::factory()->create(['weight' => 180.0]);
        $unknown = Competitor::factory()->create(['weight' => null]);

        $race->syncLineup([$unknown->id, $weighed->id]);

        $assigned = $this->assignedCars($race);
        $this->assertSame('1', $assigned[$weighed->id]);
        $this->assertSame('2', $assigned[$unknown->id]);
    }

    public function test_extra_drivers_past_the_car_group_are_left_unassigned(): void
    {
        $type = RaceType::factory()->create(['cars_per_race' => 1]);
        $race = Race::factory()->for($type)->create();
        $car = Car::factory()->create(['number' => '7', 'time_ms' => 10000]);
        $this->attachPool($type, [$car]);

        $heavy = Competitor::factory()->create(['weight' => 200.0]);
        $light = Competitor::factory()->create(['weight' => 150.0]);

        $race->syncLineup([$heavy->id, $light->id]);

        $assigned = $this->assignedCars($race);
        $this->assertSame('7', $assigned[$heavy->id]);
        $this->assertNull($assigned[$light->id]);
    }

    public function test_heat_groups_alternate_through_the_pool_by_cars_per_race(): void
    {
        $type = RaceType::factory()->create(['cars_per_race' => 5]);
        $races = Race::factory()->for($type)->count(3)->sequence(
            ['name' => 'Heat 1', 'sort_order' => 1],
            ['name' => 'Heat 2', 'sort_order' => 2],
            ['name' => 'Heat 3', 'sort_order' => 3],
        )->create();
        $cars = collect(range(1, 10))->map(fn (int $number) => Car::factory()->create([
            'number' => (string) $number,
            'time_ms' => 10000 + ($number * 100),
        ]));
        $this->attachPool($type, $cars->all());

        $this->assertSame(['1', '3', '5', '7', '9'], $type->carsForRace($races[0])->pluck('number')->all());
        $this->assertSame(['2', '4', '6', '8', '10'], $type->carsForRace($races[1])->pluck('number')->all());
        $this->assertSame(['1', '3', '5', '7', '9'], $type->carsForRace($races[2])->pluck('number')->all());
    }

    public function test_a_pool_no_larger_than_cars_per_race_is_shared_by_every_race(): void
    {
        $type = RaceType::factory()->create(['cars_per_race' => 5]);
        $first = Race::factory()->for($type)->create(['sort_order' => 1]);
        $second = Race::factory()->for($type)->create(['sort_order' => 2]);
        $cars = collect(range(1, 5))->map(fn (int $number) => Car::factory()->create(['number' => (string) $number]));
        $this->attachPool($type, $cars->all());

        $this->assertSame(['1', '2', '3', '4', '5'], $type->carsForRace($first)->pluck('number')->all());
        $this->assertSame(['1', '2', '3', '4', '5'], $type->carsForRace($second)->pluck('number')->all());
    }

    public function test_blank_cars_per_race_gives_every_race_the_whole_pool(): void
    {
        $type = RaceType::factory()->create(['cars_per_race' => null]);
        $first = Race::factory()->for($type)->create(['sort_order' => 1]);
        $second = Race::factory()->for($type)->create(['sort_order' => 2]);
        $cars = collect(range(1, 10))->map(fn (int $number) => Car::factory()->create(['number' => (string) $number]));
        $this->attachPool($type, $cars->all());

        $this->assertSame($cars->pluck('number')->all(), $type->carsForRace($first)->pluck('number')->all());
        $this->assertSame($cars->pluck('number')->all(), $type->carsForRace($second)->pluck('number')->all());
    }

    public function test_dropping_drivers_on_a_lineup_assigns_cars_by_weight(): void
    {
        $type = RaceType::factory()->create();
        $race = Race::factory()->for($type)->create();
        $fast = Car::factory()->create(['number' => '7', 'time_ms' => 10000]);
        $slow = Car::factory()->create(['number' => '99', 'time_ms' => 12000]);
        $this->attachPool($type, [$fast, $slow]);
        $heavy = Competitor::factory()->create(['weight' => 200.0]);
        $light = Competitor::factory()->create(['weight' => 140.0]);

        Livewire::test('pages::lineups')
            ->call('placeDriver', 'c'.$light->id, 0, $race->id)
            ->call('placeDriver', 'c'.$heavy->id, 1, $race->id);

        $assigned = $this->assignedCars($race);
        $this->assertSame('7', $assigned[$heavy->id]);
        $this->assertSame('99', $assigned[$light->id]);
    }

    public function test_turning_on_alternate_groups_splits_a_ten_car_pool_across_consecutive_races(): void
    {
        $type = RaceType::factory()->create(['cars_per_race' => null]);
        $races = Race::factory()->for($type)->count(3)->sequence(
            ['sort_order' => 1],
            ['sort_order' => 2],
            ['sort_order' => 3],
        )->create();
        $cars = collect(range(1, 10))->map(fn (int $number) => Car::factory()->create(['number' => (string) $number]));
        $this->attachPool($type, $cars->all());

        Livewire::test('pages::lineups')->call('toggleAlternateGroups', $type->id);

        $type->refresh()->load(['cars', 'races']);
        $this->assertSame(5, $type->cars_per_race);
        $this->assertSame(['1', '3', '5', '7', '9'], $type->carsForRace($races[0])->pluck('number')->all());
        $this->assertSame(['2', '4', '6', '8', '10'], $type->carsForRace($races[1])->pluck('number')->all());
        $this->assertSame(['1', '3', '5', '7', '9'], $type->carsForRace($races[2])->pluck('number')->all());
        $this->assertNotEquals(
            $type->carsForRace($races[0])->pluck('id')->all(),
            $type->carsForRace($races[1])->pluck('id')->all(),
        );
    }

    public function test_turning_off_alternate_groups_gives_every_race_the_same_cars(): void
    {
        $type = RaceType::factory()->create(['cars_per_race' => 5]);
        $first = Race::factory()->for($type)->create(['sort_order' => 1]);
        $second = Race::factory()->for($type)->create(['sort_order' => 2]);
        $cars = collect(range(1, 10))->map(fn (int $number) => Car::factory()->create(['number' => (string) $number]));
        $this->attachPool($type, $cars->all());

        Livewire::test('pages::lineups')->call('toggleAlternateGroups', $type->id);

        $type->refresh()->load(['cars', 'races']);
        $this->assertNull($type->cars_per_race);
        $this->assertSame($cars->pluck('number')->all(), $type->carsForRace($first)->pluck('number')->all());
        $this->assertSame($cars->pluck('number')->all(), $type->carsForRace($second)->pluck('number')->all());
    }

    public function test_adding_cars_to_an_alternating_pool_fills_the_next_group_each_time(): void
    {
        $type = RaceType::factory()->create(['cars_per_race' => 5, 'cars_allowed' => 10]);
        $first = Race::factory()->for($type)->create(['sort_order' => 1]);
        $second = Race::factory()->for($type)->create(['sort_order' => 2]);
        $cars = collect(['01', '1', '2'])->map(fn (string $number) => Car::factory()->create(['number' => $number]));

        $page = Livewire::test('pages::lineups');
        $cars->each(fn (Car $car) => $page->call('togglePoolCar', $type->id, $car->id));

        $type->refresh()->load('cars');
        $this->assertSame(['01', '2'], $type->carsForRace($first)->pluck('number')->all());
        $this->assertSame(['1'], $type->carsForRace($second)->pluck('number')->all());
    }

    public function test_a_type_cannot_add_more_cars_than_it_allows(): void
    {
        $type = RaceType::factory()->create(['cars_allowed' => 2]);
        [$first, $second, $third] = Car::factory()->count(3)->create();

        Livewire::test('pages::lineups')
            ->call('togglePoolCar', $type->id, $first->id)
            ->call('togglePoolCar', $type->id, $second->id)
            ->call('togglePoolCar', $type->id, $third->id)
            ->assertDispatched('notify', type: 'error');

        $this->assertSame([$first->id, $second->id], $type->fresh()->cars->pluck('id')->all());
    }

    public function test_lineups_renders_every_car_in_the_type_pool(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat', 'cars_per_race' => 5, 'cars_allowed' => 10]);
        $cars = collect(range(1, 10))->map(fn (int $number) => Car::factory()->create(['number' => (string) $number]));
        $this->attachPool($type, $cars->all());

        $html = Livewire::test('pages::lineups')->html();

        $this->assertStringContainsString('>G1</span>', $html);
        $this->assertStringContainsString('>G2</span>', $html);

        foreach ($cars as $car) {
            $this->assertStringContainsString('Remove '.$car->number.' from Heat', $html);
        }
    }

    public function test_lineups_can_set_how_many_cars_a_type_allows(): void
    {
        $type = RaceType::factory()->create(['cars_allowed' => null]);

        Livewire::test('pages::lineups')
            ->call('updateCarsAllowed', $type->id, '12')
            ->assertHasNoErrors();

        $this->assertSame(12, $type->refresh()->cars_allowed);
    }

    public function test_lineups_can_configure_the_pool_and_cars_per_race(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat', 'cars_per_race' => null]);
        Race::factory()->for($type)->create();
        $first = Car::factory()->create(['number' => '7']);
        $second = Car::factory()->create(['number' => '11']);

        Livewire::test('pages::lineups')
            ->call('togglePoolCar', $type->id, $first->id)
            ->call('togglePoolCar', $type->id, $second->id)
            ->call('updateCarsPerRace', $type->id, '5')
            ->call('togglePoolCar', $type->id, $first->id)
            ->assertHasNoErrors();

        $type->refresh();
        $this->assertSame(5, $type->cars_per_race);
        $this->assertSame(['11'], $type->cars()->pluck('number')->all());
    }

    public function test_invalid_cars_per_race_is_rejected(): void
    {
        $type = RaceType::factory()->create();

        Livewire::test('pages::lineups')
            ->call('updateCarsPerRace', $type->id, '0')
            ->assertDispatched('notify', type: 'error');

        $this->assertNull($type->refresh()->cars_per_race);
    }

    public function test_new_race_type_can_set_cars_per_race(): void
    {
        Livewire::test('pages::lineups')
            ->set('newTypeName', 'Heat')
            ->set('newTypeRaceCount', 4)
            ->set('newTypeCarsAllowed', 10)
            ->set('newTypeCarsPerRace', 5)
            ->call('addRaceType')
            ->assertHasNoErrors();

        $type = RaceType::query()->where('name', 'Heat')->first();
        $this->assertSame(10, $type->cars_allowed);
        $this->assertSame(5, $type->cars_per_race);
        $this->assertSame(4, $type->races()->count());
    }

    public function test_transponder_csv_keeps_the_drivers_own_number_not_the_assigned_car(): void
    {
        $type = RaceType::factory()->create();
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $car = Car::factory()->create(['number' => '7', 'time_ms' => 10000]);
        $this->attachPool($type, [$car]);
        $driver = Competitor::factory()->create([
            'transponder' => '108571',
            'car_number' => '35',
            'last_name' => 'Shultz',
            'first_name' => 'Jason',
            'weight' => 200.0,
        ]);

        $race->syncLineup([$driver->id]);

        $this->assertSame('7', $this->assignedCars($race)[$driver->id]);
        $this->assertSame(
            "\"Trns ID\",#,\"Last Name\",\"First Name\",Misc,\"Backup Trns ID\"\r\n"
            ."108571,35,Shultz,Jason,,\r\n",
            $this->get(route('exports.transponders', $race))->streamedContent(),
        );
    }

    public function test_pill_draw_deals_drivers_across_existing_races(): void
    {
        $type = RaceType::factory()->create(['name' => 'Hotlaps', 'cars_per_race' => 5]);
        Race::factory()->for($type)->create(['name' => 'Hotlaps 1', 'sort_order' => 1]);
        Race::factory()->for($type)->create(['name' => 'Hotlaps 2', 'sort_order' => 2]);
        $cars = collect(['01', '1', '2', '4', '5', '6', '7', '8', '9', '10'])->map(
            fn (string $number, int $index) => Car::factory()->create([
                'number' => $number,
                'time_ms' => 20000 - ($index * 100),
            ]),
        );
        $this->attachPool($type, $cars->all());
        collect([40, 11, 22, 13, 14, 35, 16, 17, 18, 19])->each(
            fn (int $pill) => Competitor::factory()->create(['pill_number' => $pill]),
        );

        Livewire::test('pages::lineups')->call('assignByPillDraw', $type->id);

        $type->refresh()->load(['races.entries.car', 'races.entries.competitor', 'cars']);
        $races = $type->racesInProgramOrder();
        $this->assertSame(['Hotlaps 1', 'Hotlaps 2'], $races->pluck('name')->all());
        $this->assertSame(
            [11, 14, 17, 19, 35],
            $races[0]->entries->pluck('competitor.pill_number')->all(),
        );
        $this->assertSame(
            [13, 16, 18, 22, 40],
            $races[1]->entries->pluck('competitor.pill_number')->all(),
        );
        $this->assertSame(
            ['01', '2', '5', '7', '9'],
            $races[0]->entries->pluck('car.number')->sort(SORT_NATURAL)->values()->all(),
        );
        $this->assertSame(
            ['1', '4', '6', '8', '10'],
            $races[1]->entries->pluck('car.number')->sort(SORT_NATURAL)->values()->all(),
        );
        $this->assertTrue($type->assigns_by_pill);
        $this->assertFalse($type->combines_groups);
    }

    public function test_pill_draw_without_races_is_rejected(): void
    {
        $type = RaceType::factory()->create(['name' => 'Hotlaps', 'cars_per_race' => 3]);
        Competitor::factory()->count(7)->sequence(
            ['pill_number' => 1],
            ['pill_number' => 2],
            ['pill_number' => 3],
            ['pill_number' => 4],
            ['pill_number' => 5],
            ['pill_number' => 6],
            ['pill_number' => 7],
        )->create();

        Livewire::test('pages::lineups')
            ->call('assignByPillDraw', $type->id)
            ->assertDispatched('notify', message: 'Add races to this type before assigning by pill.', type: 'error');

        $this->assertSame(0, $type->fresh()->races()->count());
    }

    public function test_pill_draw_keeps_every_race_and_deals_leftover_drivers(): void
    {
        $type = RaceType::factory()->create(['name' => 'Hotlaps', 'cars_per_race' => 5]);
        $races = Race::factory()->for($type)->count(3)->sequence(
            ['name' => 'Hotlaps 1', 'sort_order' => 1],
            ['name' => 'Hotlaps 2', 'sort_order' => 2],
            ['name' => 'Hotlaps 3', 'sort_order' => 3],
        )->create();
        $drivers = Competitor::factory()->count(5)->sequence(
            ['pill_number' => 1],
            ['pill_number' => 2],
            ['pill_number' => 3],
            ['pill_number' => 4],
            ['pill_number' => 5],
        )->create();
        $races[2]->syncLineup([$drivers[0]->id]);

        $type->assignByPillDraw();

        $this->assertSame(3, $type->fresh()->races()->count());
        $this->assertSame(
            [1, 4],
            $races[0]->fresh()->entries()->with('competitor')->get()->pluck('competitor.pill_number')->all(),
        );
        $this->assertSame(
            [2, 5],
            $races[1]->fresh()->entries()->with('competitor')->get()->pluck('competitor.pill_number')->all(),
        );
        $this->assertSame(
            [3],
            $races[2]->fresh()->entries()->with('competitor')->get()->pluck('competitor.pill_number')->all(),
        );
    }

    public function test_drivers_without_pills_are_placed_after_the_pill_draw(): void
    {
        $type = RaceType::factory()->create(['cars_per_race' => 2]);
        Race::factory()->for($type)->create();
        $undrawn = Competitor::factory()->create(['pill_number' => null]);
        $second = Competitor::factory()->create(['pill_number' => 8]);
        $first = Competitor::factory()->create(['pill_number' => 3]);

        $type->assignByPillDraw();

        $this->assertSame(
            [$first->id, $second->id, $undrawn->id],
            $type->fresh()->racesInProgramOrder()->first()->entries->pluck('competitor_id')->all(),
        );
    }

    public function test_pill_draw_with_no_competitors_is_rejected(): void
    {
        $type = RaceType::factory()->create();
        Race::factory()->for($type)->create();

        Livewire::test('pages::lineups')
            ->call('assignByPillDraw', $type->id)
            ->assertDispatched('notify', message: 'Add competitors before assigning by pill.', type: 'error');

        $this->assertSame(0, $type->fresh()->races()->first()->entries()->count());
    }

    public function test_vmix_driver_fields_include_the_assigned_car_from_the_live_race(): void
    {
        $type = RaceType::factory()->create();
        $race = Race::factory()->for($type)->create();
        $car = Car::factory()->create(['number' => '7', 'time_ms' => 10000]);
        $this->attachPool($type, [$car]);
        $driver = Competitor::factory()->create(['car_number' => '35', 'first_name' => 'Jason', 'last_name' => 'Shultz', 'weight' => 200.0]);
        $race->syncLineup([$driver->id]);
        Setting::put(Setting::ActiveRace, (string) $race->id);

        $section = VmixSection::factory()->onAir()->create(['key' => 'interview']);
        $group = VmixGroup::factory()->for($section, 'section')->create();
        VmixField::factory()->for($group, 'group')->create([
            'key' => 'guest',
            'type' => VmixField::TypeDriver,
            'live_value' => (string) $driver->id,
        ]);

        $row = $section->data();
        $this->assertSame('35', $row['guest_number']);
        $this->assertSame('7', $row['guest_assigned_car']);
        $lineup = app(VmixFeed::class)->lineup($race)['Lineup'][0];
        $this->assertSame('35', $lineup['Number1.Text']);
        $this->assertSame('7', $lineup['Driver1CarAssignment']);
    }

    /**
     * @param  list<Car>  $cars
     */
    private function attachPool(RaceType $type, array $cars): void
    {
        foreach (array_values($cars) as $index => $car) {
            $type->cars()->attach($car->id, ['sort_order' => $index]);
        }
    }

    /**
     * @return array<int, string|null>
     */
    private function assignedCars(Race $race): array
    {
        return $race->entries()->with('car')->get()
            ->mapWithKeys(fn ($entry) => [$entry->competitor_id => $entry->car?->number])
            ->all();
    }
}
