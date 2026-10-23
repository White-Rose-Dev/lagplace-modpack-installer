<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

interface LoaderInstaller
{
    /** Limpa o servidor e instala o loader (jar, Java, variáveis do egg). $type: FORGE|NEOFORGE|FABRIC|QUILT. */
    public function install(string $type, string $minecraft, int $buildId): void;
}
