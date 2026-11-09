<?php

namespace App\Http\Controllers;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class TransponderExportController extends Controller
{
    /**
     * Download a race's transponder assignment CSV for the timing system, in starting order.
     */
    public function transponders(Race $race): StreamedResponse
    {
        return $this->csvDownload($this->raceCompetitors($race), $race->name);
    }

    /**
     * Download the race settings file that accompanies the transponder CSV.
     */
    public function laps(Race $race): StreamedResponse
    {
        return $this->lapsDownload($race->laps ?? 0, $race->name);
    }

    /**
     * Download a race's CSV and DAT together.
     */
    public function raceZip(Race $race): BinaryFileResponse
    {
        return $this->zipDownload($this->fileName($race->name, 'zip'), $this->raceFiles($race));
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
     * Download a combined type's CSV and DAT together.
     */
    public function typeZip(RaceType $raceType): BinaryFileResponse
    {
        return $this->zipDownload($this->fileName($raceType->name, 'zip'), $this->typeFiles($raceType));
    }

    /**
     * One zip of CSV and DAT files for the selected races.
     */
    public function bundle(Request $request): BinaryFileResponse
    {
        $ids = collect(explode(',', (string) $request->query('races')))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 404);

        $races = Race::query()
            ->with(['raceType.races.entries.competitor', 'entries.competitor'])
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Race $race): int => $ids->search($race->id))
            ->values();

        abort_if($races->isEmpty(), 404);

        $files = [];
        $seenTypes = [];

        foreach ($races as $race) {
            if ($race->raceType->combines_groups) {
                if (isset($seenTypes[$race->race_type_id])) {
                    continue;
                }

                $seenTypes[$race->race_type_id] = true;
                $files = [...$files, ...$this->typeFiles($race->raceType)];
            } else {
                $files = [...$files, ...$this->raceFiles($race)];
            }
        }

        $zipName = $races->count() === 1
            ? $this->fileName(($races->first()->raceType->combines_groups ? $races->first()->raceType->name : $races->first()->name), 'zip')
            : 'Timing files.zip';

        return $this->zipDownload($zipName, $files);
    }

    /**
     * JSON of CSV and DAT files for the Timing File Feed. Only races set to Lineups are included.
     */
    public function lineups(Request $request): JsonResponse
    {
        $this->authorizeLineupFeed($request);

        return response()->json(['races' => $this->lineupSessions()]);
    }

    /**
     * @return Collection<int, Competitor>
     */
    private function raceCompetitors(Race $race): Collection
    {
        $race->loadMissing('entries.competitor');

        return $race->entries
            ->pluck('competitor')
            ->filter(fn (?Competitor $competitor): bool => filled($competitor?->transponder))
            ->values();
    }

    /**
     * @return array<string, string>
     */
    private function raceFiles(Race $race): array
    {
        $base = $this->baseName($race->name);

        return [
            $base.'.csv' => $this->csvContents($this->raceCompetitors($race)),
            $base.'.dat' => $this->datContents($race->laps ?? 0),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function typeFiles(RaceType $raceType): array
    {
        $base = $this->baseName($raceType->name);

        return [
            $base.'.csv' => $this->csvContents($raceType->transponderLineup()),
            $base.'.dat' => $this->datContents($raceType->sessionLaps() ?? 0),
        ];
    }

    /**
     * @param  Collection<int, Competitor|null>  $competitors
     */
    private function csvContents(Collection $competitors): string
    {
        $output = fopen('php://temp', 'r+');

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

        rewind($output);
        $contents = stream_get_contents($output) ?: '';
        fclose($output);

        return $contents;
    }

    private function datContents(int $laps): string
    {
        return 'Laps,'.$laps."\r\n"
            .'Length,'.Setting::trackLength()."\r\n"
            ."Duration,0\r\n"
            ."Breakout,0.000\r\n";
    }

    /**
     * @param  Collection<int, Competitor|null>  $competitors
     */
    private function csvDownload(Collection $competitors, string $baseName): StreamedResponse
    {
        $contents = $this->csvContents($competitors);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $this->fileName($baseName, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function lapsDownload(int $laps, string $baseName): StreamedResponse
    {
        $contents = $this->datContents($laps);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $this->fileName($baseName, 'dat'), ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zipDownload(string $zipName, array $files): BinaryFileResponse
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'timing-'.uniqid('', true).'.zip';
        $zip = new ZipArchive;

        abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return response()->download($path, $zipName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend();
    }

    private function fileName(string $name, string $extension): string
    {
        return $this->baseName($name).'.'.$extension;
    }

    private function baseName(string $name): string
    {
        $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name));

        return $name === '' ? 'Race' : $name;
    }

    private function authorizeLineupFeed(Request $request): void
    {
        abort_unless(Setting::timingFeedTokenMatches($request->string('token')->toString()), 403);
    }

    /**
     * @return list<array{key: string, name: string, type: string, laps: int, files: list<array{name: string, contents: string}>}>
     */
    private function lineupSessions(): array
    {
        $sessions = [];
        $types = RaceType::query()
            ->with(['races.entries.competitor'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (RaceType $raceType): bool => $raceType->races->isNotEmpty());

        foreach ($types as $raceType) {
            if ($raceType->combines_groups) {
                if ($this->typeShouldSkipTimingPull($raceType)) {
                    continue;
                }

                $sessions[] = $this->sessionFromFiles(
                    'type-'.$raceType->id,
                    $raceType->name,
                    $raceType->name,
                    $raceType->sessionLaps() ?? 0,
                    $this->typeFiles($raceType),
                );

                continue;
            }

            foreach ($raceType->races as $race) {
                if ($this->raceShouldSkipTimingPull($race)) {
                    continue;
                }

                $sessions[] = $this->sessionFromFiles(
                    'race-'.$race->id,
                    $race->name,
                    $raceType->name,
                    $race->laps ?? 0,
                    $this->raceFiles($race),
                );
            }
        }

        return $sessions;
    }

    /**
     * @param  array<string, string>  $files
     * @return array{key: string, name: string, type: string, laps: int, files: list<array{name: string, contents: string}>}
     */
    private function sessionFromFiles(string $key, string $name, string $type, int $laps, array $files): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'type' => $type,
            'laps' => $laps,
            'files' => collect($files)
                ->map(fn (string $contents, string $fileName): array => [
                    'name' => $fileName,
                    'contents' => $contents,
                ])
                ->values()
                ->all(),
        ];
    }

    private function raceShouldSkipTimingPull(Race $race): bool
    {
        return ! $race->isReadyForTimingPull();
    }

    private function typeShouldSkipTimingPull(RaceType $raceType): bool
    {
        return $raceType->races->contains(fn (Race $race): bool => $this->raceShouldSkipTimingPull($race));
    }
}
