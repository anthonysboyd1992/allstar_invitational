<?php

namespace App\Http\Controllers;

use App\Models\Race;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ResultsPrintController extends Controller
{
    /**
     * Landscape letter results sheet for the selected races.
     */
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'races' => ['sometimes', 'array'],
            'races.*' => ['integer', 'exists:races,id'],
        ]);

        $races = Race::inProgramOrder(
            Race::query()
                ->with([
                    'raceType.cars',
                    'raceType.races.entries',
                    'raceType.transferRules.destination.races',
                    'entries.competitor',
                    'entries.car',
                ])
                ->when(
                    array_key_exists('races', $validated),
                    fn ($query) => $query->whereIn('id', $validated['races']),
                    fn ($query) => $query->whereHas('raceType', fn ($type) => $type->where('has_results', true)),
                )
                ->get()
        );

        $blocks = $races->flatMap->printedResultBlocks()->values();

        return view('results-print', [
            'races' => $races,
            'blocks' => $blocks,
            'sheetColumns' => max(8, (int) $blocks->max('columns')),
            'title' => $races->count() === 1 ? $races->first()->printedTitle() : 'Results',
            'printKind' => 'Results',
            ...Setting::printLetterhead(),
        ]);
    }
}
