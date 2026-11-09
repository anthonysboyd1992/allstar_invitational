<?php

namespace App\Http\Controllers;

use App\Models\Race;
use App\Models\Setting;
use App\Models\TimingFeedResult;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BestTimesPrintController extends Controller
{
    /**
     * Landscape letter best-times sheet for the selected races.
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

        $sessions = TimingFeedResult::query()->orderBy('id')->get();
        $blocks = $races->flatMap(fn (Race $race) => $race->printedBestTimesBlocks($sessions))->values();

        return view('best-times-print', [
            'races' => $races,
            'blocks' => $blocks,
            'sheetColumns' => max(5, (int) $blocks->max('columns')),
            'title' => $races->count() === 1 ? $races->first()->printedTitle() : 'Best Times',
            'printKind' => 'Best Times',
            ...Setting::printLetterhead(),
        ]);
    }
}
