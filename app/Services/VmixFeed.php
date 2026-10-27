<?php

namespace App\Services;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\Setting;
use App\Models\VmixSection;

class VmixFeed
{
    /**
     * Fully transparent PNG at the default headshot size, in public/.
     */
    public const ClearHeadshot = 'images/clear-headshot.png';

    /**
     * @return array{Lineup: list<array<string, string|int>>}
     */
    public function lineup(?Race $race = null): array
    {
        $race ??= Setting::activeRace();
        $className = $this->className();
        $competitors = $race ? $race->entries->pluck('competitor')->filter()->values() : collect();
        $perPage = config('vmix.slots_per_page');
        $pages = $competitors->chunk($perPage)->map->values();

        if ($pages->isEmpty()) {
            $pages = collect([collect()]);
        }

        $header = $this->raceHeader($race, $className);
        $laps = $race?->laps ? $race->laps.' Laps' : '';
        $transfers = (string) $race?->transfers;

        return [
            'Lineup' => $pages->map(function ($pageCompetitors, int $pageIndex) use ($header, $laps, $transfers, $race, $competitors, $className, $pages, $perPage): array {
                $page = $header + [
                    'Trans_Dist.Text' => implode(' | ', array_filter([$transfers, $laps])),
                    'Laps.Text' => (string) $race?->laps,
                    'Sponsor.Text' => (string) $race?->sponsor,
                    'Transfers.Text' => $transfers,
                    'TotalStarting.Text' => $competitors->count(),
                    'Page' => $pageIndex + 1,
                    'TotalPages' => $pages->count(),
                ];

                for ($slot = 1; $slot <= $perPage; $slot++) {
                    $page += $this->slot($slot, $pageCompetitors->get($slot - 1), $pageIndex * $perPage + $slot, $className);
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
                'Accolades.Text' => (string) $competitor?->accolades,
                'Mode' => Setting::get(Setting::DriverMode, 'name_only'),
            ],
        ];
    }

    /**
     * The master vMix data file: one entry per on-air section, each a single row of that section's fields.
     *
     * @return array<string, list<array<string, string>>>
     */
    public function data(): array
    {
        return VmixSection::query()
            ->where('on_air', true)
            ->with('groups.fields')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (VmixSection $section) => [$section->key => [$section->data()]])
            ->all();
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
    private function slot(int $slot, ?Competitor $competitor, int $startPosition, string $className): array
    {
        if (! $competitor) {
            return [
                "StartPos{$slot}" => '',
                "Number{$slot}.Text" => '',
                "FullName{$slot}.Text" => '',
                "LU_BG{$slot}.Source" => $this->graphic('clear.png'),
                "FirstName{$slot}.Text" => '',
                "LastName{$slot}.Text" => '',
                "num_name{$slot}.Text" => '',
                "Hometown{$slot}.Text" => '',
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
            "FullName{$slot}.Text" => $fullName,
            "LU_BG{$slot}.Source" => $this->graphic($isLeft ? 'lu_bg_l.png' : 'lu_bg_r.png'),
            "FirstName{$slot}.Text" => $competitor->first_name,
            "LastName{$slot}.Text" => $competitor->last_name,
            "num_name{$slot}.Text" => $isLeft ? "{$number} - {$fullName}" : "{$fullName} - {$number}",
            "Hometown{$slot}.Text" => (string) $competitor->hometown,
            "DriverKey{$slot}" => $competitor->driverKey($className),
            "Headshot{$slot}.Source" => $this->headshotFor($competitor),
        ];
    }

    private function className(): string
    {
        return (string) Setting::get(Setting::ClassName, '');
    }

    private function graphic(string $file): string
    {
        return config('vmix.graphics_url').$file.'?v='.config('vmix.asset_version');
    }

    /**
     * The uploaded headshot URL vMix should load for a driver, or a transparent image when there is no driver or photo.
     */
    public function headshotFor(?Competitor $competitor): string
    {
        return $competitor?->headshotUrl() ?? asset(self::ClearHeadshot);
    }
}
