<?php

namespace Tests\Feature;

use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $race = Race::factory()->create();

        foreach ([route('competitors'), route('competitors.print'), route('lineups'), route('lineups.print'), route('results'), route('results.print'), route('vmix'), route('settings'), route('exports.transponders', $race), route('exports.laps', $race), route('exports.type-transponders', $race->raceType), route('exports.type-laps', $race->raceType)] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_vmix_feeds_stay_public(): void
    {
        $this->get(route('feeds.lineup'))->assertOk();
        $this->get(route('feeds.driver'))->assertOk();
    }

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Log in');
    }

    public function test_users_can_log_in(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        Livewire::test('pages::login')
            ->set('email', $user->email)
            ->set('password', 'secret-pass')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        Livewire::test('pages::login')
            ->set('email', $user->email)
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
