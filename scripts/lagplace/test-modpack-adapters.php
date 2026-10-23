<?php
// Teste do adaptador DaemonServerFiles (repositório do Wings simulado) e da flag ModpackFeature. Correr: php scripts/lagplace/test-modpack-adapters.php
require (getenv('PANEL_DIR') ?: '/var/www/pterodactyl') . '/vendor/autoload.php';
$dir = __DIR__ . '/../../app/Services/Lagplace/Modpacks/';
// O autoload (vendor da produção) carregaria o DaemonFileRepository da PRODUÇÃO; queremos testar o do worktree.
require_once __DIR__ . '/../../app/Repositories/Wings/DaemonFileRepository.php';
foreach (['ModpackException', 'ServerFiles', 'DaemonServerFiles', 'ModpackFeature'] as $c) {
    require_once $dir . $c . '.php';
}

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\Lagplace\Modpacks\DaemonServerFiles;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackFeature;

$n = 0;
$f = 0;
$t = function (string $label, bool $ok) use (&$n, &$f) {
    $n++;
    if (!$ok) {
        $f++;
        echo "FALHOU: $label\n";
    }
};

$repo = new class() extends DaemonFileRepository {
    public array $calls = [];
    public array $listing = [];
    public string $content = '';

    public function __construct()
    {
    }

    public function setServer(Server $server): static
    {
        $this->calls[] = ['setServer'];

        return $this;
    }

    public function pull(string $url, ?string $directory, array $params = []): \Psr\Http\Message\ResponseInterface
    {
        $this->calls[] = ['pull', $url, $directory, $params];

        return new \GuzzleHttp\Psr7\Response(200);
    }

    public function decompressFile(?string $root, string $file): \Psr\Http\Message\ResponseInterface
    {
        $this->calls[] = ['decompress', $root, $file];

        return new \GuzzleHttp\Psr7\Response(204);
    }

    public function renameFiles(?string $root, array $files): \Psr\Http\Message\ResponseInterface
    {
        $this->calls[] = ['rename', $root, $files];

        return new \GuzzleHttp\Psr7\Response(204);
    }

    public function deleteFiles(?string $root, array $files): \Psr\Http\Message\ResponseInterface
    {
        $this->calls[] = ['delete', $root, $files];

        return new \GuzzleHttp\Psr7\Response(204);
    }

    public function createDirectory(string $name, string $path): \Psr\Http\Message\ResponseInterface
    {
        $this->calls[] = ['mkdir', $name, $path];

        return new \GuzzleHttp\Psr7\Response(204);
    }

    public bool $throwOnList = false;

    public function pullMany(array $items, int $concurrency = 3, int $timeout = 1800): array
    {
        $this->calls[] = ['pullMany', $items, $concurrency, $timeout];

        return [];
    }

    public function getDirectory(string $path): array
    {
        $this->calls[] = ['list', $path];
        if ($this->throwOnList) {
            throw new \RuntimeException('connection refused');
        }

        return $this->listing;
    }

    public function getContent(string $path, ?int $notLargerThan = null): string
    {
        $this->calls[] = ['read', $path, $notLargerThan];

        return $this->content;
    }

    public function putContent(string $path, string $content): \Psr\Http\Message\ResponseInterface
    {
        $this->calls[] = ['write', $path, $content];

        return new \GuzzleHttp\Psr7\Response(204);
    }
};
$server = (new ReflectionClass(Server::class))->newInstanceWithoutConstructor();
$fs = new DaemonServerFiles($repo, $server);
$last = fn () => end($repo->calls);

$fs->pull('https://cdn.modrinth.com/a.jar', 'mods', 'a.jar');
$t('pull: diretório absoluto, nome, foreground=true e timeout de 30 min', $last() === ['pull', 'https://cdn.modrinth.com/a.jar', '/mods', ['filename' => 'a.jar', 'foreground' => true, 'timeout' => 1800]]);
$fs->pull('https://cdn.modrinth.com/p.mrpack', '', 'p.mrpack');
$t('pull na raiz usa "/"', $last()[2] === '/');
$fs->decompress('.modpack-tmp', 'pack.mrpack');
$t('decompress: raiz absoluta + ficheiro', $last() === ['decompress', '/.modpack-tmp', 'pack.mrpack']);
$fs->move('.modpack-tmp/overrides/config', 'config');
$t('move: rename a partir da raiz "/" com caminhos relativos', $last() === ['rename', '/', [['from' => '.modpack-tmp/overrides/config', 'to' => 'config']]]);
$fs->delete(['.modpack-tmp', 'old.txt']);
$t('delete: raiz "/" e lista reindexada', $last() === ['delete', '/', ['.modpack-tmp', 'old.txt']]);
$fs->mkdir('config/deep/er');
$t('mkdir: nome completo relativo à raiz', $last() === ['mkdir', 'config/deep/er', '/']);
$fs->write('server.properties', "a=b\n");
$t('write: caminho absoluto', $last() === ['write', '/server.properties', "a=b\n"]);
$repo->content = 'hello';
$t('read devolve o conteúdo e passa o limite', $fs->read('server.properties', 1000) === 'hello' && $last() === ['read', '/server.properties', 1000]);

