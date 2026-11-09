<?php

namespace App\Http\Controllers;

use App\Models\Race;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HardChargerPrintController extends Controller
{
    /**
     * Landscape letter hard-charger sheet for the selected races.
     */
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'races' => ['sometimes', 'array'],
            'races.*' => ['integer', 'exists:races,id'],
        ]);

        $races = Race::inProgramOrder(
            Race::query()
                ->with(['entries.competitor'])
                ->when(
                    array_key_exists('races', $validated),
                    fn ($query) => $query->whereIn('id', $validated['races']),
                    fn ($query) => $query->whereHas('raceType', fn ($type) => $type->where('has_results', true)),
                )
                ->get()
        );

        $blocks = $races->flatMap->printedHardChargerBlocks()->values();

        return view('hard-charger-print', [
            'races' => $races,
            'blocks' => $blocks,
            'sheetColumns' => max(6, (int) $blocks->max('columns')),
            'title' => $races->count() === 1 ? $races->first()->printedTitle() : 'Hard Charger',
            'printKind' => 'Hard Charger',
            ...Setting::printLetterhead(),
        ]);
    }
}
