<?php

namespace App\Http\Controllers;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransponderExportController extends Controller
{
    /**
     * Download a race's transponder assignment CSV for the timing system, in starting order.
     */
    public function transponders(Race $race): StreamedResponse
    {
        $competitors = $race->entries()
            ->with('competitor')
            ->get()
            ->pluck('competitor')
            ->filter(fn ($competitor): bool => filled($competitor?->transponder));

        return $this->csvDownload($competitors, $race->name);
    }

    /**
     * Download the race settings file that accompanies the transponder CSV.
     */
    public function laps(Race $race): StreamedResponse
    {
        return $this->lapsDownload($race->laps ?? 0, $race->name);
    }

    /**
     * One CSV for every group in the type, in program order.
     */
    public function typeTransponders(RaceType $raceType): StreamedResponse
    {
        return $this->csvDownload($raceType->transponderLineup(), $raceType->name);
    }

    /**
     * One laps file for a type that shares a timing session.
     */
    public function typeLaps(RaceType $raceType): StreamedResponse
    {
        return $this->lapsDownload($raceType->sessionLaps() ?? 0, $raceType->name);
    }

    /**
     * @param  Collection<int, Competitor|null>  $competitors
     */
    private function csvDownload(Collection $competitors, string $baseName): StreamedResponse
    {
        return response()->streamDownload(function () use ($competitors): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, ['Trns ID', '#', 'Last Name', 'First Name', 'Misc', 'Backup Trns ID'], eol: "\r\n");

            foreach ($competitors as $competitor) {
                fputcsv($output, [
                    $competitor->transponder,
                    $competitor->car_number,
                    $competitor->last_name,
                    $competitor->first_name,
                    '',
                    '',
                ], eol: "\r\n");
            }

            fclose($output);
        }, $this->fileName($baseName, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function lapsDownload(int $laps, string $baseName): StreamedResponse
    {
        return response()->streamDownload(function () use ($laps): void {
            echo 'Laps='.$laps."\r\n";
        }, $this->fileName($baseName, 'dat'), ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function fileName(string $name, string $extension): string
    {
        $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name));

        return ($name === '' ? 'Race' : $name).'.'.$extension;
    }
}
