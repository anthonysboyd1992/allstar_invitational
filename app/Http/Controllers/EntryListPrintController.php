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
     * Landscape letter entry list of every competitor, with weight.
     */
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'sort' => ['sometimes', Rule::in(['car_number', 'pill_number', 'last_name', 'weight'])],
        ]);

        $competitors = $this->sortedCompetitors($validated['sort'] ?? 'car_number');

        return view('entry-list-print', [
            'competitors' => $competitors,
            'sheetColumns' => 7,
            'title' => 'Entry list',
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
