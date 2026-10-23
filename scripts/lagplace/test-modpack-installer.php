<?php
// Teste do orquestrador de instalação de modpacks contra um servidor simulado em memória.
// Correr: php scripts/lagplace/test-modpack-installer.php
require (getenv('PANEL_DIR') ?: '/var/www/pterodactyl') . '/vendor/autoload.php';
$dir = __DIR__ . '/../../app/Services/Lagplace/Modpacks/';
foreach (['ModpackException', 'ModpackCancelled', 'PackPath', 'PackFile', 'ParsedPack', 'MrpackIndex', 'LoaderMap', 'ModpackState', 'ServerFiles', 'LoaderInstaller', 'BackupRunner', 'ModpackInstaller'] as $c) {
    require_once $dir . $c . '.php';
}

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Pterodactyl\Services\Lagplace\Modpacks\BackupRunner;
use Pterodactyl\Services\Lagplace\Modpacks\LoaderInstaller;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackInstaller;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackState;
use Pterodactyl\Services\Lagplace\Modpacks\MrpackIndex;
use Pterodactyl\Services\Lagplace\Modpacks\ServerFiles;

/** Servidor em memória que imita o Wings 1.13.4: rename silencioso se a origem/pasta-destino não existe, mkdir idempotente. */
final class FakeServerFiles implements ServerFiles
{
    /** @var array<string, array{dir:bool,size:int,archive:?array}> */
    public array $tree = ['' => ['dir' => true, 'size' => 0, 'archive' => null]];
    /** @var array<string, array{size:int,archive:?array,badSizeTimes:int,onPull:?callable,failTimes:int}> */
    public array $remote = [];
    public array $log = [];
    public int $pulls = 0;
    public array $contents = [];
    /** @var list<string> destinos cujo move() falha em silêncio (como o Wings quando a origem/pasta não existe) */
    public array $dropMoves = [];

    public function addRemote(string $url, int $size, ?array $archive = null, int $badSizeTimes = 0, ?callable $onPull = null, int $failTimes = 0): void
    {
        $this->remote[$url] = ['size' => $size, 'archive' => $archive, 'badSizeTimes' => $badSizeTimes, 'onPull' => $onPull, 'failTimes' => $failTimes];
    }

    public function seed(string $path, string $content = 'x'): void
    {
        $this->mkdir(dirname($path) === '.' ? '' : dirname($path));
        $this->tree[$path] = ['dir' => false, 'size' => strlen($content), 'archive' => null];
        $this->contents[$path] = $content;
    }

    public function exists(string $p): bool
    {
        return isset($this->tree[$p]);
    }

    /** @return list<string> */
    public function files(): array
    {
        $out = array_keys(array_filter($this->tree, fn ($n, $p) => $p !== '' && !$n['dir'], ARRAY_FILTER_USE_BOTH));
        sort($out);

        return $out;
    }

    public function pull(string $url, string $dir, string $filename): void
    {
        $this->log[] = ['pull', $url, $dir, $filename];
        $this->pulls++;
        $r = &$this->remote[$url];
        if (!isset($r)) {
            throw new \RuntimeException("404 $url");
        }
        if ($r['onPull']) {
            ($r['onPull'])($url);
        }
        if ($r['failTimes'] > 0) {
            $r['failTimes']--;
            throw new \RuntimeException("network error for $url");
        }
        if (!$this->exists($dir)) {
            throw new \RuntimeException("pull: target directory $dir does not exist");
        }
        $size = $r['size'];
        if ($r['badSizeTimes'] > 0) {
            $r['badSizeTimes']--;
            $size = max(0, $size - 1);
        }
        $path = ltrim($dir . '/' . $filename, '/');
        $this->tree[$path] = ['dir' => false, 'size' => $size, 'archive' => $r['archive']];
        $this->contents[$path] = 'remote:' . $url;
    }

    /** @var list<array{size:int,concurrency:int}> */
    public array $batches = [];

