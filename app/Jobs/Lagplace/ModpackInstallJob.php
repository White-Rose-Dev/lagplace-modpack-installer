<?php

namespace Pterodactyl\Jobs\Lagplace;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Services\Lagplace\McJarsInstallService;
use Pterodactyl\Services\Lagplace\Modpacks\DaemonServerFiles;
use Pterodactyl\Services\Lagplace\Modpacks\EloquentBackupGateway;
use Pterodactyl\Services\Lagplace\Modpacks\McJarsLoaderInstaller;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackInstaller;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackProviders;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackSources;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackState;
use Pterodactyl\Services\Lagplace\Modpacks\PollingBackupRunner;
use Pterodactyl\Services\Lagplace\Modpacks\RedirectResolver;

/**
 * Instala um modpack Modrinth num servidor. Corre na fila "modpacks" (worker próprio: demora minutos e o
 * pteroq normal é um só, ficaria bloqueado) e nunca é repetido automaticamente: é destrutivo, e o estado
 * (ModpackState) é a única fonte de verdade para o utilizador.
 */
#[DeleteWhenMissingModels]
class ModpackInstallJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 7200;

    public function __construct(public readonly int $serverId, public readonly string $versionId, public readonly string $provider = 'modrinth')
    {
        $this->onQueue('modpacks');
    }

    public function handle(
        ModpackSources $sources,
        ModpackState $state,
        McJarsInstallService $mcJars,
        InitiateBackupService $backups,
        DaemonFileRepository $files,
        Http $http,
    ): void {
        $server = Server::query()->with('allocation')->find($this->serverId);
        if ($server === null) {
            return;
        }

        // Guarda contra execução duplicada (ex.: a fila devolve o job): só corre quem foi registado como "queued".
        $current = $state->get($this->serverId);
        if ($current === null || ($current['stage'] ?? '') !== 'queued') {
            Log::warning('[lagplace][modpacks] job ignored, install is not queued', ['server_id' => $this->serverId, 'stage' => $current['stage'] ?? null]);

            return;
        }

        try {
            $plan = $sources->get(ModpackProviders::require($this->provider))->plan($this->versionId);
            $installer = new ModpackInstaller(
                DaemonServerFiles::connect($files, $server, fn (string $m) => Log::warning('[lagplace][modpacks] ' . $m, ['server_id' => $server->id])),
                new McJarsLoaderInstaller($mcJars, $server),
                new PollingBackupRunner(new EloquentBackupGateway($backups, $server), $state, $server->id, fn (int $s) => sleep($s), fn () => time()),
                $state,
                new RedirectResolver($http),
                ['id' => $server->id, 'diskMb' => (int) $server->disk, 'port' => (int) ($server->allocation->port ?? 25565)],
            );
            $installer->install($plan->pack, $plan->loaderBuildId, $plan->mrpackSize, $plan->mrpackUrl);

            Log::info('[lagplace][modpacks] installed', ['server_id' => $server->id, 'pack' => $plan->pack->name, 'version' => $plan->pack->versionId]);
        } catch (\Throwable $e) {
            // O instalador já regista a falha no estado quando o erro vem dele; isto cobre o que falha antes (planeamento).
            $now = $state->get($this->serverId);
            if ($now !== null && !in_array($now['stage'], ['done', 'failed', 'cancelled'], true)) {
                $state->finish($this->serverId, $e instanceof ModpackException ? $e->getMessage() : 'Unexpected error while preparing the modpack.');
            }
            Log::warning('[lagplace][modpacks] install failed', ['server_id' => $this->serverId, 'provider' => $this->provider, 'version' => $this->versionId, 'error' => $e->getMessage()]);
        }
    }
}
