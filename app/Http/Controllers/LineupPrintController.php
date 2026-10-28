<?php

namespace App\Http\Controllers;

use App\Models\Race;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LineupPrintController extends Controller
{
    /**
     * Printable letter-size heat sheet for the selected races.
     */
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'races' => ['sometimes', 'array'],
            'races.*' => ['integer', 'exists:races,id'],
        ]);

        $races = Race::inProgramOrder(
            Race::query()
                ->with(['raceType.cars', 'raceType.races', 'entries.competitor', 'entries.car'])
                ->when(array_key_exists('races', $validated), fn ($query) => $query->whereIn('id', $validated['races']))
                ->get()
        );

        return view('lineups-print', [
            'races' => $races,
            'title' => $races->count() === 1 ? $races->first()->printedTitle() : 'Lineups',
            'leftLogoUrl' => Setting::letterheadUrl('left'),
            'rightLogoUrl' => Setting::letterheadUrl('right'),
        ]);
    }
}
