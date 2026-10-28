<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\User;
use Database\Seeders\CarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CarsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('cars'))->assertRedirect(route('login'));
    }

    public function test_accounts_without_cars_access_are_forbidden(): void
    {
        $this->actingAs(User::factory()->withAreas(['timing'])->create());

        $this->get(route('cars'))->assertForbidden();
    }

    public function test_page_renders(): void
    {
        $this->actingAs(User::factory()->create());
        Car::factory()->create(['number' => '71']);

        $this->get(route('cars'))
            ->assertOk()
            ->assertSee('71')
            ->assertSee('Add')
            ->assertSee('By #')
            ->assertSee('By time');
    }

    public function test_cars_sort_by_number_like_01_1_2_4_11(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test('pages::cars');

        foreach (['11', '4A', '01', '2', '4', '1'] as $number) {
            $component->set('newNumber', $number)->call('add')->assertHasNoErrors();
        }

        $this->assertSame(['01', '1', '2', '4', '4A', '11'], $component->get('cars')->pluck('number')->all());
    }

    public function test_alphanumeric_car_numbers_are_allowed(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::cars')
            ->set('newNumber', '7BC')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('cars', ['number' => '7BC']);
    }

    public function test_car_number_zero_is_kept(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::cars')->set('newNumber', '0')->call('add')->assertHasNoErrors();

        $this->assertDatabaseHas('cars', ['number' => '0']);
    }

    public function test_duplicate_car_numbers_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        Car::factory()->create(['number' => '4A']);

        Livewire::test('pages::cars')
            ->set('newNumber', '4A')
            ->call('add')
            ->assertHasErrors(['newNumber' => 'unique']);
    }

    public function test_a_blank_car_number_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::cars')->set('newNumber', '')->call('add')->assertHasErrors(['newNumber' => 'required']);
    }

    public function test_car_numbers_cannot_exceed_ten_characters(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::cars')
            ->set('newNumber', '12345678901')
            ->call('add')
            ->assertHasErrors(['newNumber' => 'max']);
    }

    public function test_dragging_ranks_fastest_to_slowest(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test('pages::cars');

        foreach (['01', '1', '2'] as $number) {
            $component->set('newNumber', $number)->call('add');
        }

        $slowest = Car::query()->where('number', '2')->sole();
        $component->call('sortCars', $slowest->id, 0);

        $this->assertSame(['2', '01', '1'], $component->get('cars')->pluck('number')->all());
    }

    public function test_a_new_car_goes_last_after_a_speed_ranking(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test('pages::cars');

        foreach (['1', '2'] as $number) {
            $component->set('newNumber', $number)->call('add');
        }

        $component->call('sortCars', Car::query()->where('number', '2')->value('id'), 0)
            ->set('newNumber', '99')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame(['2', '1', '99'], $component->get('cars')->pluck('number')->all());
    }

    public function test_sort_by_car_number_restores_number_order(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test('pages::cars');

        foreach (['1', '11', '2'] as $number) {
            $component->set('newNumber', $number)->call('add');
        }

        $component->call('sortCars', Car::query()->where('number', '11')->value('id'), 0)
            ->call('sortByNumber');

        $this->assertSame(['1', '2', '11'], $component->get('cars')->pluck('number')->all());
    }

    public function test_deleting_a_car_removes_it(): void
    {
        $this->actingAs(User::factory()->create());
        $car = Car::factory()->create(['number' => '21']);

        Livewire::test('pages::cars')->call('deleteCar', $car->id);

        $this->assertModelMissing($car);
    }

    public function test_car_numbers_are_escaped_on_the_page(): void
    {
        $this->actingAs(User::factory()->create());
        Car::factory()->create(['number' => '<script>']);

        $this->get(route('cars'))
            ->assertOk()
            ->assertDontSee('<script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_car_seeder_skips_outside_local(): void
    {
        $this->seed(CarSeeder::class);

        $this->assertSame(0, Car::query()->count());
    }

    public function test_car_seeder_creates_sample_cars_in_local(): void
    {
        $this->app['env'] = 'local';

        $this->seed(CarSeeder::class);

        $this->assertSame(array_column(CarSeeder::Samples, 0), Car::query()->orderBy('sort_order')->orderBy('id')->pluck('number')->all());
    }

    public function test_sort_by_time_puts_the_fastest_car_first(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test('pages::cars');

        foreach ([['2', '13.000'], ['1', '12.010'], ['4', '12.500'], ['11', '']] as [$number, $time]) {
            $component->set('newNumber', $number)->set('newTime', $time)->call('add')->assertHasNoErrors();
        }

        $component->call('sortByTime');

        $this->assertSame(['1', '4', '2', '11'], $component->get('cars')->pluck('number')->all());
    }

    public function test_a_faster_car_inserts_by_time_when_sorted_by_time(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test('pages::cars');

        $component->set('newNumber', '1')->set('newTime', '12.500')->call('add');
        $component->call('sortByTime')
            ->set('newNumber', '7')
            ->set('newTime', '12.100')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame(['7', '1'], $component->get('cars')->pluck('number')->all());
    }

    public function test_minute_times_are_stored_and_sorted(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::cars')
            ->set('newNumber', '21')
            ->set('newTime', '1:14.567')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame(74567, Car::query()->where('number', '21')->value('time_ms'));
        $this->assertSame('1:14.567', Car::query()->where('number', '21')->first()->formattedTime());
    }

    public function test_invalid_times_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::cars')
            ->set('newNumber', '1')
            ->set('newTime', 'fast')
            ->call('add')
            ->assertHasErrors('newTime')
            ->assertSee('Use a time like 12.345 or 1:14.567.');
    }
}
