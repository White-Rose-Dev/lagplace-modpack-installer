<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

use Pterodactyl\Exceptions\Service\Backup\TooManyBackupsException;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** Backup "a sério" do Pterodactyl, bloqueado (nunca rodado automaticamente) e a substituir o mais antigo se faltar espaço. */
final class EloquentBackupGateway implements BackupGateway
{
    public function __construct(private InitiateBackupService $service, private Server $server)
    {
    }

    public function start(): string
    {
        try {
            return $this->service->setIgnoredFiles([])->setIsLocked(true)->handle($this->server, 'Pre-modpack backup', true)->uuid;
        } catch (TooManyBackupsException) {
            throw new ModpackException('This server has no free backup slot. Delete an old backup (or ask an admin to raise the limit) and try again.');
        } catch (TooManyRequestsHttpException) {
            throw new ModpackException('Too many backups were created recently. Wait a few minutes and try again.');
        }
    }

    public function status(string $uuid): array
    {
        $backup = Backup::query()->where('uuid', $uuid)->first();
        if ($backup === null) {
            return ['done' => true, 'ok' => false];
        }

        return ['done' => $backup->completed_at !== null, 'ok' => (bool) $backup->is_successful];
    }
}
