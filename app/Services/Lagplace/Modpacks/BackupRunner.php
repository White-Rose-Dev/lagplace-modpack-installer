<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

interface BackupRunner
{
    /** Cria um backup do servidor e espera que termine com sucesso. @return string uuid do backup (lança se falhar) */
    public function runAndWait(): string;
}
