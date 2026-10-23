<?php
// Testes do fornecedor Feed The Beast: parser da versão (FtbPack), cliente da API (FtbClient, HTTP simulado) e plano (FtbSource).
// Fixtures reduzidos a partir de respostas reais da api.modpacks.ch (StoneBlock 3). Correr: php scripts/lagplace/test-ftb.php
require (getenv('PANEL_DIR') ?: '/var/www/pterodactyl') . '/vendor/autoload.php';
$dir = __DIR__ . '/../../app/Services/Lagplace/Modpacks/';
foreach (['ModpackException', 'PackPath', 'PackFile', 'ParsedPack', 'MrpackIndex', 'LoaderMap', 'ModrinthClient', 'ModpackPlan', 'ModpackSource', 'FtbPack', 'FtbClient', 'FtbSource'] as $c) {
    require_once $dir . $c . '.php';
}

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Pterodactyl\Services\Lagplace\Modpacks\FtbClient;
use Pterodactyl\Services\Lagplace\Modpacks\FtbPack;
use Pterodactyl\Services\Lagplace\Modpacks\FtbSource;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;

$n = 0;
$f = 0;
$t = function (string $label, bool $ok) use (&$n, &$f) {
    $n++;
    if (!$ok) {
        $f++;
        echo "FALHOU: $label\n";
    }
};
$msg = function (callable $fn): ?string {
    try {
        $fn();
    } catch (ModpackException $e) {
        return $e->getMessage();
    }

    return null;
};

$version = json_decode(file_get_contents(__DIR__ . '/fixtures/ftb-version-sample.json'), true);
$detail = json_decode(file_get_contents(__DIR__ . '/fixtures/ftb-pack-detail-sample.json'), true);
$with = function (array $over = [], ?callable $mutate = null) use ($version) {
    $v = array_merge($version, $over);
    if ($mutate) {
        $v = $mutate($v);
    }

    return $v;
};
$file = fn (string $name, array $over = []) => array_merge(['id' => 1, 'name' => $name, 'path' => './mods', 'type' => 'mod', 'url' => 'https://dist.modpacks.ch/modpacks/1/' . $name, 'size' => 100, 'sha1' => str_repeat('a', 40), 'clientonly' => false, 'serveronly' => false], $over);

// ================= FtbPack::parse
$p = FtbPack::parse($version, 'FTB StoneBlock 3', '1.11.1');
$t('parse: loader, versão do loader e Minecraft', $p->loader === 'forge' && $p->loaderVersion === '40.2.14' && $p->minecraft === '1.18.2');
$t('parse: nome do pack e da versão', $p->name === 'FTB StoneBlock 3' && $p->versionId === '1.11.1');
$clientOnly = count(array_filter($version['files'], fn ($x) => !empty($x['clientonly'])));
$t('parse: ficheiros só de cliente são ignorados', count($p->files) === count($version['files']) - $clientOnly && $clientOnly >= 1);
$t('parse: caminhos normalizados (sem "./") e com o nome do ficheiro', in_array('config/Advancedperipherals/general.toml', array_map(fn ($x) => $x->path, $p->files), true));
$t('parse: nenhum caminho começa por ./ ou /', array_filter($p->files, fn ($x) => str_starts_with($x->path, '.') && !str_starts_with($x->path, '.m') || str_starts_with($x->path, '/')) === []);
$t('parse: total de bytes é a soma dos ficheiros incluídos', $p->totalBytes === array_sum(array_map(fn ($x) => $x->size, $p->files)));
$t('parse: guarda o sha1 e o URL', $p->files[0]->sha1 !== null && str_starts_with($p->files[0]->urls[0], 'https://'));
$t('parse: mods do CurseForge (edge.forgecdn.net) aceites', count(array_filter($p->files, fn ($x) => str_contains($x->urls[0], 'edge.forgecdn.net'))) >= 3);

