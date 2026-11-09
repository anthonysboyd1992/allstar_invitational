<?php

namespace Tests\Feature;

use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_timing_accounts_only_reach_timing(): void
    {
        $race = Race::factory()->create();
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('timing'))->assertOk();
        $this->get(route('exports.transponders', $race))->assertOk();
        $this->get(route('exports.race-zip', $race))->assertOk();
        $this->get(route('exports.type-transponders', $race->raceType))->assertOk();
        $this->get(route('exports.type-zip', $race->raceType))->assertOk();

        foreach (['competitors', 'cars', 'lineups', 'results', 'vmix', 'vmix-data', 'settings', 'users'] as $routeName) {
            $this->get(route($routeName))->assertForbidden();
        }
    }

    public function test_vmix_accounts_only_reach_vmix_pages(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['vmix']['areas'])->create());

        $this->get(route('vmix'))->assertOk();
        $this->get(route('vmix-data'))->assertOk();
        $this->get(route('timing'))->assertForbidden();
        $this->get(route('settings'))->assertForbidden();
        $this->get(route('users'))->assertForbidden();
    }

    public function test_home_sends_users_to_their_first_area(): void
    {
        $this->actingAs(User::factory()->withAreas(['vmix-data'])->create());
        $this->get(route('home'))->assertRedirect(route('vmix-data'));

        $this->actingAs(User::factory()->withAreas([])->create());
        $this->get(route('home'))->assertForbidden();
    }

    public function test_nav_only_lists_allowed_areas(): void
    {
        $this->actingAs(User::factory()->withAreas(['timing'])->create());

        $this->get(route('timing'))
            ->assertSee('href="'.route('timing').'"', false)
            ->assertDontSee('href="'.route('cars').'"', false)
            ->assertDontSee('href="'.route('vmix').'"', false)
            ->assertDontSee('href="'.route('users').'"', false)
            ->assertDontSee('href="'.route('settings').'"', false);
    }

    public function test_existing_lineup_operators_gain_cars_access(): void
    {
        $operator = User::factory()->withAreas(['competitors', 'lineups', 'settings', 'users'])->create();
        $timing = User::factory()->withAreas(['timing'])->create();

        (require database_path('migrations/2026_10_28_173004_add_cars_area_to_existing_users.php'))->up();

        $this->assertSame(
            ['competitors', 'cars', 'lineups', 'settings', 'users'],
            $operator->fresh()->permissions,
        );
        $this->assertSame(['timing'], $timing->fresh()->permissions);
    }

    public function test_existing_lineup_operators_gain_results_access(): void
    {
        $operator = User::factory()->withAreas(['competitors', 'lineups', 'settings', 'users'])->create();
        $timing = User::factory()->withAreas(['timing'])->create();

        (require database_path('migrations/2026_10_28_180047_add_results_area_to_existing_users.php'))->up();

        $this->assertSame(
            ['competitors', 'lineups', 'results', 'settings', 'users'],
            $operator->fresh()->permissions,
        );
        $this->assertSame(['timing'], $timing->fresh()->permissions);
    }

    public function test_admins_can_add_a_user_with_a_preset(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::users')
            ->call('openCreate')
            ->set('name', 'Graphics Op')
            ->set('email', 'graphics@allstar.test')
            ->set('password', 'secret-pass')
            ->call('applyPreset', 'vmix')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $user = User::query()->where('email', 'graphics@allstar.test')->sole();
        $this->assertSame(['vmix', 'vmix-data'], $user->permissions);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }

    public function test_editing_keeps_the_password_when_left_blank(): void
    {
        $this->actingAs(User::factory()->create());
        $user = User::factory()->withAreas(['timing'])->create(['password' => 'original-pass']);

        Livewire::test('pages::users')
            ->call('openEdit', $user->id)
            ->set('areas', ['timing', 'vmix'])
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame(['timing', 'vmix'], $user->permissions);
        $this->assertTrue(Hash::check('original-pass', $user->password));
    }

    public function test_admins_cannot_lock_themselves_out(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test('pages::users')
            ->call('openEdit', $admin->id)
            ->set('areas', ['timing'])
            ->call('save')
            ->assertHasErrors('areas')
            ->call('delete', $admin->id);

        $this->assertTrue($admin->fresh()->canAccessArea('users'));
    }

    public function test_admins_can_delete_other_users(): void
    {
        $this->actingAs(User::factory()->create());
        $user = User::factory()->create();

        Livewire::test('pages::users')->call('delete', $user->id);

        $this->assertModelMissing($user);
    }

    public function test_users_page_rejects_non_admins(): void
    {
        $this->actingAs(User::factory()->withAreas(['timing'])->create());

        Livewire::test('pages::users')->assertForbidden();
    }
}
