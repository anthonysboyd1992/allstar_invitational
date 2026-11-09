<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TimingPullerDownloadController extends Controller
{
    public const DownloadName = 'Allstar Invitational Race Manager Puller.zip';

    public const PublicPath = 'downloads/allstar-invitational-race-manager-puller.zip';

    /**
     * Windows puller zip built locally by tools/timing-pull (`npm run dist`).
     */
    public static function zipPath(): string
    {
        return public_path(self::PublicPath);
    }

    public function __invoke(): BinaryFileResponse
    {
        abort_unless(is_file(self::zipPath()), 404);

        return response()->download(self::zipPath(), self::DownloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
