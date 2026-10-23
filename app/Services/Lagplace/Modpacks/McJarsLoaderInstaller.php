<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Lagplace\McJarsInstallService;

/** Instala o loader pelo mesmo serviço da aba Versions (limpa o servidor, jar, Java e variáveis do egg). */
final class McJarsLoaderInstaller implements LoaderInstaller
{
    public function __construct(private McJarsInstallService $service, private Server $server)
    {
    }

    public function install(string $type, string $minecraft, int $buildId): void
    {
        try {
            $result = $this->service->install($this->server, $type, $minecraft, $buildId, true);
        } catch (\Throwable $e) {
            Log::warning('[lagplace][modpacks] loader install threw', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);

            throw new ModpackException('The mod loader could not be installed on this server.', 0, $e);
        }
        if (!$result['success']) {
            Log::warning('[lagplace][modpacks] loader install failed', ['server_id' => $this->server->id, 'message' => $result['message']]);

            throw new ModpackException('The mod loader could not be installed on this server.');
        }
    }
}
