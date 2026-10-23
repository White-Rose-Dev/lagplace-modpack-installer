<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/**
 * Instala um modpack Modrinth como servidor completo. Toda a I/O passa por interfaces estreitas
 * (ServerFiles, LoaderInstaller, BackupRunner) para poder ser testada sem Wings.
 *
 * Ordem fixa: validar → backup → loader (limpa o servidor) → .mrpack → overrides → mods → verificar → finalizar.
 * Nada destrutivo acontece antes de o pack estar totalmente validado (ParsedPack) e o backup concluído.
 */
final class ModpackInstaller
{
    private const TMP = '.modpack-tmp';
    private const REDIRECT_HOSTS = ['github.com', 'gitlab.com'];
    private const FORCED_PROPERTIES = ['enable-query' => 'true', 'server-ip' => '0.0.0.0'];

    /** @var callable(string):string */
    private $resolveRedirect;
    private int $concurrency;
    private int $batch;

    /**
     * @param array{id:int,diskMb:int,port:int} $server  diskMb 0 = ilimitado
     * @param array{concurrency?:int,batch?:int} $tuning concurrency: pulls em simultâneo (o Wings aceita 3 por servidor);
     *                                                   batch: ficheiros por lote (cancelamento e progresso entre lotes)
     */
    public function __construct(
        private ServerFiles $files,
        private LoaderInstaller $loader,
        private BackupRunner $backup,
        private ModpackState $state,
        callable $resolveRedirect,
        private array $server,
        array $tuning = [],
    ) {
        $this->resolveRedirect = $resolveRedirect;
        $this->concurrency = max(1, (int) ($tuning['concurrency'] ?? 3));
        $this->batch = max(1, (int) ($tuning['batch'] ?? 30));
    }

    /** $mrpackUrl é null quando o fornecedor não tem arquivo do pack (Feed The Beast): a lista de ficheiros já traz tudo. */
    public function install(ParsedPack $pack, int $loaderBuildId, int $mrpackSize, ?string $mrpackUrl): void
    {
        $id = $this->server['id'];
        $wiped = false;

        try {
            $this->state->update($id, 'validating', 1);
            $this->preflight($pack, $mrpackSize, $mrpackUrl);
            $this->checkCancel();

            $this->state->update($id, 'backup', 3, 'Creating a backup of the current files');
            $uuid = $this->backup->runAndWait();
            $this->state->update($id, 'backup', 15, null, ['backup_uuid' => $uuid]);
            $this->checkCancel();

            // Marcado antes da chamada: o loader limpa o servidor logo no início.
            $this->state->update($id, 'loader', 16, "Installing {$pack->loader} {$pack->loaderVersion}", ['wiped' => true]);
            $wiped = true;
            $this->loader->install(LoaderMap::mcjarsType($pack->loader), $pack->minecraft, $loaderBuildId);
            $this->checkCancel();

            if ($mrpackUrl !== null) {
                $this->fetchPack($mrpackUrl);
                $this->checkCancel();
                $this->mergeOverrides();
            }
            $this->downloadFiles($pack);
            $this->verify($pack);
            $this->finalize($pack);

            $this->state->finish($id);
        } catch (ModpackCancelled) {
            $this->removeTemp();
            $this->state->finish($id, $wiped
                ? 'Cancelled. The server was left empty; restore the backup to get your files back.'
                : 'Cancelled before any change was made.', 'cancelled');
        } catch (\Throwable $e) {
            $this->removeTemp();
            $message = $e instanceof ModpackException ? $e->getMessage() : 'Unexpected error while installing the modpack.';
            $this->state->finish($id, $message);
            throw $e instanceof ModpackException ? $e : new ModpackException($message, 0, $e);
        }
    }

    private function preflight(ParsedPack $pack, int $mrpackSize, ?string $mrpackUrl): void
    {
        if ($mrpackUrl !== null && !MrpackIndex::isAllowedUrl($mrpackUrl)) {
            throw new ModpackException('The modpack file is hosted somewhere that is not allowed.');
        }
        self::checkDisk($pack->totalBytes, $mrpackSize, (int) $this->server['diskMb']);
    }

    /** Estimativa conservadora do pico de disco: mods declarados + 1,5x o .mrpack (overrides extraídos). diskMb 0 = ilimitado. */
    public static function checkDisk(int $totalBytes, int $mrpackSize, int $diskMb): void
    {
        if ($diskMb <= 0) {
            return;
        }
        $need = $totalBytes + (int) round(1.5 * $mrpackSize);
        $limit = $diskMb * 1048576;
        if ($need > (int) floor($limit * 0.95)) {
            throw new ModpackException(sprintf('This pack needs about %s of disk space, but this server only has %s.', self::human($need), self::human($limit)));
        }
    }