// loaders
foreach (['forge' => 'forge', 'neoforge' => 'neoforge', 'fabric' => 'fabric', 'quilt' => 'quilt', 'Forge' => 'forge'] as $name => $expect) {
    $v = $with([], function ($v) use ($name) { foreach ($v['targets'] as &$tg) { if ($tg['type'] === 'modloader') { $tg['name'] = $name; } } return $v; });
    $t("parse: loader $name -> $expect", FtbPack::parse($v, 'P', '1')->loader === $expect);
}
$t('parse: loader desconhecido lança', str_contains((string) $msg(fn () => FtbPack::parse($with([], function ($v) { foreach ($v['targets'] as &$tg) { if ($tg['type'] === 'modloader') { $tg['name'] = 'liteloader'; } } return $v; }), 'P', '1')), 'not supported'));
$t('parse: sem modloader lança', $msg(fn () => FtbPack::parse($with(['targets' => [['type' => 'game', 'name' => 'minecraft', 'version' => '1.18.2']]]), 'P', '1')) !== null);
$t('parse: sem Minecraft lança', $msg(fn () => FtbPack::parse($with(['targets' => [['type' => 'modloader', 'name' => 'forge', 'version' => '40.2.14']]]), 'P', '1')) !== null);
$t('parse: versão do loader com lixo lança', $msg(fn () => FtbPack::parse($with([], function ($v) { foreach ($v['targets'] as &$tg) { if ($tg['type'] === 'modloader') { $tg['version'] = '1; rm -rf /'; } } return $v; }), 'P', '1')) !== null);
$t('parse: status != success lança', $msg(fn () => FtbPack::parse($with(['status' => 'error']), 'P', '1')) !== null);
$t('parse: sem ficheiros lança', $msg(fn () => FtbPack::parse(['status' => 'success', 'targets' => $version['targets']], 'P', '1')) !== null);

// ficheiros
$one = fn (array $files) => $with(['files' => $files]);
$t('parse: tipo de ficheiro desconhecido lança', str_contains((string) $msg(fn () => FtbPack::parse($one([$file('a.jar', ['type' => 'cf-extract'])]), 'P', '1')), 'file type'));
foreach (['https://evil.example/a.jar', 'http://dist.modpacks.ch/a.jar', 'https://dist.modpacks.ch:8443/a.jar', 'https://user@dist.modpacks.ch/a.jar', 'https://dist.modpacks.ch.evil.com/a.jar', '', 'ftp://dist.modpacks.ch/a'] as $u) {
    $t("parse: URL recusado $u", $msg(fn () => FtbPack::parse($one([$file('a.jar', ['url' => $u])]), 'P', '1')) !== null);
}
foreach (['https://dist.modpacks.ch/x', 'https://edge.forgecdn.net/files/1/2/a.jar', 'https://mediafilez.forgecdn.net/files/1/2/a.jar'] as $u) {
    $t("parse: URL aceite $u", count(FtbPack::parse($one([$file('a.jar', ['url' => $u])]), 'P', '1')->files) === 1);
}
foreach ([['path' => './mods', 'name' => '../../x.jar'], ['path' => '../mods', 'name' => 'x.jar'], ['path' => '/etc', 'name' => 'passwd'], ['path' => './mods', 'name' => ''], ['path' => 'a\\b', 'name' => 'x.jar'], ['path' => './mods', 'name' => "x\0.jar"]] as $bad) {
    $t('parse: caminho perigoso recusado ' . json_encode($bad), $msg(fn () => FtbPack::parse($one([$file('a.jar', $bad)]), 'P', '1')) !== null);
}
$t('parse: path vazio e "." põem o ficheiro na raiz', array_map(fn ($x) => $x->path, FtbPack::parse($one([$file('a.cfg', ['path' => '']), $file('b.cfg', ['path' => '.']), $file('c.cfg', ['path' => './'])]), 'P', '1')->files) === ['a.cfg', 'b.cfg', 'c.cfg']);
$t('parse: caminhos duplicados lançam', $msg(fn () => FtbPack::parse($one([$file('a.jar'), $file('a.jar', ['path' => 'mods/'])]), 'P', '1')) !== null);
$t('parse: tamanho negativo ou não inteiro lança', $msg(fn () => FtbPack::parse($one([$file('a.jar', ['size' => -1])]), 'P', '1')) !== null && $msg(fn () => FtbPack::parse($one([$file('a.jar', ['size' => 'grande'])]), 'P', '1')) !== null);
$many = [];
for ($i = 0; $i < 3001; $i++) {
    $many[] = $file("m$i.jar");
}
$t('parse: 3001 ficheiros lança, 3000 passa', $msg(fn () => FtbPack::parse($one($many), 'P', '1')) !== null && count(FtbPack::parse($one(array_slice($many, 0, 3000)), 'P', '1')->files) === 3000);
$t('parse: total acima do teto lança', $msg(fn () => FtbPack::parse($one([$file('a.jar', ['size' => 4294967297])]), 'P', '1')) !== null);
$t('parse: limites configuráveis', $msg(fn () => FtbPack::parse($one([$file('a.jar'), $file('b.jar')]), 'P', '1', ['maxFiles' => 1])) !== null);

// ================= FtbClient
$make = function (array $fakes) {
    $http = new Factory();
    $http->fake($fakes);

    return [new FtbClient($http, new Repository(new ArrayStore())), $http];
};
$d = fn (int $id, array $over = []) => array_merge($detail, ['id' => $id, 'name' => "Pack $id"], $over);
$ids = fn (array $packs, array $extra = []) => Factory::response(array_merge(['packs' => $packs, 'total' => count($packs), 'status' => 'success'], $extra));
$urls = fn (Factory $http) => $http->recorded()->map(fn ($p) => $p[0]->url())->values()->all();

