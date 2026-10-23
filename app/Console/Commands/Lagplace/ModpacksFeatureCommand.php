<?php

namespace Pterodactyl\Console\Commands\Lagplace;

use Illuminate\Console\Command;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackFeature;

/** Liga/desliga o instalador de modpacks (setting lagplace::modpacks_enabled). Desligado = só admins veem a aba. */
class ModpacksFeatureCommand extends Command
{
    protected $signature = 'lagplace:modpacks {state=status : on, off or status}';
    protected $description = 'Switch the modpack installer on or off for everyone (admins always have it).';

    public function handle(ModpackFeature $feature): int
    {
        $state = strtolower((string) $this->argument('state'));
        if (!in_array($state, ['on', 'off', 'status'], true)) {
            $this->error('Use: on, off or status.');

            return self::FAILURE;
        }
        if ($state !== 'status') {
            $feature->set($state === 'on');
        }
        $this->info('Modpack installer: ' . ($feature->enabled() ? 'ON (advanced users can use it)' : 'OFF (only admins)'));
        if ($state !== 'status') {
            $this->line('Users see the change after reloading the page.');
        }

        return self::SUCCESS;
    }
}
