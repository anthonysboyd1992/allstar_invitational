<?php

namespace App\Models;

use App\Services\ImageCropper;
use App\Services\MediaStorage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const ActiveRace = 'active_race_id';

    public const ActiveDriver = 'active_competitor_id';

    public const ClassName = 'class_name';

    public const DriverMode = 'driver_mode';

    public const DriversPerDataset = 'lineup_drivers_per_dataset';

    /**
     * The vMix data run-of-show item Next / Previous step from.
     */
    public const ShowCurrentItem = 'vmix_show_current_item';

    /**
     * How the cars page is ordered: number, time, or manual (drag).
     */
    public const CarsSort = 'cars_sort';

    public const LetterheadLeft = 'letterhead_left';

    public const LetterheadRight = 'letterhead_right';

    public const PrintHeading = 'print_heading';

    public const PrintDate = 'print_date';

    public const PrintVenue = 'print_venue';

    public const PrintVenueName = 'print_venue_name';

    /**
     * Track length in miles for the timing .dat Length line.
     */
    /**
     * Timing sessions at or below this id are omitted from day pass totals.
     */
    public const PassesCountedAfterId = 'passes_counted_after_id';

    public const TrackLength = 'track_length';

    public const DefaultTrackLength = '0.100';

    /**
     * Token the scoring-PC puller sends as ?token= on the lineup JSON feed.
     */
    public const TimingFeedToken = 'timing_feed_token';

    /**
     * Comma-separated race ids shown on the Lineup TV board.
     */
    public const LineupBoardRaces = 'lineup_board_race_ids';

    /**
     * Which board the shared /tv screen should show.
     */
    public const TvBoardMode = 'tv_board_mode';

    public const TvBoardPillDraw = 'pill-draw';

    public const TvBoardLineup = 'lineup-board';

    /**
     * Competitor currently open in the Competitors pill-draw modal (TV highlight).
     */
    public const PillDrawCompetitor = 'pill_draw_competitor_id';

    /**
     * Pending pill shown on TV before Save lands the driver in a heat.
     */
    public const PillDrawPendingPill = 'pill_draw_pending_pill';

    public const LetterheadDirectory = 'letterhead';

    /**
     * @var list<string>
     */
    public const LetterheadRules = ['image', 'mimes:png,jpg,jpeg,gif,webp', 'max:10240'];

    /**
     * @var list<string>
     */
    public const LetterheadSides = ['left', 'right'];

    public const DefaultDriversPerDataset = 12;

    public const MaxDriversPerDataset = 50;

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function activeRace(): ?Race
    {
        $id = static::get(self::ActiveRace);

        return $id ? Race::query()->with(['raceType', 'entries.competitor', 'entries.car'])->find($id) : null;
    }

    /**
     * Race ids currently pushed to the Lineup TV board.
     *
     * @return list<int>
     */
    public static function lineupBoardRaceIds(): array
    {
        $raw = trim((string) static::get(self::LineupBoardRaces, ''));

        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>  $raceIds
     */
    public static function putLineupBoardRaceIds(array $raceIds): void
    {
        $ids = collect($raceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        static::put(self::LineupBoardRaces, $ids === [] ? null : implode(',', $ids));
    }

    /**
     * Active shared TV board mode.
     */
    public static function tvBoardMode(): string
    {
        $mode = (string) static::get(self::TvBoardMode, self::TvBoardPillDraw);

        return in_array($mode, [self::TvBoardPillDraw, self::TvBoardLineup], true)
            ? $mode
            : self::TvBoardPillDraw;
    }

    public static function putTvBoardMode(string $mode): void
    {
        static::put(
            self::TvBoardMode,
            in_array($mode, [self::TvBoardPillDraw, self::TvBoardLineup], true)
                ? $mode
                : self::TvBoardPillDraw,
        );
    }

    public static function tvBoardModeLabel(?string $mode = null): string
    {
        return ($mode ?? static::tvBoardMode()) === self::TvBoardLineup
            ? 'Lineup Board'
            : 'Pill Draw';
    }

    public static function pillDrawCompetitorId(): ?int
    {
        $id = (int) static::get(self::PillDrawCompetitor);

        return $id > 0 ? $id : null;
    }

    public static function putPillDrawCompetitorId(?int $id): void
    {
        static::put(self::PillDrawCompetitor, $id !== null && $id > 0 ? (string) $id : null);

        if ($id === null || $id <= 0) {
            static::putPillDrawPendingPill(null);
        }
    }

    public static function pillDrawCompetitor(): ?Competitor
    {
        $id = static::pillDrawCompetitorId();

        return $id ? Competitor::query()->find($id) : null;
    }

    public static function pillDrawPendingPill(): ?int
    {
        $pill = (int) static::get(self::PillDrawPendingPill);

        return $pill > 0 ? $pill : null;
    }

    public static function putPillDrawPendingPill(?int $pill): void
    {
        static::put(self::PillDrawPendingPill, $pill !== null && $pill > 0 ? (string) $pill : null);
    }

    /**
     * How many drivers go in each dataset (page) of the vMix lineup feed.
     */
    public static function driversPerDataset(): int
    {
        $count = (int) static::get(self::DriversPerDataset, (string) self::DefaultDriversPerDataset);

        return $count >= 1 && $count <= self::MaxDriversPerDataset ? $count : self::DefaultDriversPerDataset;
    }

    /**
     * Track length for timing .dat files, always three decimal places, e.g. "0.100".
     */
    /**
     * Stop counting earlier timing sessions toward most-passes and hard-charger-today.
     */
    public static function resetDayPasses(): void
    {
        static::put(self::PassesCountedAfterId, (string) ((int) TimingFeedResult::query()->max('id')));
    }

    public static function trackLength(): string
    {
        $value = static::get(self::TrackLength, self::DefaultTrackLength);
        $length = is_numeric($value) ? (float) $value : (float) self::DefaultTrackLength;

        if ($length <= 0) {
            $length = (float) self::DefaultTrackLength;
        }

        return number_format($length, 3, '.', '');
    }

    /**
     * Token for GET /timing/lineups.json. Created on first use so Settings can show a copyable URL.
     */
    public static function timingFeedToken(): string
    {
        $token = static::get(self::TimingFeedToken);

        if (filled($token)) {
            return $token;
        }

        return static::replaceTimingFeedToken();
    }

    public static function replaceTimingFeedToken(): string
    {
        $token = Str::random(40);
        static::put(self::TimingFeedToken, $token);

        return $token;
    }

    public static function timingFeedTokenMatches(string $provided): bool
    {
        $expected = (string) static::get(self::TimingFeedToken, '');

        return filled($expected) && filled($provided) && hash_equals($expected, $provided);
    }

    public static function activeDriver(): ?Competitor
    {
        $id = static::get(self::ActiveDriver);

        return $id ? Competitor::query()->find($id) : null;
    }

    /**
     * Stored path of an uploaded letterhead logo, or null when the built-in image should be used.
     */
    public static function letterheadPath(string $side): ?string
    {
        return static::get(static::letterheadKey($side));
    }

    /**
     * Public URL of the uploaded letterhead logo, or the built-in image when none has been uploaded.
     */
    public static function letterheadUrl(string $side): string
    {
        $path = static::letterheadPath($side);

        return $path ? MediaStorage::url($path) : asset('images/letterhead-'.$side.'.png');
    }

    /**
     * Shared letterhead for every print sheet.
     *
     * @return array{leftLogoUrl: string, rightLogoUrl: string, printHeading: string, printDate: string, printVenueName: string, printVenue: string}
     */
    public static function printLetterhead(): array
    {
        return [
            'leftLogoUrl' => static::letterheadUrl('left'),
            'rightLogoUrl' => static::letterheadUrl('right'),
            'printHeading' => (string) static::get(self::PrintHeading, ''),
            'printDate' => (string) static::get(self::PrintDate, ''),
            'printVenueName' => (string) static::get(self::PrintVenueName, ''),
            'printVenue' => (string) static::get(self::PrintVenue, ''),
        ];
    }

    /**
     * Validate and save a letterhead logo, returning an error message instead of throwing when it can't be used.
     */
    public static function replaceLetterhead(string $side, mixed $photo): ?string
    {
        $validator = Validator::make(['logo' => $photo], ['logo' => ['required', ...self::LetterheadRules]]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        try {
            static::storeLetterhead($side, $photo);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    public static function storeLetterhead(string $side, UploadedFile $photo): void
    {
        $path = app(ImageCropper::class)->storePng(
            $photo->getRealPath(),
            self::LetterheadDirectory,
            MediaStorage::diskName(),
        );

        static::deleteLetterhead($side);
        static::put(static::letterheadKey($side), $path);
    }

    public static function deleteLetterhead(string $side): void
    {
        $path = static::letterheadPath($side);

        if ($path) {
            MediaStorage::disk()->delete($path);
        }

        static::put(static::letterheadKey($side), null);
    }

    public static function letterheadKey(string $side): string
    {
        return match ($side) {
            'left' => self::LetterheadLeft,
            'right' => self::LetterheadRight,
        };
    }
}