    private function fetchPack(string $mrpackUrl): void
    {
        $id = $this->server['id'];
        $this->state->update($id, 'pack', 25, 'Downloading the modpack');
        $this->files->mkdir(self::TMP);
        $this->pullWithRetry($mrpackUrl, self::TMP, 'pack.zip', 'the modpack file');
        $this->state->update($id, 'pack', 32, 'Unpacking the modpack');
        $this->files->decompress(self::TMP, 'pack.zip');
        $this->files->delete([self::TMP . '/pack.zip']);
    }

    private function mergeOverrides(): void
    {
        $id = $this->server['id'];
        $this->state->update($id, 'overrides', 35, 'Applying the pack configuration');
        $top = $this->files->list(self::TMP);
        foreach (['overrides', 'server-overrides'] as $name) {
            if (isset($top[$name]) && $top[$name]['is_dir']) {
                $this->mergeDir(self::TMP . '/' . $name, '', 0);
            }
        }
        $this->state->update($id, 'overrides', 45);
    }

    private function mergeDir(string $from, string $to, int $depth): void
    {
        $src = $this->files->list($from);
        if ($src === []) {
            return;
        }
        $dst = $this->files->list($to);

        foreach ($src as $name => $info) {
            $fromPath = ltrim("$from/$name", '/');
            $toPath = ltrim("$to/$name", '/');
            if (!isset($dst[$name])) {
                $this->files->move($fromPath, $toPath);
            } elseif ($info['is_dir'] && $dst[$name]['is_dir'] && $depth < 10) {
                $this->mergeDir($fromPath, $toPath, $depth + 1);
            } else {
                $this->files->delete([$toPath]);
                $this->files->move($fromPath, $toPath);
            }
        }

        // O Wings ignora em silêncio um rename cuja origem ou pasta de destino não existe.
        $after = $this->files->list($to);
        foreach (array_keys($src) as $name) {
            if (!isset($after[$name])) {
                throw new ModpackException('Could not place ' . ltrim("$to/$name", '/') . ' from the modpack.');
            }
        }
    }

    private function downloadFiles(ParsedPack $pack): void
    {
        $id = $this->server['id'];
        $files = $pack->files;
        $total = count($files);

        $dirs = [];
        foreach ($files as $file) {
            $dir = dirname($file->path);
            if ($dir !== '.') {
                $dirs[$dir] = true;
            }
        }
        foreach (array_keys($dirs) as $dir) {
            $this->files->mkdir($dir);
        }

        $done = 0;
        foreach (array_chunk($files, $this->batch) as $chunk) {
            $this->checkCancel();
            $this->state->update($id, 'mods', 45 + (int) floor(43 * $done / max(1, $total)), $chunk[0]->path, ['done' => $done, 'total' => $total]);
            $this->pullBatch($chunk);
            $done += count($chunk);
        }
        $this->state->update($id, 'mods', 88, null, ['done' => $total, 'total' => $total]);
    }

    /**
     * Descarrega um lote com $concurrency pulls em simultâneo. Os que falham são repetidos uma vez; se ainda falharem, a instalação
     * pára a dizer qual.
     *
     * @param list<PackFile> $files
     */
    private function pullBatch(array $files): void
    {
        $items = [];
        $labels = [];
        foreach ($files as $file) {
            $dir = dirname($file->path);
            $items[] = ['url' => $this->finalUrl($file), 'dir' => $dir === '.' ? '' : $dir, 'filename' => basename($file->path)];
            $labels[] = $file->path;
        }

        $failed = $this->files->pullMany($items, $this->concurrency);
        if ($failed === []) {
            return;
        }

        $retry = array_map(fn (int $i) => $items[$i], $failed);
        $retryLabels = array_map(fn (int $i) => $labels[$i], $failed);
        $failedAgain = $this->files->pullMany($retry, $this->concurrency);
        if ($failedAgain !== []) {
            $extra = count($failedAgain) > 1 ? ' and ' . (count($failedAgain) - 1) . ' more' : '';
            throw new ModpackException('Could not download ' . $retryLabels[$failedAgain[0]] . $extra . '.');
        }
    }

    /**
     * O edge.forgecdn.net responde 302 para o mediafilez.forgecdn.net e o Wings não segue redirects (daria erro 500). A troca é fixa e
     * conhecida, por isso evita-se um pedido por ficheiro.
     */
    public static function normalizeUrl(string $url): string
    {
        return preg_replace('#^https://edge\.forgecdn\.net/#', 'https://mediafilez.forgecdn.net/', $url) ?? $url;
    }

