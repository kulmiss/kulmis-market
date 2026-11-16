<?php

namespace App\Console\Commands;

use App\Help\AdminGuide;
use Illuminate\Console\Command;

/** docs/admin-guide.pdf: the whole Super Admin guide in one file, to read offline. */
class GuidePdf extends Command
{
    protected $signature = 'km:guide-pdf {--out=docs/admin-guide.pdf}';

    protected $description = 'Make the Super Admin guide as one PDF (numbers from the current settings)';

    public function handle(): int
    {
        $out = base_path((string) $this->option('out'));
        file_put_contents($out, AdminGuide::pdf());
        $this->info('Saved '.$this->option('out').' ('.round(filesize($out) / 1024).' KB, '.count(AdminGuide::pages()).' pages of the guide).');

        return self::SUCCESS;
    }
}
