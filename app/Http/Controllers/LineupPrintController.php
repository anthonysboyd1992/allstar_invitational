<?php

namespace App\Http\Controllers;

use App\Models\Race;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LineupPrintController extends Controller
{
    /**
     * Printable landscape letter heat sheet for the selected races.
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

        $blocks = $races->flatMap->printedLineupBlocks()->values();

        return view('lineups-print', [
            'races' => $races,
            'blocks' => $blocks,
            'sheetColumns' => max(6, (int) $blocks->max('columns')),
            'title' => $races->count() === 1 ? $races->first()->printedTitle() : 'Lineups',
            'printKind' => 'Lineups',
            ...Setting::printLetterhead(),
        ]);
    }
}