    private function finalUrl(PackFile $file): string
    {
        $url = self::normalizeUrl($file->urls[0]);
        if (in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::REDIRECT_HOSTS, true)) {
            $final = ($this->resolveRedirect)($url);
            if (!MrpackIndex::isAllowedFinalUrl($final)) {
                throw new ModpackException("The download source for {$file->path} redirects somewhere that is not allowed.");
            }

            return $final;
        }

        return $url;
    }

    private function pullWithRetry(string $url, string $dir, string $filename, string $label): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->files->pull($url, $dir, $filename);

                return;
            } catch (\Throwable $e) {
                if ($attempt >= 2) {
                    throw new ModpackException("Could not download $label.", 0, $e);
                }
            }
        }
    }

    private function verify(ParsedPack $pack): void
    {
        $this->state->update($this->server['id'], 'verify', 90, 'Checking the downloaded files');
        $bad = $this->mismatches($pack->files);
        if ($bad !== []) {
            foreach (array_chunk($bad, $this->batch) as $chunk) {
                $this->pullBatch($chunk);
            }
            $bad = $this->mismatches($bad);
        }
        if ($bad !== []) {
            $extra = count($bad) > 1 ? ' and ' . (count($bad) - 1) . ' more' : '';
            throw new ModpackException("Some files did not download correctly ({$bad[0]->path}$extra).");
        }
    }

    /**
     * Compara o tamanho de cada ficheiro com o declarado (o Wings não verifica hashes). Uma listagem por pasta.
     *
     * @param list<PackFile> $files
     * @return list<PackFile>
     */
    private function mismatches(array $files): array
    {
        $byDir = [];
        foreach ($files as $file) {
            $dir = dirname($file->path);
            $byDir[$dir === '.' ? '' : $dir][] = $file;
        }

        $bad = [];
        foreach ($byDir as $dir => $list) {
            $listing = $this->files->list((string) $dir);
            foreach ($list as $file) {
                $entry = $listing[basename($file->path)] ?? null;
                if ($entry === null || $entry['is_dir'] || $entry['size'] !== $file->size) {
                    $bad[] = $file;
                }
            }
        }

        return $bad;
    }

    private function finalize(ParsedPack $pack): void
    {
        $id = $this->server['id'];
        $this->state->update($id, 'finalize', 96, 'Finishing up');

        $root = $this->files->list('');
        $current = isset($root['server.properties']) && !$root['server.properties']['is_dir']
            ? $this->files->read('server.properties', 1048576) : '';
        $this->files->write('server.properties', $this->forceProperties($current));

        $this->files->mkdir('.modpack');
        $this->files->write('.modpack/manifest.json', json_encode([
            'pack' => $pack->name,
            'versionId' => $pack->versionId,
            'minecraft' => $pack->minecraft,
            'loader' => $pack->loader,
            'loaderVersion' => $pack->loaderVersion,
            'installed_at' => gmdate('c'),
            'files' => array_map(fn (PackFile $f) => $f->path, $pack->files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->files->write('eula.txt', "eula=true\n");

        $this->removeTemp();
    }

    /** Mantém tudo o que o pack definiu, mas repõe porta/query/ip da alocação (senão o servidor fica inalcançável). */
    private function forceProperties(string $text): string
    {
        $forced = self::FORCED_PROPERTIES + ['server-port' => (string) $this->server['port'], 'query.port' => (string) $this->server['port']];
        $lines = $text === '' ? [] : preg_split('/\R/', rtrim($text, "\r\n"));

        $out = [];
        $done = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Za-z0-9._-]+)\s*=/', $line, $m) === 1 && isset($forced[$m[1]])) {
                if (!isset($done[$m[1]])) {
                    $out[] = $m[1] . '=' . $forced[$m[1]];
                    $done[$m[1]] = true;
                }

                continue;
            }
            $out[] = $line;
        }
        foreach ($forced as $key => $value) {
            if (!isset($done[$key])) {
                $out[] = "$key=$value";
            }
        }

        return implode("\n", $out) . "\n";
    }

    private function checkCancel(): void
    {
        if ($this->state->cancelRequested($this->server['id'])) {
            throw new ModpackCancelled('Cancelled by the user.');
        }
    }

    private function removeTemp(): void
    {
        try {
            $this->files->delete([self::TMP]);
        } catch (\Throwable) {
            // pode não existir; nunca esconder o erro original
        }
    }

    private static function human(int $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 1) . ' GB' : round($bytes / 1048576) . ' MB';
    }
}