    public function pullMany(array $items, int $concurrency): array
    {
        $this->batches[] = ['size' => count($items), 'concurrency' => $concurrency];
        $failed = [];
        foreach ($items as $i => $item) {
            try {
                $this->pull($item['url'], $item['dir'], $item['filename']);
            } catch (\Throwable) {
                $failed[] = $i;
            }
        }

        return $failed;
    }

    public function decompress(string $dir, string $file): void
    {
        $this->log[] = ['decompress', $dir, $file];
        $node = $this->tree[ltrim("$dir/$file", '/')] ?? null;
        if (!$node || $node['archive'] === null) {
            throw new \RuntimeException('not an archive');
        }
        foreach ($node['archive'] as $path => $content) {
            $full = ltrim("$dir/$path", '/');
            $this->mkdir(dirname($full) === '.' ? '' : dirname($full));
            $this->tree[$full] = ['dir' => false, 'size' => strlen($content), 'archive' => null];
            $this->contents[$full] = $content;
        }
    }

    public function move(string $from, string $to): void
    {
        $this->log[] = ['move', $from, $to];
        if (!$this->exists($from) || in_array($to, $this->dropMoves, true)) {
            return;
        }
        $parent = dirname($to) === '.' ? '' : dirname($to);
        if (!$this->exists($parent)) {
            return;
        }
        if ($this->exists($to)) {
            if ($this->tree[$to]['dir'] && $this->children($to) !== []) {
                throw new \RuntimeException("ENOTEMPTY $to");
            }
            if ($this->tree[$to]['dir'] !== $this->tree[$from]['dir']) {
                throw new \RuntimeException("type mismatch $to");
            }
            unset($this->tree[$to], $this->contents[$to]);
        }
        foreach (array_keys($this->tree) as $p) {
            if ($p === $from || str_starts_with($p, $from . '/')) {
                $np = $to . substr($p, strlen($from));
                $this->tree[$np] = $this->tree[$p];
                if (isset($this->contents[$p])) {
                    $this->contents[$np] = $this->contents[$p];
                    unset($this->contents[$p]);
                }
                unset($this->tree[$p]);
            }
        }
    }

    public function delete(array $paths): void
    {
        $this->log[] = ['delete', $paths];
        foreach ($paths as $d) {
            foreach (array_keys($this->tree) as $p) {
                if ($p !== '' && ($p === $d || str_starts_with($p, $d . '/'))) {
                    unset($this->tree[$p], $this->contents[$p]);
                }
            }
        }
    }

    public function mkdir(string $path): void
    {
        $this->log[] = ['mkdir', $path];
        $acc = '';
        foreach ($path === '' ? [] : explode('/', $path) as $seg) {
            $acc = ltrim("$acc/$seg", '/');
            if (isset($this->tree[$acc]) && !$this->tree[$acc]['dir']) {
                throw new \RuntimeException("ENOTDIR $acc");
            }
            $this->tree[$acc] ??= ['dir' => true, 'size' => 0, 'archive' => null];
        }
    }

    public function list(string $dir): array
    {
        if (!$this->exists($dir)) {
            throw new \RuntimeException("list: $dir does not exist");
        }
        $out = [];
        foreach ($this->children($dir) as $name) {
            $p = ltrim("$dir/$name", '/');
            $out[$name] = ['size' => $this->tree[$p]['size'], 'is_dir' => $this->tree[$p]['dir']];
        }

        return $out;
    }

    public function read(string $path, int $maxBytes): string
    {
        if (!$this->exists($path)) {
            throw new \RuntimeException("read: $path does not exist");
        }

        return substr($this->contents[$path] ?? '', 0, $maxBytes);
    }

    public function write(string $path, string $content): void
    {
        $this->log[] = ['write', $path];
        $parent = dirname($path) === '.' ? '' : dirname($path);
        if (!$this->exists($parent)) {
            throw new \RuntimeException("write: parent $parent does not exist");
        }
        $this->tree[$path] = ['dir' => false, 'size' => strlen($content), 'archive' => null];
        $this->contents[$path] = $content;
    }