// pesquisa sem termo = populares; devolve ids -> detalhes em paralelo
[$c, $http] = $make([
    'api.modpacks.ch/public/modpack/popular/installs/*' => $ids([100, 35, 4]),
    'api.modpacks.ch/public/modpack/100' => Factory::response($d(100)),
    'api.modpacks.ch/public/modpack/35' => Factory::response($d(35)),
    'api.modpacks.ch/public/modpack/4' => Factory::response($d(4, ['private' => true])),
]);
$r = $c->search(null, 1, 20);
$t('search: total = nº de ids; packs privados ficam de fora', $r['total'] === 3 && count($r['hits']) === 2);
$h = $r['hits'][0];
$t('search: campos do hit', $h['id'] === '100' && $h['title'] === 'Pack 100' && $h['slug'] === 'pack-100' && $h['downloads'] === (int) $detail['installs'] && is_string($h['description']));
$t('search: url oficial do pack', $h['url'] === 'https://www.feed-the-beast.com/modpacks/100');
$t('search: ícone https vindo de "art" (square)', is_string($h['icon']) && str_starts_with($h['icon'], 'https://apps.modpacks.ch/'));
$t('search: loader e versão de Minecraft da versão mais recente', $h['loaders'] === ['forge'] && $h['versions'] === ['1.18.2']);
$u = $urls($http);
$t('search: sem termo usa popular/installs/100', in_array('https://api.modpacks.ch/public/modpack/popular/installs/100', $u, true));
$t('search: User-Agent identificável', str_contains($http->recorded()->first()[0]->header('User-Agent')[0] ?? '', 'LagPlace-Panel'));
$before = count($u);
$c->search(null, 1, 20);
$t('search: segunda chamada igual vem da cache (0 pedidos novos)', $http->recorded()->count() === $before);

// pesquisa com termo: só os ids do FTB (ignora os do CurseForge)
[$c, $http] = $make([
    'api.modpacks.ch/public/modpack/search/*' => $ids([19, 25], ['curseforge' => [296062, 392141]]),
    'api.modpacks.ch/public/modpack/19' => Factory::response($d(19)),
    'api.modpacks.ch/public/modpack/25' => Factory::response($d(25)),
]);
$r = $c->search('sky factory', 1, 20);
$t('search termo: ignora os ids do CurseForge', $r['total'] === 2 && count($r['hits']) === 2);
$u = $urls($http);
$t('search termo: usa search/100 com o termo codificado', count(array_filter($u, fn ($x) => str_contains($x, '/modpack/search/100') && str_contains($x, 'term=sky'))) === 1);
$t('search termo gigante é cortado sem erro', is_array($c->search(str_repeat('a', 500), 1, 20)));

// paginação: pede page*perPage ids e fatia
[$c, $http] = $make([
    'api.modpacks.ch/public/modpack/popular/installs/*' => $ids(range(101, 130)),
    'api.modpacks.ch/public/modpack/1*' => fn ($req) => Factory::response($d((int) basename($req->url()))),
]);
$r = $c->search(null, 2, 10);
$t('paginação: página 2 de 10 pede 20 ids e devolve o 11.º ao 20.º', $r['total'] === 30 && count($r['hits']) === 10 && $r['hits'][0]['id'] === '111' && $r['hits'][9]['id'] === '120');
$t('paginação: pede sempre a lista completa (100) para o total e as páginas', in_array('https://api.modpacks.ch/public/modpack/popular/installs/100', $urls($http), true));
$detailCalls = count(array_filter($urls($http), fn ($x) => preg_match('#/modpack/\d+$#', $x) === 1));
$t('paginação: só busca os detalhes dos 10 da página', $detailCalls === 10);
$t('paginação: página inválida lança', $msg(fn () => $c->search(null, 0)) !== null);

