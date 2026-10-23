<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

interface BackupGateway
{
    /** Inicia um backup do servidor e devolve o uuid. Lança ModpackException com mensagem para o utilizador se não puder. */
    public function start(): string;

    /** @return array{done:bool,ok:bool} */
    public function status(string $uuid): array;
}