    /** @return list<string> */
    private function children(string $dir): array
    {
        $out = [];
        $prefix = $dir === '' ? '' : $dir . '/';
        foreach (array_keys($this->tree) as $p) {
            if ($p === '' || !str_starts_with($p, $prefix) || $p === $dir) {
                continue;
            }
            $rest = substr($p, strlen($prefix));
            if (!str_contains($rest, '/')) {
                $out[] = $rest;
            }
        }

        return $out;
    }
}

final class FakeLoader implements LoaderInstaller
{
    public array $calls = [];
    public ?\Throwable $fails = null;
    /** @var ?callable */
    public $onInstall = null;
    public function __construct(private FakeServerFiles $fs, private int $port)
    {
    }

    public function install(string $type, string $minecraft, int $buildId): void
    {
        $this->calls[] = [$type, $minecraft, $buildId];
        if ($this->onInstall) {
            ($this->onInstall)();
        }
        foreach (array_keys($this->fs->tree) as $p) {
            if ($p !== '' && $p !== 'backups' && !str_starts_with($p, 'backups/')) {
                unset($this->fs->tree[$p], $this->fs->contents[$p]);
            }
        }
        if ($this->fails) {
            throw $this->fails;
        }
        $this->fs->seed('server.jar', 'jar');
        $this->fs->seed('config/loader.toml', 'loader=1');
        $this->fs->seed('server.properties', "enable-query=true\nquery.port={$this->port}\nserver-port={$this->port}\nserver-ip=0.0.0.0\nmotd=A Minecraft Server\n");
    }
}

final class FakeBackup implements BackupRunner
{
    public int $calls = 0;
    public bool $fails = false;
    public function runAndWait(): string
    {
        $this->calls++;
        if ($this->fails) {
            throw new ModpackException('Backup failed');
        }

        return 'bk-uuid-1';
    }
}

$n = 0;
$f = 0;
$t = function (string $label, bool $ok) use (&$n, &$f) {
    $n++;
    if (!$ok) {
        $f++;
        echo "FALHOU: $label\n";
    }
};

const PORT = 25577;
const MRPACK_URL = 'https://cdn.modrinth.com/data/P/versions/V/pack.mrpack';
$cdn = fn (string $p) => 'https://cdn.modrinth.com/data/' . $p;

/** Constrói o cenário: pack, servidor com ficheiros antigos, loader/backup/estado simulados. */
$scenario = function (array $opts = []) use ($cdn) {
    $fs = new FakeServerFiles();
    $fs->seed('world/level.dat', 'old world');
    $fs->seed('old-notes.txt', 'old');
    $fs->mkdir('backups');
    $fs->seed('backups/b1.tar.gz', 'b');

    $fileDefs = $opts['files'] ?? [['mods/a.jar', 100], ['mods/b.jar', 200], ['config/x.toml', 10]];
    $files = [];
    foreach ($fileDefs as [$path, $size]) {
        $files[] = ['path' => $path, 'hashes' => ['sha1' => str_repeat('a', 40)], 'env' => ['server' => 'required', 'client' => 'required'],
            'downloads' => [$opts['host'] ?? $cdn('f/' . $path)], 'fileSize' => $size];
        $fs->addRemote($opts['host'] ?? $cdn('f/' . $path), $size, null, $opts['badSize'][$path] ?? 0, $opts['onPull'][$path] ?? null, $opts['failTimes'][$path] ?? 0);
    }
    $index = ['formatVersion' => 1, 'game' => 'minecraft', 'name' => 'TestPack', 'versionId' => '9.9', 'files' => $files,
        'dependencies' => ['minecraft' => '1.21.1', 'fabric-loader' => '0.18.4']];
    $archive = ['modrinth.index.json' => json_encode($index)] + ($opts['archive'] ?? [
        'overrides/config/y.toml' => 'from-overrides',
        'overrides/mods/z.jar' => 'zzz',
        'overrides/kubejs/s.js' => 'js',
        'overrides/server.properties' => "server-port=30000\nquery.port=30000\nenable-query=false\nserver-ip=1.2.3.4\nmotd=Pack MOTD\n",
        'server-overrides/config/y.toml' => 'from-server-overrides',
    ]);
    $fs->addRemote(MRPACK_URL, 5000, $archive);

    $cache = new Repository(new ArrayStore());
    $state = new ModpackState($cache);
    $state->begin(1, 'TestPack');
    $loader = new FakeLoader($fs, PORT);
    $backup = new FakeBackup();
    $backup->fails = $opts['backupFails'] ?? false;
    $loader->fails = $opts['loaderFails'] ?? null;
    $resolver = $opts['resolver'] ?? fn (string $u) => $u;
    $inst = new ModpackInstaller($fs, $loader, $backup, $state, $resolver, ['id' => 1, 'diskMb' => $opts['diskMb'] ?? 0, 'port' => PORT], $opts['tuning'] ?? []);
    $pack = MrpackIndex::parse($index);

    return compact('fs', 'state', 'loader', 'backup', 'inst', 'pack', 'cache');
};
$run = function (array $s, int $mrpackSize = 5000) {
    try {
        $s['inst']->install($s['pack'], 4242, $mrpackSize, MRPACK_URL);
    } catch (ModpackException $e) {
        return $e;
    }

    return null;
};

