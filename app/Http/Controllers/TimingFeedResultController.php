<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\TimingFeedResult;
use App\Services\TimingFeedStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimingFeedResultController extends Controller
{
    /**
     * Store a standings snapshot the Windows puller sent from RMonitor.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeTimingFeed($request);
        $this->stringifyStandingFields($request);

        $validated = $request->validate([
            'source_key' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'flag' => ['nullable', 'string', 'max:50'],
            'laps_to_go' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'race_laps' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'live' => ['sometimes', 'boolean'],
            'standings' => ['present', 'array', 'max:200'],
            'standings.*.position' => ['required', 'integer', 'min:0', 'max:999999'],
            'standings.*.number' => ['nullable', 'string', 'max:50'],
            'standings.*.transponder' => ['nullable', 'string', 'max:50'],
            'standings.*.registration' => ['nullable', 'string', 'max:50'],
            'standings.*.laps' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'standings.*.total_time' => ['nullable', 'string', 'max:50'],
            'standings.*.last_lap' => ['nullable', 'string', 'max:50'],
            'standings.*.best_lap' => ['nullable', 'string', 'max:50'],
            'standings.*.start_position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'standings.*.passes' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'standings.*.first_name' => ['nullable', 'string', 'max:100'],
            'standings.*.last_name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = TimingFeedResult::query()->firstOrNew([
            'source_key' => $validated['source_key'],
        ]);
        $created = ! $result->exists;

        $result->fill([
            'name' => $validated['name'],
            'flag' => $validated['flag'] ?? null,
            'laps_to_go' => $validated['laps_to_go'] ?? null,
            'race_laps' => $validated['race_laps'] ?? null,
            'standings' => (new TimingFeedStats)->withTrackedLaps(
                collect($validated['standings']),
                $result->exists ? $result->orderedStandings() : collect(),
            )->all(),
        ])->save();

        if ($result->isAvailable()) {
            $result->markLive($request->boolean('live'));
        }

        return response()->json([
            'id' => $result->id,
            'name' => $result->name,
        ], $created ? 201 : 200);
    }

    /**
     * The Windows puller closed its RMonitor connection.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $this->authorizeTimingFeed($request);

        TimingFeedResult::clearLive();

        return response()->json(['ok' => true]);
    }

    /**
     * Race Manager often sends car numbers and transponders as JSON numbers.
     */
    private function stringifyStandingFields(Request $request): void
    {
        $standings = $request->input('standings');

        if (! is_array($standings)) {
            return;
        }

        $normalized = [];

        foreach ($standings as $row) {
            if (! is_array($row)) {
                $normalized[] = $row;

                continue;
            }

            foreach (['number', 'transponder', 'registration', 'total_time', 'last_lap', 'best_lap', 'first_name', 'last_name'] as $field) {
                if (array_key_exists($field, $row) && $row[$field] !== null) {
                    $row[$field] = (string) $row[$field];
                }
            }

            if (! array_key_exists('position', $row) || $row['position'] === '' || $row['position'] === null) {
                $row['position'] = 0;
            }

            if (is_numeric($row['position'])) {
                $row['position'] = (int) $row['position'];
            }

            if (array_key_exists('laps', $row) && $row['laps'] === '') {
                $row['laps'] = null;
            }

            if (array_key_exists('laps', $row) && is_numeric($row['laps'])) {
                $row['laps'] = (int) $row['laps'];
            }

            foreach (['start_position', 'passes'] as $field) {
                if (array_key_exists($field, $row) && $row[$field] === '') {
                    $row[$field] = 0;
                }

                if (array_key_exists($field, $row) && is_numeric($row[$field])) {
                    $row[$field] = (int) $row[$field];
                }
            }

            $normalized[] = $row;
        }

        $payload = ['standings' => $normalized];

        if ($request->has('source_key')) {
            $payload['source_key'] = (string) $request->input('source_key');
        }

        foreach (['laps_to_go', 'race_laps'] as $field) {
            if ($request->has($field) && is_numeric($request->input($field))) {
                $payload[$field] = (int) $request->input($field);
            }
        }

        $request->merge($payload);
    }

    private function authorizeTimingFeed(Request $request): void
    {
        abort_unless(Setting::timingFeedTokenMatches($request->string('token')->toString()), 403);
    }
}
