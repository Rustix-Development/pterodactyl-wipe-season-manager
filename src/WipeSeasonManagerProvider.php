<?php

namespace RustixDev\WipeSeasonManager;

use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use RustixDev\WipeSeasonManager\Console\TickCommand;
use RustixDev\WipeSeasonManager\Http\Controllers\PlanController;
use RustixDev\WipeSeasonManager\Services\WipeRunner;

class WipeSeasonManagerProvider extends ExtensionProvider
{
    public function boot(): void
    {
        $this->registerApiRoutes();

        $this->registerPermissions('Wipe & Season Manager', [
            'manage' => 'Create, change and run wipe plans, and see the wipe history.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('stop_wait_seconds', 'stop_wait_seconds', 120, ['integer', 'min:10', 'max:900'])
                ->field('number')
                ->label('Seconds to wait for a server to stop')
                ->help('After this the server is killed so the wipe can go ahead.'),
            ExtensionSettingDefinition::make('backup_wait_minutes', 'backup_wait_minutes', 30, ['integer', 'min:1', 'max:240'])
                ->field('number')
                ->label('Minutes to wait for the backup')
                ->help('If the backup has not finished by then, the wipe is cancelled and nothing is deleted.'),
            ExtensionSettingDefinition::make('max_plans', 'max_plans', 5, ['integer', 'min:1', 'max:20'])
                ->field('number')
                ->label('Plans per server'),
            ExtensionSettingDefinition::make('allow_discord', 'allow_discord', true, ['boolean'])
                ->field('toggle')
                ->label('Allow Discord posts')
                ->frontend(),
        ]));

        $this->registerCommands([TickCommand::class]);
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->command('wipe-season-manager:tick')->everyMinute()->withoutOverlapping(10);
        });

        foreach ([WipeRunner::class, PlanController::class] as $class) {
            $this->app->when($class)->needs(ExtensionSettings::class)->give(fn () => $this->settings());
        }
    }
}