// ===== 1. caminho feliz
$s = $scenario();
$e = $run($s);
$t('feliz: sem erro', $e === null);
$files = $s['fs']->files();
$t('feliz: mods do índice presentes', in_array('mods/a.jar', $files) && in_array('mods/b.jar', $files) && in_array('config/x.toml', $files));
$t('feliz: override mods/z.jar mesclado com a pasta mods', in_array('mods/z.jar', $files));
$t('feliz: kubejs vindo dos overrides', in_array('kubejs/s.js', $files));
$t('feliz: config/y.toml é o do server-overrides (ganha)', ($s['fs']->contents['config/y.toml'] ?? '') === 'from-server-overrides');
$t('feliz: config do loader preservada (mescla dir com dir)', in_array('config/loader.toml', $files));
$t('feliz: ficheiros antigos apagados', !in_array('old-notes.txt', $files) && !in_array('world/level.dat', $files));
$t('feliz: backups intactos', in_array('backups/b1.tar.gz', $files));
$t('feliz: pasta temporária removida', !$s['fs']->exists('.modpack-tmp'));
$t('feliz: pack.zip apagado depois de extraído', !in_array('.modpack-tmp/pack.zip', $files));
$t('feliz: eula.txt', ($s['fs']->contents['eula.txt'] ?? '') === "eula=true\n");
$manifest = json_decode($s['fs']->contents['.modpack/manifest.json'] ?? 'null', true);
$t('feliz: manifest com pack, versão e 3 ficheiros', is_array($manifest) && $manifest['pack'] === 'TestPack' && $manifest['versionId'] === '9.9' && $manifest['minecraft'] === '1.21.1' && $manifest['loader'] === 'fabric' && count($manifest['files']) === 3);
$st = $s['state']->get(1);
$t('feliz: estado done 100%', $st['stage'] === 'done' && $st['percent'] === 100 && $st['error'] === null);
$t('feliz: backup uuid no estado e wiped', $st['backup_uuid'] === 'bk-uuid-1' && $st['wiped'] === true);
$t('feliz: backup chamado 1 vez, loader com tipo/mc/build certos', $s['backup']->calls === 1 && $s['loader']->calls === [['FABRIC', '1.21.1', 4242]]);
$order = array_map(fn ($l) => $l[0] === 'pull' ? ($l[1] === MRPACK_URL ? 'pack' : 'mod') : $l[0], $s['fs']->log);
$t('feliz: ordem pack antes de mods', array_search('pack', $order) < array_search('mod', $order));

// ===== 4. server.properties do pack: porta/query/ip forçados à alocação, o resto preservado
$props = $s['fs']->contents['server.properties'] ?? '';
$t('props: server-port forçado', str_contains($props, 'server-port=' . PORT) && !str_contains($props, '30000'));
$t('props: query.port, enable-query e server-ip forçados', str_contains($props, 'query.port=' . PORT) && str_contains($props, 'enable-query=true') && str_contains($props, 'server-ip=0.0.0.0') && !str_contains($props, '1.2.3.4'));
$t('props: motd do pack preservado', str_contains($props, 'motd=Pack MOTD'));

