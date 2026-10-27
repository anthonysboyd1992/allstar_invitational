<?php

namespace App\Http\Controllers;

use App\Models\Race;
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
        }, $this->fileName($race, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Download the race settings file that accompanies the transponder CSV.
     */
    public function laps(Race $race): StreamedResponse
    {
        return response()->streamDownload(function () use ($race): void {
            echo 'Laps='.($race->laps ?? 0)."\r\n";
        }, $this->fileName($race, 'dat'), ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function fileName(Race $race, string $extension): string
    {
        $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $race->name));

        return ($name === '' ? 'Race '.$race->id : $name).'.'.$extension;
    }
}