$repo->listing = [
    ['name' => 'mods', 'size' => 4096, 'directory' => true, 'file' => false],
    ['name' => 'a.jar', 'size' => 123, 'directory' => false, 'file' => true],
    ['name' => 'link', 'size' => 5, 'directory' => false, 'file' => false, 'symlink' => true],
];
$list = $fs->list('');
$t('list raiz: chama "/" e normaliza', $repo->calls[count($repo->calls) - 1] === ['list', '/'] && $list['mods'] === ['size' => 4096, 'is_dir' => true] && $list['a.jar'] === ['size' => 123, 'is_dir' => false]);
$fs->list('config/x');
$t('list subpasta: caminho absoluto', $last() === ['list', '/config/x']);
$repo->listing = [];
$t('list vazio devolve array vazio', $fs->list('vazia') === []);
$repo->listing = [['name' => 'x', 'size' => '77', 'directory' => 0]];
$t('size numérico em string vira int', $fs->list('')['x'] === ['size' => 77, 'is_dir' => false]);
$repo->listing = [['size' => 1]];
$t('entrada sem nome é ignorada', $fs->list('') === []);

// --- DaemonFileRepository::pull real: o timeout por chamada chega mesmo ao pedido HTTP (e por omissão não é imposto)
$history = [];
$stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(204), new \GuzzleHttp\Psr7\Response(204)]));
$stack->push(\GuzzleHttp\Middleware::history($history));
$real = new class($stack) extends DaemonFileRepository {
    public function __construct(private \GuzzleHttp\HandlerStack $stack)
    {
    }

    public function setServer(Server $server): static
    {
        $this->server = $server;

        return $this;
    }

    public function getHttpClient(array $headers = []): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client(['handler' => $this->stack, 'base_uri' => 'http://wings.test', 'timeout' => 15]);
    }
};
$server->uuid = 'abc-123';
$real->setServer($server)->pull('https://cdn.modrinth.com/a.jar', '/mods', ['filename' => 'a.jar', 'foreground' => true, 'timeout' => 1800]);
$real->setServer($server)->pull('https://cdn.modrinth.com/b.jar', '/mods', ['filename' => 'b.jar']);
$body1 = json_decode((string) $history[0]['request']->getBody(), true);
$t('pull real: corpo com url, root, file_name e foreground', $body1 === ['url' => 'https://cdn.modrinth.com/a.jar', 'root' => '/mods', 'file_name' => 'a.jar', 'foreground' => true]);
$t('pull real: o timeout pedido (1800) chega ao cliente HTTP', ($history[0]['options']['timeout'] ?? null) === 1800);
$t('pull real: sem timeout pedido, mantém o do cliente (15)', ($history[1]['options']['timeout'] ?? null) === 15);
$t('pull real: o timeout não vai no corpo enviado ao Wings', !array_key_exists('timeout', $body1));
$t('pull real: endpoint do servidor certo', $history[0]['request']->getUri()->getPath() === '/api/servers/abc-123/files/pull');

// --- connect(): endereço da LAN com fallback
$nodeWith = fn (?string $lan) => (function () use ($lan) {
    $n = (new ReflectionClass(\Pterodactyl\Models\Node::class))->newInstanceWithoutConstructor();
    $n->setAttribute('lagplace_lan_url', $lan);

    return $n;
})();
$srvWith = function (?string $lan) use ($nodeWith) {
    $s = (new ReflectionClass(Server::class))->newInstanceWithoutConstructor();
    $s->setRelation('node', $nodeWith($lan));

    return $s;
};
$logs = [];
$log = function (string $m) use (&$logs) { $logs[] = $m; };

$repo->calls = [];
DaemonServerFiles::connect($repo, $srvWith('http://10.0.0.10:443'), $log);
$t('connect: com LAN que responde, usa o endereço da LAN', $repo->baseUri() === 'http://10.0.0.10:443' && $logs === []);
$t('connect: faz uma leitura de teste ao Wings (listar a raiz)', in_array(['list', '/'], $repo->calls, true));

$repo->useBaseUri(null);
$repo->calls = [];
DaemonServerFiles::connect($repo, $srvWith(null), $log);
$t('connect: sem endereço da LAN, não mexe e não testa', $repo->baseUri() === null && $repo->calls === []);
DaemonServerFiles::connect($repo, $srvWith(''), $log);
$t('connect: endereço da LAN vazio conta como ausente', $repo->baseUri() === null);

