<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TimingFeedResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimingFeedResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_results_json_returns_403_when_no_token_is_provided(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results'), $this->payload())->assertForbidden();
    }

    public function test_results_json_returns_403_when_the_token_is_wrong(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results', ['token' => 'nope']), $this->payload())->assertForbidden();
    }

    public function test_results_json_stores_a_standings_snapshot(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), $this->payload())
            ->assertCreated()
            ->assertJsonPath('name', 'Heat 1');

        $this->assertDatabaseHas('timing_feed_results', [
            'name' => 'Heat 1',
            'flag' => 'Finish',
        ]);
        $this->assertSame(1, TimingFeedResult::query()->count());
        $this->assertSame('12', TimingFeedResult::query()->first()->orderedStandings()->first()['number']);
        $this->assertSame('heat-1', TimingFeedResult::query()->first()->source_key);
    }

    public function test_results_json_updates_the_same_session_instead_of_creating_another(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), $this->payload())
            ->assertCreated();

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), $this->payload([
            'flag' => 'Green',
            'standings' => [[
                'position' => 1,
                'number' => '7',
                'transponder' => '11111',
                'registration' => '7',
                'laps' => 3,
                'total_time' => '00:01:00.000',
                'first_name' => 'Bob',
                'last_name' => 'Second',
            ]],
        ]))->assertOk();

        $this->assertSame(1, TimingFeedResult::query()->count());
        $this->assertSame('Green', TimingFeedResult::query()->first()->flag);
        $this->assertSame('7', TimingFeedResult::query()->first()->orderedStandings()->first()['number']);
    }

    public function test_results_json_keeps_updating_a_session_after_it_is_applied(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');
        $result = TimingFeedResult::factory()->create([
            'source_key' => 'heat-1',
            'flag' => 'Finish',
            'applied_at' => now(),
        ]);

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), $this->payload([
            'flag' => 'Green',
            'live' => true,
        ]))->assertOk();

        $result = $result->fresh();
        $this->assertSame('Green', $result->flag);
        $this->assertNotNull($result->applied_at);
        $this->assertFalse($result->is_live);
        $this->assertSame(0, TimingFeedResult::query()->live()->count());
        $this->assertSame(1, TimingFeedResult::query()->count());
    }

    public function test_results_json_marks_the_posted_session_live_and_clears_the_previous_live_session(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');
        $previous = TimingFeedResult::factory()->live()->create(['source_key' => 'heat-0']);

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), $this->payload([
            'live' => true,
        ]))->assertCreated();

        $this->assertFalse($previous->fresh()->is_live);
        $this->assertTrue(TimingFeedResult::query()->where('source_key', 'heat-1')->sole()->is_live);
    }

    public function test_results_json_keeps_updating_an_ignored_session_without_making_it_live(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');
        $result = TimingFeedResult::factory()->ignored()->create([
            'source_key' => 'heat-1',
            'flag' => 'Finish',
        ]);

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), $this->payload([
            'flag' => 'Green',
            'live' => true,
        ]))->assertOk();

        $result = $result->fresh();
        $this->assertSame('Green', $result->flag);
        $this->assertNotNull($result->ignored_at);
        $this->assertFalse($result->is_live);
        $this->assertSame(0, TimingFeedResult::query()->live()->count());
    }

    public function test_disconnect_json_clears_the_live_session(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');
        $result = TimingFeedResult::factory()->live()->create();

        $this->postJson(route('feeds.timing-results-disconnect', ['token' => 'secret-token']))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertFalse($result->fresh()->is_live);
    }

    public function test_disconnect_json_returns_403_when_no_token_is_provided(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');
        TimingFeedResult::factory()->live()->create();

        $this->postJson(route('feeds.timing-results-disconnect'))->assertForbidden();
        $this->assertTrue(TimingFeedResult::query()->sole()->is_live);
    }

    public function test_results_json_accepts_numeric_car_numbers_and_transponders(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), [
            'source_key' => 17,
            'name' => 'Heat 1',
            'live' => true,
            'standings' => [[
                'position' => '1',
                'number' => 12,
                'transponder' => 52474,
                'registration' => 12,
                'laps' => '3',
                'total_time' => '00:01:00.000',
                'first_name' => 'Ann',
                'last_name' => 'First',
            ]],
        ])->assertCreated();

        $standing = TimingFeedResult::query()->sole()->orderedStandings()->first();
        $this->assertSame('12', $standing['number']);
        $this->assertSame('52474', $standing['transponder']);
        $this->assertSame(3, $standing['laps']);
    }

    public function test_results_json_stores_lap_times_passes_and_race_distance(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), [
            'source_key' => 'heat-1',
            'name' => 'Heat 1',
            'live' => true,
            'laps_to_go' => 5,
            'race_laps' => 8,
            'standings' => [[
                'position' => 1,
                'number' => '12',
                'transponder' => '52474',
                'registration' => '12',
                'laps' => 3,
                'total_time' => '00:01:00.000',
                'last_lap' => '16.400',
                'best_lap' => '16.200',
                'start_position' => 4,
                'passes' => 3,
                'first_name' => 'Ann',
                'last_name' => 'First',
            ]],
        ])->assertCreated();

        $result = TimingFeedResult::query()->sole();
        $standing = $result->orderedStandings()->first();

        $this->assertSame(5, $result->laps_to_go);
        $this->assertSame(8, $result->race_laps);
        $this->assertSame('16.400', $standing['last_lap']);
        $this->assertSame('16.200', $standing['best_lap']);
        $this->assertSame(4, $standing['start_position']);
        $this->assertSame(3, $standing['passes']);
    }

    public function test_results_json_stores_westhold_transponder_ids_used_as_positions(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), [
            'source_key' => 'heat-1',
            'name' => 'Heat 1',
            'live' => true,
            'standings' => [[
                'position' => 52474,
                'number' => '12',
                'transponder' => '52474',
                'registration' => '52474',
                'laps' => 1,
                'total_time' => '00:00:23.100',
                'first_name' => 'Ann',
                'last_name' => 'First',
            ]],
        ])->assertCreated();

        $this->assertSame(1, TimingFeedResult::query()->live()->count());
        $this->assertSame('12', TimingFeedResult::query()->sole()->orderedStandings()->first()['number']);
    }

    public function test_results_json_keeps_best_lap_when_a_later_snapshot_sends_zero(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), [
            'source_key' => 'heat-1',
            'name' => 'Heat 1',
            'live' => true,
            'standings' => [[
                'position' => 1,
                'number' => '12',
                'transponder' => '52474',
                'last_lap' => '00:00:06.090',
                'best_lap' => '00:00:05.370',
                'first_name' => 'Ann',
                'last_name' => 'First',
            ]],
        ])->assertCreated();

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), [
            'source_key' => 'heat-1',
            'name' => 'Heat 1',
            'live' => true,
            'standings' => [[
                'position' => 1,
                'number' => '12',
                'transponder' => '52474',
                'last_lap' => '00:00:00.000',
                'best_lap' => '00:00:00.000',
                'first_name' => 'Ann',
                'last_name' => 'First',
            ]],
        ])->assertOk();

        $standing = TimingFeedResult::query()->sole()->orderedStandings()->first();

        $this->assertSame('00:00:06.090', $standing['last_lap']);
        $this->assertSame('00:00:05.370', $standing['best_lap']);
    }

    public function test_results_json_uses_last_lap_as_best_when_best_is_zero(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), [
            'source_key' => 'heat-1',
            'name' => 'Heat 1',
            'live' => true,
            'standings' => [[
                'position' => 1,
                'number' => '12',
                'transponder' => '52474',
                'last_lap' => '5.370',
                'best_lap' => '0',
                'first_name' => 'Ann',
                'last_name' => 'First',
            ]],
        ])->assertCreated();

        $standing = TimingFeedResult::query()->sole()->orderedStandings()->first();

        $this->assertSame('5.370', $standing['last_lap']);
        $this->assertSame('5.370', $standing['best_lap']);
    }

    public function test_results_json_stores_a_live_session_before_any_cars_have_passed(): void
    {
        Setting::put(Setting::TimingFeedToken, 'secret-token');

        $this->postJson(route('feeds.timing-results', ['token' => 'secret-token']), [
            'source_key' => 'heat-1',
            'name' => 'Heat 1',
            'flag' => 'Green',
            'live' => true,
            'standings' => [],
        ])->assertCreated();

        $result = TimingFeedResult::query()->sole();
        $this->assertSame('Heat 1', $result->name);
        $this->assertTrue($result->is_live);
        $this->assertSame([], $result->standings);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{source_key: string, name: string, flag: string, standings: list<array{position: int, number: string, transponder: string, registration: string, laps: int, total_time: string, first_name: string, last_name: string}>}
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'source_key' => 'heat-1',
            'name' => 'Heat 1',
            'flag' => 'Finish',
            'standings' => [
                [
                    'position' => 1,
                    'number' => '12',
                    'transponder' => '52474',
                    'registration' => '12',
                    'laps' => 8,
                    'total_time' => '00:02:14.321',
                    'first_name' => 'Ann',
                    'last_name' => 'First',
                ],
            ],
        ], $overrides);
    }
}
