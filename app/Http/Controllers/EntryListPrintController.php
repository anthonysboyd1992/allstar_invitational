<?php

namespace App\Http\Controllers;

use App\Models\Competitor;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class EntryListPrintController extends Controller
{
    /**
     * Landscape letter entry list of every competitor.
     * Weight is included unless ?weight=0. The announcer copy omits weight and pill.
     */
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'sort' => ['sometimes', Rule::in(['car_number', 'pill_number', 'last_name', 'weight'])],
            'weight' => ['sometimes', 'boolean'],
        ]);

        $announcer = $request->routeIs('competitors.print-announcer');
        $showWeight = ! $announcer && $request->boolean('weight', true);
        $showPill = ! $announcer;
        $sheetTitle = $announcer ? 'Announcer Entry List' : 'Entry list';
        $sheetColumns = 6
            + ($showWeight ? 1 : 0)
            + ($announcer ? 1 : 0)
            + ($showPill ? 1 : 0);

        return view('entry-list-print', [
            'competitors' => $this->sortedCompetitors($validated['sort'] ?? 'car_number'),
            'showWeight' => $showWeight,
            'showPill' => $showPill,
            'showAnnouncerDetails' => $announcer,
            'sheetTitle' => $sheetTitle,
            'sheetColumns' => $sheetColumns,
            'title' => $sheetTitle,
            ...Setting::printLetterhead(),
        ]);
    }

    /**
     * @return Collection<int, Competitor>
     */
    private function sortedCompetitors(string $sortBy): Collection
    {
        $direction = $sortBy === 'weight' ? 'desc' : 'asc';

        $competitors = Competitor::query()
            ->when(in_array($sortBy, ['pill_number', 'weight'], true), fn ($query) => $query->orderByRaw("{$sortBy} is null"))
            ->when($sortBy !== 'car_number', fn ($query) => $query->orderBy($sortBy, $direction)->orderBy('last_name'))
            ->get();

        return $sortBy === 'car_number' ? Competitor::sortByCarNumber($competitors) : $competitors;
    }
}