// pack sem overrides nenhuns e server.properties do loader normalizado
$s = $scenario(['archive' => []]);
$e = $run($s);
$t('sem overrides: sucesso', $e === null && in_array('mods/a.jar', $s['fs']->files()));
$t('sem overrides: server.properties do loader intacto', str_contains($s['fs']->contents['server.properties'], 'server-port=' . PORT));

// ===== 2. disco insuficiente: recusa ANTES do backup
$s = $scenario(['diskMb' => 1]); // 1 MiB; pack declara 310 B + 1.5*5000 = ok -> aumentar mrpackSize
$e = $run($s, 50 * 1024 * 1024);
$t('disco: lança ModpackException', $e instanceof ModpackException && str_contains($e->getMessage(), 'disk'));
$t('disco: backup e loader nunca chamados', $s['backup']->calls === 0 && $s['loader']->calls === []);
$t('disco: servidor intacto', in_array('world/level.dat', $s['fs']->files()) && in_array('old-notes.txt', $s['fs']->files()));
$t('disco: estado failed, wiped=false', $s['state']->get(1)['stage'] === 'failed' && $s['state']->get(1)['wiped'] === false);
$s = $scenario(['diskMb' => 0]);
$t('disco ilimitado (0) passa', $run($s, 50 * 1024 * 1024) === null);
$s = $scenario(['diskMb' => 10]);
$t('disco suficiente passa', $run($s, 1000) === null);

// ===== 7. backup falha: nada é tocado
$s = $scenario(['backupFails' => true]);
$e = $run($s);
$t('backup falha: lança e não chama o loader', $e instanceof ModpackException && $s['loader']->calls === []);
$t('backup falha: servidor intacto e wiped=false', in_array('world/level.dat', $s['fs']->files()) && $s['state']->get(1)['wiped'] === false && $s['state']->get(1)['stage'] === 'failed');

// ===== 6. loader falha depois do backup
$s = $scenario(['loaderFails' => new ModpackException('Loader install failed')]);
$e = $run($s);
$st = $s['state']->get(1);
$t('loader falha: estado failed com backup_uuid e wiped=true', $e instanceof ModpackException && $st['stage'] === 'failed' && $st['backup_uuid'] === 'bk-uuid-1' && $st['wiped'] === true);
$t('loader falha: não descarregou nada', $s['fs']->pulls === 0);

// ===== 5. cancelamento depois do wipe (no 3º ficheiro)
$cancelHook = null;
$s = $scenario(['tuning' => ['batch' => 3], 'files' => array_map(fn ($i) => ["mods/m$i.jar", 10], range(1, 25))]);
$s['fs']->remote[$cdn('f/mods/m3.jar')]['onPull'] = fn () => $s['state']->requestCancel(1);
$e = $run($s);
$st = $s['state']->get(1);
$t('cancelar: estado cancelled com backup_uuid e wiped', $st['stage'] === 'cancelled' && $st['backup_uuid'] === 'bk-uuid-1' && $st['wiped'] === true);
$t('cancelar: parou cedo (menos de 10 pulls de mods)', $s['fs']->pulls < 1 + 10);
$t('cancelar: pasta temporária removida', !$s['fs']->exists('.modpack-tmp'));
$t('cancelar depois do wipe: mensagem manda restaurar o backup', str_contains($st['error'] ?? '', 'restore the backup'));
$t('cancelar: lock libertado', $s['state']->begin(1, 'again') === true);
// cancelar enquanto o loader instala: nada é descarregado
$s = $scenario();
$s['loader']->onInstall = fn () => $s['state']->requestCancel(1);
$e = $run($s);
$t('cancelar durante o loader: sem downloads, cancelled, wiped', $s['fs']->pulls === 0 && $s['state']->get(1)['stage'] === 'cancelled' && $s['state']->get(1)['wiped'] === true);
// cancelar antes de tocar em nada
$s = $scenario();
$s['state']->requestCancel(1);
$e = $run($s);
$t('cancelar cedo: mensagem diz que nada mudou', str_contains($s['state']->get(1)['error'] ?? '', 'before any change'));
$t('cancelar cedo: nada tocado, wiped=false, sem backup', $s['state']->get(1)['stage'] === 'cancelled' && $s['state']->get(1)['wiped'] === false && $s['backup']->calls === 0 && in_array('world/level.dat', $s['fs']->files()));

