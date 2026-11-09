<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TimingPullerDownloadController extends Controller
{
    public const DownloadName = 'Allstar Invitational Race Manager Puller.zip';

    /**
     * Windows puller zip built by tools/timing-pull (`npm run dist`).
     */
    public static function zipPath(): string
    {
        return storage_path('app/private/timing-puller.zip');
    }

    public function __invoke(): BinaryFileResponse
    {
        abort_unless(is_file(self::zipPath()), 404);

        return response()->download(self::zipPath(), self::DownloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
