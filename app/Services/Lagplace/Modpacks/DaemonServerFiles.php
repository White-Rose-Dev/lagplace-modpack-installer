<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/** Adapta o DaemonFileRepository (Wings) à interface ServerFiles: caminhos relativos à raiz do servidor. */
final class DaemonServerFiles implements ServerFiles
{
    public function __construct(private DaemonFileRepository $repo, private Server $server)
    {
    }

    public function pull(string $url, string $dir, string $filename): void
    {
        // Em primeiro plano o Wings só responde com o ficheiro completo: 30 min cobrem packs de centenas de MB em ligações lentas.
        $this->repo()->pull($url, '/' . $dir, ['filename' => $filename, 'foreground' => true, 'timeout' => 1800]);
    }

    public function pullMany(array $items, int $concurrency): array
    {
        return $this->repo()->pullMany($items, $concurrency, 1800);
    }

    /**
     * Cria o adaptador a falar com o Wings pelo endereço da LAN do nó (`lagplace_lan_url`) quando existe e responde; senão (ou se
     * falhar uma leitura de teste) usa o endereço normal. `$log` recebe a mensagem quando se cai no endereço normal.
     */
    public static function connect(DaemonFileRepository $repo, Server $server, ?callable $log = null): self
    {
        $files = new self($repo, $server);
        $lan = $server->node?->getAttribute('lagplace_lan_url');
        if (is_string($lan) && $lan !== '') {
            $repo->useBaseUri($lan);
            try {
                $repo->setServer($server)->getDirectory('/');
            } catch (\Throwable $e) {
                $repo->useBaseUri(null);
                if ($log !== null) {
                    $log('LAN address of the node did not answer, using the public address: ' . $e->getMessage());
                }
            }
        }

        return $files;
    }

    public function decompress(string $dir, string $file): void
    {
        $this->repo()->decompressFile('/' . $dir, $file);
    }

    public function move(string $from, string $to): void
    {
        $this->repo()->renameFiles('/', [['from' => $from, 'to' => $to]]);
    }

    public function delete(array $paths): void
    {
        $this->repo()->deleteFiles('/', array_values($paths));
    }

    public function mkdir(string $path): void
    {
        $this->repo()->createDirectory($path, '/');
    }

    public function list(string $dir): array
    {
        $out = [];
        foreach ($this->repo()->getDirectory('/' . $dir) as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
                continue;
            }
            $out[$entry['name']] = ['size' => (int) ($entry['size'] ?? 0), 'is_dir' => (bool) ($entry['directory'] ?? false)];
        }

        return $out;
    }

    public function read(string $path, int $maxBytes): string
    {
        return $this->repo()->getContent('/' . $path, $maxBytes);
    }

    public function write(string $path, string $content): void
    {
        $this->repo()->putContent('/' . $path, $content);
    }

    private function repo(): DaemonFileRepository
    {
        return $this->repo->setServer($this->server);
    }
}
