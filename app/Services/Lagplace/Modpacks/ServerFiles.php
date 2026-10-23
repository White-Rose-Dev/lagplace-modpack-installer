<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/**
 * Operações de ficheiros num servidor (via Wings). Todos os caminhos são relativos à raiz do servidor,
 * sem "/" inicial; '' é a raiz. mkdir é idempotente e cria os ancestrais.
 */
interface ServerFiles
{
    /** Descarrega $url para $dir/$filename e só devolve quando o ficheiro está completo. */
    public function pull(string $url, string $dir, string $filename): void;

    /**
     * Vários pulls em simultâneo (no máximo $concurrency ao mesmo tempo). Devolve os índices (de $items) que falharam; nunca lança
     * por um item individual.
     *
     * @param list<array{url:string,dir:string,filename:string}> $items
     * @return list<int>
     */
    public function pullMany(array $items, int $concurrency): array;

    /** Extrai $dir/$file para $dir. */
    public function decompress(string $dir, string $file): void;

    /** Renomeia/move; falha se o destino já existir. */
    public function move(string $from, string $to): void;

    /** @param list<string> $paths */
    public function delete(array $paths): void;

    public function mkdir(string $path): void;

    /** @return array<string, array{size:int,is_dir:bool}> filhos diretos, por nome */
    public function list(string $dir): array;

    public function read(string $path, int $maxBytes): string;

    public function write(string $path, string $content): void;
}