// detalhe que falha não derruba a lista
[$c] = $make([
    'api.modpacks.ch/public/modpack/popular/installs/*' => $ids([100, 35]),
    'api.modpacks.ch/public/modpack/100' => Factory::response($d(100)),
    'api.modpacks.ch/public/modpack/35' => Factory::response('boom', 500),
]);
$t('search: um detalhe com erro HTTP é saltado, os outros aparecem', count($c->search(null, 1, 20)['hits']) === 1);
[$c] = $make(['api.modpacks.ch/public/modpack/popular/installs/*' => Factory::response('boom', 500)]);
$t('search: lista indisponível lança', str_contains((string) $msg(fn () => $c->search(null, 1, 20)), 'not reachable'));
[$c] = $make(['api.modpacks.ch/public/modpack/popular/installs/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
$t('search: ligação falhada lança', $msg(fn () => $c->search(null, 1, 20)) !== null);

// versões
[$c] = $make(['api.modpacks.ch/public/modpack/100' => Factory::response($d(100, ['versions' => array_merge($detail['versions'], [
    ['id' => 99999, 'name' => '2.0.0-beta', 'type' => 'beta', 'updated' => 2000000000, 'targets' => $detail['versions'][0]['targets']],
    ['id' => 99998, 'name' => '1.0.0', 'type' => 'archived', 'updated' => 2100000000, 'targets' => $detail['versions'][0]['targets']],
    ['id' => 99997, 'name' => 'sem-alvos', 'type' => 'release', 'updated' => 2200000000, 'targets' => []],
    ['id' => 99996, 'name' => 'privada', 'type' => 'release', 'updated' => 2300000000, 'private' => true, 'targets' => $detail['versions'][0]['targets']],
])]))]);
$vs = $c->versions('100');
$t('versões: mais recente primeiro, beta etiquetada', $vs[0]['id'] === '100-99999' && $vs[0]['versionNumber'] === '2.0.0-beta (beta)' && $vs[1]['versionNumber'] === '1.11.1');
$t('versões: arquivadas, sem alvos e privadas ficam de fora', count(array_filter($vs, fn ($x) => in_array($x['id'], ['100-99998', '100-99997', '100-99996'], true))) === 0);
$t('versões: id composto, Minecraft, loader e data ISO', $vs[1]['id'] === '100-12632' && $vs[1]['gameVersions'] === ['1.18.2'] && $vs[1]['loaders'] === ['forge'] && preg_match('/^\d{4}-\d\d-\d\dT/', $vs[1]['datePublished']) === 1);
$t('versões: id de pack inválido lança', $msg(fn () => $c->versions('../etc')) !== null && $msg(fn () => $c->versions('')) !== null && $msg(fn () => $c->versions('1 2')) !== null);
[$c] = $make(['api.modpacks.ch/public/modpack/777' => Factory::response('', 404)]);
$t('versões: pack inexistente lança', $msg(fn () => $c->versions('777')) !== null);

// ================= FtbSource::plan
$mkSource = function (array $builds, array $fakes = []) use ($version, $detail) {
    $http = new Factory();
    $http->fake($fakes + [
        'api.modpacks.ch/public/modpack/100/12632' => Factory::response($version),
        'api.modpacks.ch/public/modpack/100' => Factory::response(array_merge($detail, ['id' => 100, 'name' => 'FTB StoneBlock 3'])),
    ]);
    $calls = (object) ['builds' => []];

    return [new FtbSource(new FtbClient($http, new Repository(new ArrayStore())), function (string $type, string $mc) use ($builds, $calls) { $calls->builds[] = [$type, $mc]; return $builds; }), $calls];
};
$forge = [['id' => 777, 'projectVersionId' => '40.2.14'], ['id' => 776, 'projectVersionId' => '40.2.13']];
[$src, $calls] = $mkSource($forge);
$plan = $src->plan('100-12632');
$t('plan: pack, build exata do loader e sem arquivo (.mrpack)', $plan->pack->name === 'FTB StoneBlock 3' && $plan->loaderBuildId === 777 && $plan->mrpackUrl === null && $plan->mrpackSize === 0);
$t('plan: consulta o mcjars com FORGE e a versão de Minecraft', $calls->builds === [['FORGE', '1.18.2']]);
[$src] = $mkSource([]);
$m = $msg(fn () => $src->plan('100-12632'));
$t('plan: loader indisponível no mcjars -> mensagem clara', $m !== null && str_contains($m, '40.2.14') && str_contains($m, '1.18.2'));
[$src] = $mkSource($forge);
foreach (['100', '100-', '-12632', 'a-b', '100-12632-1', '../1-2', ''] as $bad) {
    $t('plan: id de versão inválido ' . json_encode($bad), $msg(fn () => $src->plan($bad)) !== null);
}
[$src] = $mkSource($forge, ['api.modpacks.ch/public/modpack/100/12632' => Factory::response('', 404)]);
$t('plan: versão inexistente lança', $msg(fn () => $src->plan('100-12632')) !== null);
[$src] = $mkSource($forge, ['api.modpacks.ch/public/modpack/popular/installs/*' => $ids([100])]);
$t('FtbSource::search e ::versions delegam no cliente', count($src->search(null, 1, 20)['hits']) === 1 && $src->versions('100')[0]['id'] === '100-12632');

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