// ===== 7b. tamanho diferente do declarado
$s = $scenario(['badSize' => ['mods/a.jar' => 1]]);
$e = $run($s);
$t('tamanho errado na 1ª vez: volta a descarregar e passa', $e === null && $s['fs']->tree['mods/a.jar']['size'] === 100);
$s = $scenario(['badSize' => ['mods/a.jar' => 5]]);
$e = $run($s);
$t('tamanho sempre errado: falha a nomear o ficheiro', $e instanceof ModpackException && str_contains($e->getMessage(), 'mods/a.jar'));
$t('tamanho sempre errado: estado failed com backup e wiped', $s['state']->get(1)['stage'] === 'failed' && $s['state']->get(1)['backup_uuid'] === 'bk-uuid-1');
$t('tamanho sempre errado: pasta temporária removida', !$s['fs']->exists('.modpack-tmp'));

// download que falha uma vez é repetido
$s = $scenario(['failTimes' => ['mods/b.jar' => 1]]);
$t('erro de rede numa vez: repete e passa', $run($s) === null);
$s = $scenario(['failTimes' => ['mods/b.jar' => 5]]);
$e = $run($s);
$t('erro de rede persistente: falha a nomear o ficheiro', $e instanceof ModpackException && str_contains($e->getMessage(), 'mods/b.jar'));

// ===== 5b. redirects: só se chama para github/gitlab e o destino final é revalidado
$resolved = [];
$s = $scenario([
    'files' => [['mods/g.jar', 50]],
    'host' => 'https://github.com/o/r/releases/download/v1/g.jar',
    'resolver' => function (string $u) use (&$resolved) {
        $resolved[] = $u;

        return 'https://evil.example/stolen.jar';
    },
]);
$s['fs']->addRemote('https://evil.example/stolen.jar', 50);
$e = $run($s);
$t('redirect para fora da lista: aborta a falar de redirect e do ficheiro', $e instanceof ModpackException && str_contains($e->getMessage(), 'mods/g.jar') && str_contains($e->getMessage(), 'redirects'));
$t('redirect para fora da lista: nunca descarrega do destino malicioso', array_filter($s['fs']->log, fn ($l) => $l[0] === 'pull' && str_contains($l[1], 'evil.example')) === []);
$t('redirect: resolver foi chamado para o github', count($resolved) === 1);
$resolved = [];
$s = $scenario(['resolver' => function (string $u) use (&$resolved) { $resolved[] = $u; return $u; }]);
$run($s);
$t('cdn.modrinth.com não passa pelo resolver', $resolved === []);
$s = $scenario([
    'files' => [['mods/g.jar', 50]],
    'host' => 'https://github.com/o/r/releases/download/v1/g.jar',
    'resolver' => fn (string $u) => 'https://release-assets.githubusercontent.com/github-production-release-asset/9/g.jar',
]);
$s['fs']->addRemote('https://release-assets.githubusercontent.com/github-production-release-asset/9/g.jar', 50);
$t('redirect github.com -> githubusercontent (destino real das releases) é aceite', $run($s) === null && in_array('mods/g.jar', $s['fs']->files()));
$s = $scenario([
    'files' => [['mods/g.jar', 50]],
    'host' => 'https://github.com/o/r/releases/download/v1/g.jar',
    'resolver' => fn (string $u) => 'https://raw.githubusercontent.com/o/r/main/g.jar',
]);
$s['fs']->addRemote('https://raw.githubusercontent.com/o/r/main/g.jar', 50);
$t('redirect para host permitido: descarrega do destino final', $run($s) === null && in_array('mods/g.jar', $s['fs']->files()));

// ===== 3. pack declara ficheiro em subpasta profunda: mkdir antes do pull
$s = $scenario(['files' => [['config/deep/er/x.json', 5], ['mods/a.jar', 5]]]);
$t('subpastas novas são criadas antes do download', $run($s) === null && in_array('config/deep/er/x.json', $s['fs']->files()));

