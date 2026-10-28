<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }}</title>
        <style>
            :root { --page-width: 11in; --page-height: 8.5in; --page-margin: 0.5in; --heading: #ee6426; }
            * { box-sizing: border-box; }
            body { margin: 0; color: #111; font: 11px/1.3 Arial, Helvetica, sans-serif; background: #64748b; }
            .toolbar { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 16px; background: #0f172a; }
            .toolbar a, .toolbar button { padding: 6px 12px; border: 0; border-radius: 6px; background: #f8fafc; font: 600 13px Arial, sans-serif; color: #0f172a; text-decoration: none; cursor: pointer; }
            .sheet {
                width: var(--page-width);
                min-height: var(--page-height);
                margin: 16px auto 32px;
                padding: var(--page-margin);
                background: #fff;
                box-shadow: 0 8px 24px rgba(15, 23, 42, 0.35);
            }
            .sheet-pages { width: 100%; border-collapse: collapse; }
            .sheet-pages > thead { display: table-header-group; }
            .sheet-pages > thead > tr > td { padding: 0 0 10px; background: #fff; border: 0; color: inherit; }
            .letterhead { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
            .letterhead img { display: block; width: auto; object-fit: contain; }
            .letterhead-left { height: 1.25in; max-width: 5in; flex: 1 1 auto; object-position: left center; }
            .letterhead-right { height: 0.7in; max-width: 2.8in; flex: 0 0 auto; object-position: right center; }
            .race-block, .race-block tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .race-title { padding: 6px 8px; background: var(--heading); color: #fff; font-size: 12px; letter-spacing: 0.04em; text-align: center; text-transform: uppercase; }
            .race-block + .race-block .race-title { border-top: 14px solid #fff; }
            th, td { padding: 3px 6px; text-align: left; vertical-align: top; }
            th { background: var(--heading); color: #fff; font-size: 9px; letter-spacing: 0.04em; text-transform: uppercase; }
            th, .race-title {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            td { border-bottom: 1px solid #d1d5db; }
            .pos, .car, .driver, .weight, .kart { white-space: nowrap; width: 1%; }
            .pos, .car { font-weight: 700; }
            .hometown { width: 18%; }
            .accolades { width: 42%; }
            .kart { font-weight: 700; text-align: center; }
            .empty { padding: 10px 6px; color: #64748b; }
            @page { size: letter landscape; margin: 0.5in; }
            @media print {
                body { background: #fff; }
                .toolbar { display: none; }
                .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
                .sheet-pages > thead { display: table-header-group; }
                th, .race-title {
                    background: var(--heading) !important;
                    color: #fff !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        </style>
    </head>
    <body>
        <div class="toolbar">
            <a href="{{ route('lineups') }}">Back to lineups</a>
            <button type="button" onclick="window.print()">Print</button>
        </div>

        <div class="sheet">
            <table class="sheet-pages">
                <thead>
                    <tr>
                        <td colspan="7">
                            <header class="letterhead">
                                <img class="letterhead-left" src="{{ $leftLogoUrl }}" alt="Left letterhead">
                                <img class="letterhead-right" src="{{ $rightLogoUrl }}" alt="Right letterhead">
                            </header>
                        </td>
                    </tr>
                </thead>
                @forelse ($races as $race)
                    <tbody class="race-block">
                        <tr class="race-start">
                            <th class="race-title" colspan="7">{{ $race->printedTitle() }}</th>
                        </tr>
                        <tr>
                            <th class="pos">Pos</th>
                            <th class="car">Car</th>
                            <th class="driver">Driver</th>
                            <th class="hometown">Hometown</th>
                            <th class="accolades">Accolades</th>
                            <th class="weight">Weight</th>
                            <th class="kart">Kart Assignment</th>
                        </tr>
                        @forelse ($race->entries as $entry)
                            <tr>
                                <td class="pos">{{ $entry->start_position }}</td>
                                <td class="car">{{ $entry->competitor?->car_number }}</td>
                                <td class="driver">{{ $entry->competitor?->full_name }}</td>
                                <td class="hometown">{{ $entry->competitor?->hometown }}</td>
                                <td class="accolades">{{ $entry->competitor?->accolades }}</td>
                                <td class="weight">{{ $entry->competitor?->formattedWeight() }}</td>
                                <td class="kart">{{ $race->kartAssignment($entry) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="empty">No drivers in this race.</td>
                            </tr>
                        @endforelse
                    </tbody>
                @empty
                    <tbody>
                        <tr>
                            <td colspan="7" class="empty">No races selected to print.</td>
                        </tr>
                    </tbody>
                @endforelse
            </table>
        </div>
    </body>
</html>
