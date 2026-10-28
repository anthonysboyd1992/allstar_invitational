<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }}</title>
        <style>
            :root { --page-width: 8.5in; --page-height: 11in; --page-margin: 0.6in; }
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
            .letterhead { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 14px; }
            .letterhead img { display: block; height: 0.85in; width: auto; max-width: 2.8in; object-fit: contain; }
            .race { margin: 0 0 18px; page-break-inside: avoid; }
            .race h1 { margin: 0; padding: 6px 8px; background: #111; color: #fff; font-size: 12px; letter-spacing: 0.04em; text-align: center; text-transform: uppercase; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 3px 5px; text-align: left; vertical-align: top; }
            th { background: #111; color: #fff; font-size: 9px; letter-spacing: 0.04em; text-transform: uppercase; }
            td { border-bottom: 1px solid #d1d5db; }
            .pos, .car, .weight, .kart { white-space: nowrap; }
            .pos { width: 2.2em; font-weight: 700; }
            .car { width: 3em; font-weight: 700; }
            .weight { width: 4em; }
            .kart { width: 8.5em; font-weight: 700; }
            .empty { padding: 10px 6px; color: #64748b; }
            @page { size: 8.5in 11in; margin: 0.6in; }
            @media print {
                body { background: #fff; }
                .toolbar { display: none; }
                .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            }
        </style>
    </head>
    <body>
        <div class="toolbar">
            <a href="{{ route('lineups') }}">Back to lineups</a>
            <button type="button" onclick="window.print()">Print</button>
        </div>

        <div class="sheet">
            <header class="letterhead">
                <img src="{{ $leftLogoUrl }}" alt="Left letterhead">
                <img src="{{ $rightLogoUrl }}" alt="Right letterhead">
            </header>

            @forelse ($races as $race)
                <section class="race">
                    <h1>{{ $race->printedTitle() }}</h1>
                    <table>
                        <thead>
                            <tr>
                                <th class="pos">Pos</th>
                                <th class="car">Car</th>
                                <th>Driver</th>
                                <th>Hometown</th>
                                <th>Accolades</th>
                                <th class="weight">Weight</th>
                                <th class="kart">Kart Assignment</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($race->entries as $entry)
                                <tr>
                                    <td class="pos">{{ $entry->start_position }}</td>
                                    <td class="car">{{ $entry->competitor?->car_number }}</td>
                                    <td>{{ $entry->competitor?->full_name }}</td>
                                    <td>{{ $entry->competitor?->hometown }}</td>
                                    <td>{{ $entry->competitor?->accolades }}</td>
                                    <td class="weight">{{ $entry->competitor?->formattedWeight() }}</td>
                                    <td class="kart">{{ $race->kartAssignment($entry) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="empty">No drivers in this race.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>
            @empty
                <p class="empty">No races selected to print.</p>
            @endforelse
        </div>
    </body>
</html>