// ===== o Wings ignora em silêncio um rename que falha: o orquestrador tem de dar por isso
$s = $scenario();
$s['fs']->dropMoves = ['kubejs'];
$e = $run($s);
$t('rename silencioso é detetado e falha a nomear o destino', $e instanceof ModpackException && str_contains($e->getMessage(), 'kubejs'));
$t('rename silencioso: estado failed, wiped e pasta temporária removida', $s['state']->get(1)['stage'] === 'failed' && $s['state']->get(1)['wiped'] === true && !$s['fs']->exists('.modpack-tmp'));

// ===== o verificador de merge apanha rename silencioso do Wings (destino não existe)
$s = $scenario(['archive' => ['overrides/a/b/c.txt' => 'deep']]);
$t('overrides em pastas aninhadas novas chegam à raiz', $run($s) === null && ($s['fs']->contents['a/b/c.txt'] ?? '') === 'deep');

// ===== pack sem arquivo (Feed The Beast): a lista de ficheiros já traz tudo, não há .mrpack nem overrides
$s = $scenario();
try {
    $s['inst']->install($s['pack'], 4242, 0, null);
    $e = null;
} catch (ModpackException $e) {
}
$kinds = array_map(fn ($l) => $l[0], $s['fs']->log);
$t('sem arquivo: instala sem erro e os ficheiros do pack chegam', $e === null && in_array('mods/a.jar', $s['fs']->files()) && in_array('mods/b.jar', $s['fs']->files()) && in_array('config/x.toml', $s['fs']->files()));
$t('sem arquivo: não descarrega nem extrai nenhum arquivo e não cria a pasta temporária', !in_array('decompress', $kinds, true) && array_filter($s['fs']->log, fn ($l) => $l[0] === 'pull' && $l[1] === MRPACK_URL) === [] && array_filter($s['fs']->log, fn ($l) => $l[0] === 'mkdir' && $l[1] === '.modpack-tmp') === []);
$t('sem arquivo: o estado termina done e o loader/backup correram', ($s['state']->get(1)['stage'] ?? '') === 'done' && $s['backup']->calls === 1 && count($s['loader']->calls) === 1);
$t('sem arquivo: server.properties continua com a porta da alocação e o manifest existe', str_contains($s['fs']->contents['server.properties'] ?? '', 'server-port=' . PORT) && isset($s['fs']->contents['.modpack/manifest.json']));
$t('sem arquivo: o disco continua a ser verificado (pack maior que o disco)', (function () use ($scenario) {
    $x = $scenario(['diskMb' => 1, 'files' => [['mods/big.jar', 5 * 1024 * 1024]]]);
    try { $x['inst']->install($x['pack'], 4242, 0, null); } catch (ModpackException $e) { return str_contains($e->getMessage(), 'disk') && $x['backup']->calls === 0; }

    return false;
})());

// ===== CDN do CurseForge: edge.forgecdn.net -> mediafilez.forgecdn.net (o Wings não segue o 302)
$t('normalizeUrl troca o edge pelo mediafilez e mantém o resto', ModpackInstaller::normalizeUrl('https://edge.forgecdn.net/files/4684/137/A-1.0.jar') === 'https://mediafilez.forgecdn.net/files/4684/137/A-1.0.jar');
$t('normalizeUrl não mexe noutros hosts nem em http', ModpackInstaller::normalizeUrl('https://cdn.modrinth.com/a.jar') === 'https://cdn.modrinth.com/a.jar' && ModpackInstaller::normalizeUrl('http://edge.forgecdn.net/a') === 'http://edge.forgecdn.net/a' && ModpackInstaller::normalizeUrl('https://evil.edge.forgecdn.net/a') === 'https://evil.edge.forgecdn.net/a' && ModpackInstaller::normalizeUrl('https://edge.forgecdn.net.evil.com/a') === 'https://edge.forgecdn.net.evil.com/a');
$s = $scenario(['files' => [['mods/cf.jar', 70]]]);
// pack montado à mão: o parser do Modrinth (e bem) recusa edge.forgecdn.net; o do FTB tem os seus próprios domínios
$s['pack'] = new \Pterodactyl\Services\Lagplace\Modpacks\ParsedPack('FtbLike', '1', '1.21.1', 'fabric', '0.18.4', [new \Pterodactyl\Services\Lagplace\Modpacks\PackFile('mods/cf.jar', ['https://edge.forgecdn.net/files/1/2/cf.jar'], 70, null, null)], 70);
$s['fs']->addRemote('https://mediafilez.forgecdn.net/files/1/2/cf.jar', 70);
$t('mod no edge.forgecdn.net é descarregado do mediafilez e o edge nunca é pedido', $run($s) === null && in_array('mods/cf.jar', $s['fs']->files()) && array_filter($s['fs']->log, fn ($l) => $l[0] === 'pull' && str_contains($l[1], 'edge.forgecdn.net')) === []);

