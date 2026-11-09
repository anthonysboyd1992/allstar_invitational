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
            .sheet-pages > thead > tr > td { width: 100%; padding: 0 0 10px; background: #fff; border: 0; color: inherit; }
            .letterhead { position: relative; display: flex; align-items: center; justify-content: space-between; width: 100%; min-height: 1.5in; }
            .letterhead img { position: relative; z-index: 1; display: block; width: auto; object-fit: contain; }
            .letterhead-left { height: 1.25in; max-width: 3.1in; object-position: left center; }
            .letterhead-right { height: 0.7in; max-width: 2.4in; object-position: right center; }
            .letterhead-center { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); width: max-content; text-align: center; z-index: 0; }
            .letterhead-kind { margin: 0 0 10px; font-size: 22px; font-weight: 900; letter-spacing: 0.08em; color: #ee6426; text-transform: uppercase; white-space: nowrap; }
            .letterhead-heading { margin: 0 0 10px; font-size: 14px; font-weight: 900; letter-spacing: 0.01em; line-height: 1.2; text-transform: uppercase; white-space: nowrap; }
            .letterhead-date, .letterhead-venue, .letterhead-venue-name { margin: 8px 0 0; font-size: 12px; font-weight: 600; color: #334155; white-space: nowrap; }
            .letterhead-venue-name { font-weight: 800; }
            .race-block, .race-block tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .race-title { padding: 6px 8px; background: var(--heading); color: #fff; font-size: 12px; letter-spacing: 0.04em; text-align: center; text-transform: uppercase; }
            .race-block + .race-block .race-title { border-top: 14px solid #fff; }
            th, td { padding: 3px 6px; text-align: left; vertical-align: top; }
            .cols th { background: #111; color: #fff; font-size: 9px; letter-spacing: 0.04em; text-transform: uppercase; }
            th, .race-title, .cols th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            td { border-bottom: 1px solid #d1d5db; }
            .rank, .car, .driver, .lap { white-space: nowrap; width: 1%; }
            .rank, .car { font-weight: 700; }
            .lap { text-align: center; font-weight: 700; }
            .empty { padding: 10px 6px; color: #64748b; }
            @page { size: letter landscape; margin: 0.5in; }
            @media print {
                body { background: #fff; }
                .toolbar { display: none; }
                .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
                .sheet-pages > thead { display: table-header-group; }
                .race-title {
                    background: var(--heading) !important;
                    color: #fff !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .cols th {
                    background: #111 !important;
                    color: #fff !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        </style>
    </head>
    <body>
        <div class="toolbar">
            <a href="{{ route('results') }}">Back to results</a>
            <button type="button" onclick="window.print()">Print</button>
        </div>

        <div class="sheet">
            <table class="sheet-pages">
                <thead>
                    <tr>
                        <td colspan="{{ $sheetColumns }}">
                            @include('print-letterhead')
                        </td>
                    </tr>
                </thead>
                @forelse ($blocks as $block)
                    <tbody class="race-block">
                        <tr class="race-start">
                            <th class="race-title" colspan="{{ $block['columns'] }}">{{ $block['title'] }}</th>
                        </tr>
                        <tr class="cols">
                            <th class="rank">Rank</th>
                            <th class="car">Car</th>
                            <th class="driver">Driver</th>
                            <th class="lap">Race</th>
                            <th class="lap">Day</th>
                        </tr>
                        @forelse ($block['slots'] as $slot)
                            <tr>
                                <td class="rank">{{ $slot['rank'] }}</td>
                                <td class="car">{{ $slot['entry']->competitor?->car_number }}</td>
                                <td class="driver">{{ $slot['entry']->competitor?->full_name }}</td>
                                <td class="lap">{{ $slot['best_race'] }}</td>
                                <td class="lap">{{ $slot['best_day'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $block['columns'] }}" class="empty">No lap times for this race.</td>
                            </tr>
                        @endforelse
                    </tbody>
                @empty
                    <tbody>
                        <tr>
                            <td colspan="{{ $sheetColumns }}" class="empty">No races selected to print.</td>
                        </tr>
                    </tbody>
                @endforelse
            </table>
        </div>
    </body>
</html>
