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
            .letterhead { position: relative; display: flex; align-items: center; justify-content: space-between; width: 100%; min-height: 1.25in; }
            .letterhead img { position: relative; z-index: 1; display: block; width: auto; object-fit: contain; }
            .letterhead-left { height: 1.25in; max-width: 3.1in; object-position: left center; }
            .letterhead-right { height: 0.7in; max-width: 2.4in; object-position: right center; }
            .letterhead-center { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); width: max-content; text-align: center; z-index: 0; }
            .letterhead-heading { margin: 0 0 10px; font-size: 14px; font-weight: 900; letter-spacing: 0.01em; line-height: 1.2; text-transform: uppercase; white-space: nowrap; }
            .letterhead-date, .letterhead-venue, .letterhead-venue-name { margin: 8px 0 0; font-size: 12px; font-weight: 600; color: #334155; white-space: nowrap; }
            .letterhead-venue-name { font-weight: 800; }
            .race-block, .race-block tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .race-title { padding: 6px 8px; background: var(--heading); color: #fff; font-size: 12px; letter-spacing: 0.04em; text-align: center; text-transform: uppercase; }
            th, td { padding: 3px 6px; text-align: left; vertical-align: top; }
            .cols th { background: #111; color: #fff; font-size: 9px; letter-spacing: 0.04em; text-transform: uppercase; }
            th, .race-title, .cols th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            td { border-bottom: 1px solid #d1d5db; }
            .pos, .car, .driver, .pill, .weight, .division { white-space: nowrap; width: 1%; }
            .pos, .car { font-weight: 700; }
            .hometown { width: 16%; }
            .division { width: 12%; }
            .accolades { width: 28%; }
            .announcer { width: 28%; }
            .pill, .weight { text-align: center; }
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
            <a href="{{ route('competitors') }}">Back to competitors</a>
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
                <tbody class="race-block">
                    <tr class="race-start">
                        <th class="race-title" colspan="{{ $sheetColumns }}">{{ $sheetTitle }}</th>
                    </tr>
                    <tr class="cols">
                        <th class="pos">Pos</th>
                        <th class="car">Car</th>
                        <th class="driver">Driver</th>
                        <th class="hometown">Hometown</th>
                        <th class="division">Primary Division</th>
                        @if ($showWeight)
                            <th class="weight">Weight</th>
                        @endif
                        <th class="accolades">Accolades</th>
                        @if ($showAnnouncerDetails)
                            <th class="announcer">Announcer Details</th>
                        @endif
                        @if ($showPill)
                            <th class="pill">Pill</th>
                        @endif
                    </tr>
                    @forelse ($competitors as $competitor)
                        <tr>
                            <td class="pos">{{ $loop->iteration }}</td>
                            <td class="car">{{ $competitor->car_number }}</td>
                            <td class="driver">{{ $competitor->full_name }}</td>
                            <td class="hometown">{{ $competitor->hometown }}</td>
                            <td class="division">{{ $competitor->primary_division }}</td>
                            @if ($showWeight)
                                <td class="weight">{{ $competitor->formattedWeight() }}</td>
                            @endif
                            <td class="accolades">{{ $competitor->accolades }}</td>
                            @if ($showAnnouncerDetails)
                                <td class="announcer">{{ $competitor->announcer_details }}</td>
                            @endif
                            @if ($showPill)
                                <td class="pill">{{ $competitor->pill_number }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $sheetColumns }}" class="empty">No competitors to print.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </body>
</html>
