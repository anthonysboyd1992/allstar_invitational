<?php

namespace App\Services;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\Setting;
use App\Models\VmixSection;

class VmixFeed
{
    public const ClearDefaultSize = 256;

    /**
     * @return array{Lineup: list<array<string, string|int>>}
     */
    public function lineup(?Race $race = null): array
    {
        $race ??= Setting::activeRace();
        $className = $this->className();

        if ($race) {
            $race->loadMissing(['entries.competitor', 'entries.car']);
        }

        $entries = $race
            ? $race->entries->filter(fn (RaceEntry $entry): bool => filled($entry->competitor))->values()
            : collect();
        $perPage = Setting::driversPerDataset();
        $pages = $entries->chunk($perPage)->map->values();

        if ($pages->isEmpty()) {
            $pages = collect([collect()]);
        }

        $header = $this->raceHeader($race, $className);
        $laps = $race?->laps ? $race->laps.' Laps' : '';
        $transfers = (string) $race?->transfers;

        return [
            'Lineup' => $pages->map(function ($pageEntries, int $pageIndex) use ($header, $laps, $transfers, $race, $entries, $className, $pages, $perPage): array {
                $page = $header + [
                    'Trans_Dist.Text' => implode(' | ', array_filter([$transfers, $laps])),
                    'Laps.Text' => (string) $race?->laps,
                    'Sponsor.Text' => (string) $race?->sponsor,
                    'Transfers.Text' => $transfers,
                    'TotalStarting.Text' => $entries->count(),
                    'Page' => $pageIndex + 1,
                    'TotalPages' => $pages->count(),
                ];

                for ($slot = 1; $slot <= $perPage; $slot++) {
                    $page += $this->slot($slot, $pageEntries->get($slot - 1), $pageIndex * $perPage + $slot, $className);
                }

                return $page;
            })->all(),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public function driver(?Competitor $competitor = null): array
    {
        $competitor ??= Setting::activeDriver();
        $className = $this->className();

        return [
            $this->raceHeader(Setting::activeRace(), $className) + [
                'Driver.Text' => (string) $competitor?->car_number,
                'Headshot.Source' => $this->headshotFor($competitor),
                'FullName.Text' => (string) $competitor?->full_name,
                'Hometown.Text' => (string) $competitor?->hometown,
                'PrimaryDivision.Text' => (string) $competitor?->primary_division,
                'Accolades.Text' => (string) $competitor?->accolades,
                'Mode' => Setting::get(Setting::DriverMode, 'name_only'),
            ],
        ];
    }

    /**
     * The master vMix data file: each on-air standalone section under its own key, then for each template
     * with an item on air, the template's key holding that item's single row of fields.
     *
     * @return array<string, list<array<string, string>>>
     */
    public function data(): array
    {
        $standalone = VmixSection::query()
            ->standalone()
            ->where('on_air', true)
            ->with('groups.fields')
            ->get()
            ->mapWithKeys(fn (VmixSection $section) => [$section->key => [$section->data()]]);

        $items = VmixSection::query()
            ->runOfShow()
            ->whereNotNull('template_id')
            ->where('on_air', true)
            ->with(['template', 'groups.fields'])
            ->get()
            ->mapWithKeys(fn (VmixSection $item) => [$item->template->key => [$item->data()]]);

        return $standalone->toBase()->merge($items)->all();
    }

    /**
     * @return array{'Class.Text': string, 'Race.Text': string, 'Event.Text': string}
     */
    private function raceHeader(?Race $race, string $className): array
    {
        $raceName = (string) $race?->name;

        return [
            'Class.Text' => $className,
            'Race.Text' => $raceName,
            'Event.Text' => trim($className.' '.$raceName),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function slot(int $slot, ?RaceEntry $entry, int $startPosition, string $className): array
    {
        $competitor = $entry?->competitor;
        $assignment = (string) ($entry?->car?->number ?? '');

        if (! $competitor) {
            return [
                "StartPos{$slot}" => '',
                "Number{$slot}.Text" => '',
                "Driver{$slot}CarAssignment" => '',
                "FullName{$slot}.Text" => '',
                "FirstName{$slot}.Text" => '',
                "LastName{$slot}.Text" => '',
                "num_name{$slot}.Text" => '',
                "Hometown{$slot}.Text" => '',
                "PrimaryDivision{$slot}.Text" => '',
                "DriverKey{$slot}" => '',
                "Headshot{$slot}.Source" => $this->headshotFor(null),
            ];
        }

        $isLeft = $slot % 2 === 1;
        $number = (string) $competitor->car_number;
        $fullName = $competitor->full_name;

        return [
            "StartPos{$slot}" => (string) $startPosition,
            "Number{$slot}.Text" => $number,
            "Driver{$slot}CarAssignment" => $assignment,
            "FullName{$slot}.Text" => $fullName,
            "FirstName{$slot}.Text" => $competitor->first_name,
            "LastName{$slot}.Text" => $competitor->last_name,
            "num_name{$slot}.Text" => $isLeft ? "{$number} - {$fullName}" : "{$fullName} - {$number}",
            "Hometown{$slot}.Text" => (string) $competitor->hometown,
            "PrimaryDivision{$slot}.Text" => (string) $competitor->primary_division,
            "DriverKey{$slot}" => $competitor->driverKey($className),
            "Headshot{$slot}.Source" => $this->headshotFor($competitor),
        ];
    }

    private function className(): string
    {
        return (string) Setting::get(Setting::ClassName, '');
    }

    /**
     * Link to a transparent PNG generated on this server at the given size.
     */
    public static function clearUrl(int $width, int $height): string
    {
        return route('feeds.clear', ['w' => $width, 'h' => $height]);
    }

    /**
     * The uploaded headshot URL vMix should load for a driver, or a transparent image when there is no driver or photo.
     */
    public function headshotFor(?Competitor $competitor): string
    {
        return $competitor?->headshotUrl() ?? self::clearUrl(config('vmix.headshot_width'), config('vmix.headshot_height'));
    }
}