$repo->throwOnList = true;
$logs = [];
DaemonServerFiles::connect($repo, $srvWith('http://10.0.0.99:443'), $log);
$t('connect: LAN que não responde volta ao endereço público e avisa', $repo->baseUri() === null && count($logs) === 1 && str_contains($logs[0], 'public address'));
$repo->throwOnList = false;
$t('connect: sem callback de log não rebenta quando cai no público', (function () use ($repo, $srvWith) { $repo->throwOnList = true; try { DaemonServerFiles::connect($repo, $srvWith('http://x:443')); return $repo->baseUri() === null; } finally { $repo->throwOnList = false; } })());

// --- pullMany: o adaptador passa os itens e a concorrência; o repositório real faz os pedidos e devolve os índices que falharam
$repo->calls = [];
$fs->pullMany([['url' => 'https://cdn.modrinth.com/a.jar', 'dir' => 'mods', 'filename' => 'a.jar']], 3);
$t('pullMany (adaptador): chama o repositório com os itens, concorrência e timeout de 30 min', end($repo->calls) === ['pullMany', [['url' => 'https://cdn.modrinth.com/a.jar', 'dir' => 'mods', 'filename' => 'a.jar']], 3, 1800]);

$history2 = [];
$mock = new \GuzzleHttp\Handler\MockHandler([
    new \GuzzleHttp\Psr7\Response(204),
    new \GuzzleHttp\Exception\RequestException('boom', new \GuzzleHttp\Psr7\Request('POST', '/x')),
    new \GuzzleHttp\Psr7\Response(400, [], '{"error":"limit of 3"}'),
    new \GuzzleHttp\Psr7\Response(204),
]);
$stack2 = \GuzzleHttp\HandlerStack::create($mock);
$stack2->push(\GuzzleHttp\Middleware::history($history2));
$real2 = new class($stack2) extends DaemonFileRepository {
    public function __construct(private \GuzzleHttp\HandlerStack $stack)
    {
    }

    public function setServer(Server $server): static
    {
        $this->server = $server;

        return $this;
    }

    public function getHttpClient(array $headers = []): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client(['handler' => $this->stack, 'base_uri' => 'http://wings.test', 'timeout' => 15]);
    }
};
$items = [
    ['url' => 'https://cdn.modrinth.com/a.jar', 'dir' => 'mods', 'filename' => 'a.jar'],
    ['url' => 'https://cdn.modrinth.com/b.jar', 'dir' => '', 'filename' => 'b.jar'],
    ['url' => 'https://cdn.modrinth.com/c.jar', 'dir' => 'config/x', 'filename' => 'c.toml'],
    ['url' => 'https://cdn.modrinth.com/d.jar', 'dir' => 'mods', 'filename' => 'd.jar'],
];
$failed = $real2->setServer($server)->pullMany($items, 3, 900);
$t('pullMany real: devolve os índices que falharam (erro de rede e HTTP 400) e só esses', $failed === [1, 2]);
$bodies = array_map(fn ($h) => json_decode((string) $h['request']->getBody(), true), $history2);
usort($bodies, fn ($a, $b) => strcmp($a['url'], $b['url']));
$t('pullMany real: 4 pedidos, foreground=true e raiz absoluta', count($bodies) === 4 && $bodies[0] === ['url' => 'https://cdn.modrinth.com/a.jar', 'root' => '/mods', 'file_name' => 'a.jar', 'foreground' => true] && $bodies[1]['root'] === '/' && $bodies[2]['root'] === '/config/x');
$t('pullMany real: o timeout pedido chega a cada pedido', ($history2[0]['options']['timeout'] ?? null) === 900);
$t('pullMany real: endpoint do servidor certo', $history2[0]['request']->getUri()->getPath() === '/api/servers/abc-123/files/pull');
$t('pullMany real: lista vazia não faz pedidos', (function () use ($real2, $server, &$history2) { $before = count($history2); $r = $real2->setServer($server)->pullMany([], 3, 900); return $r === [] && count($history2) === $before; })());

// --- ModpackFeature
$data = [];
$feature = new ModpackFeature(function (string $k, mixed $d) use (&$data) { return $data[$k] ?? $d; }, function (string $k, string $v) use (&$data) { $data[$k] = $v; });
$t('flag desligada por omissão', $feature->enabled() === false);
$feature->set(true);
$t('set(true) liga', $feature->enabled() === true && $data['lagplace::modpacks_enabled'] === '1');
$feature->set(false);
$t('set(false) desliga', $feature->enabled() === false && $data['lagplace::modpacks_enabled'] === '0');
foreach (['1', 'true', 1, true] as $on) {
    $data['lagplace::modpacks_enabled'] = $on;
    $t('valor ' . json_encode($on) . ' conta como ligado', $feature->enabled() === true);
}
foreach (['0', 'false', '', 'abc', null] as $off) {
    $data['lagplace::modpacks_enabled'] = $off;
    $t('valor ' . json_encode($off) . ' conta como desligado', $feature->enabled() === false);
}

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