// ===== descarregamentos em lotes com concorrência
$s = $scenario(['files' => array_map(fn ($i) => ["mods/m$i.jar", 10], range(1, 70))]);
$t('lotes: 70 ficheiros passam sem erro', $run($s) === null && count(array_filter($s['fs']->files(), fn ($p) => str_starts_with($p, 'mods/m'))) === 70);
$mod = array_values(array_filter($s['fs']->batches, fn ($b) => true));
$t('lotes: tamanhos 30, 30, 10 (lote por omissão = 30)', array_column($mod, 'size') === [30, 30, 10]);
$t('lotes: concorrência por omissão = 3 (limite do Wings por servidor)', array_unique(array_column($mod, 'concurrency')) === [3]);
$s = $scenario(['tuning' => ['concurrency' => 5, 'batch' => 4], 'files' => array_map(fn ($i) => ["mods/m$i.jar", 10], range(1, 9))]);
$run($s);
$t('lotes: concorrência e tamanho configuráveis', array_column($s['fs']->batches, 'size') === [4, 4, 1] && array_unique(array_column($s['fs']->batches, 'concurrency')) === [5]);

// só os que falham são repetidos (uma vez), nunca o lote inteiro
$s = $scenario(['failTimes' => ['mods/b.jar' => 1]]);
$pullsBefore = 0;
$run($s);
$sizes = array_column($s['fs']->batches, 'size');
$t('repetição: lote de 3 e depois só 1 ficheiro (o que falhou)', $sizes === [3, 1]);
$t('repetição: o ficheiro que falhou acabou por chegar', in_array('mods/b.jar', $s['fs']->files()));
$s = $scenario(['failTimes' => ['mods/a.jar' => 9, 'mods/b.jar' => 9]]);
$e = $run($s);
$t('falha persistente em 2 ficheiros: para no descarregamento, nomeia o primeiro e diz quantos mais', $e instanceof ModpackException && str_starts_with($e->getMessage(), 'Could not download mods/a.jar') && str_contains($e->getMessage(), 'and 1 more'));
$t('falha persistente: nem chega à fase de verificação', ($s['state']->get(1)['stage'] ?? '') === 'failed' && count($s['fs']->batches) === 2);

// o cancelamento só é visto entre lotes (progresso e cancelamento a cada lote)
$s = $scenario(['files' => array_map(fn ($i) => ["mods/m$i.jar", 10], range(1, 70))]);
$s['fs']->remote[$cdn('f/mods/m2.jar')]['onPull'] = fn () => $s['state']->requestCancel(1);
$run($s);
$t('cancelar a meio do 1º lote: o lote acaba mas o 2º nunca começa', $s['state']->get(1)['stage'] === 'cancelled' && count($s['fs']->batches) === 1);

// a verificação re-descarrega por lotes
$s = $scenario(['badSize' => ['mods/a.jar' => 1, 'mods/b.jar' => 1]]);
$t('verificação: dois ficheiros com tamanho errado são re-descarregados em lote e acabam certos', $run($s) === null && $s['fs']->tree['mods/a.jar']['size'] === 100 && $s['fs']->tree['mods/b.jar']['size'] === 200);
$t('verificação: o re-download foi um lote de 2', end($s['fs']->batches)['size'] === 2);

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
