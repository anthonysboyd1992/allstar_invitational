<?php

namespace App\Console\Commands;

use App\Services\MediaStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('media:copy {from=public : Disk to copy from} {to=s3 : Disk to copy to}')]
#[Description('Copy uploaded headshots and vMix images from one storage disk to another, e.g. before switching VMIX_MEDIA_DISK to s3')]
class CopyMediaToDisk extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $from = Storage::disk($this->argument('from'));
        $to = Storage::disk($this->argument('to'));
        $copied = 0;
        $skipped = 0;

        foreach (MediaStorage::Directories as $directory) {
            foreach ($from->allFiles($directory) as $path) {
                if ($to->exists($path)) {
                    $skipped++;

                    continue;
                }

                $to->writeStream($path, $from->readStream($path));
                $this->line("Copied {$path}");
                $copied++;
            }
        }

        $this->info("Copied {$copied} file(s), skipped {$skipped} already on '{$this->argument('to')}'.");

        return self::SUCCESS;
    }
}
