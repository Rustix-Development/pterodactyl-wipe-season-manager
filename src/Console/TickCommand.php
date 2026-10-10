<?php

namespace RustixDev\WipeSeasonManager\Console;

use Illuminate\Console\Command;
use RustixDev\WipeSeasonManager\Services\WipeScheduler;

class TickCommand extends Command
{
    protected $signature = 'wipe-season-manager:tick';

    protected $description = 'Send wipe warnings and move scheduled wipes forward.';

    public function handle(WipeScheduler $scheduler): int
    {
        $scheduler->tick();

        return self::SUCCESS;
    }
}
