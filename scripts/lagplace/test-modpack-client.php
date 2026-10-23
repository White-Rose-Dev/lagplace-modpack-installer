<?php
// Teste do cliente Modrinth (HTTP simulado, cache em memória: não toca na rede nem no Redis).
// Correr: php scripts/lagplace/test-modpack-client.php
require (getenv('PANEL_DIR') ?: '/var/www/pterodactyl') . '/vendor/autoload.php';
$dir = __DIR__ . '/../../app/Services/Lagplace/Modpacks/';
foreach (['ModpackException', 'PackPath', 'PackFile', 'ParsedPack', 'MrpackIndex', 'ModrinthClient'] as $c) {
    require_once $dir . $c . '.php';
}

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\ModrinthClient;

$n = 0;
$f = 0;
$t = function (string $label, bool $ok) use (&$n, &$f) {
    $n++;
    if (!$ok) {
        $f++;
        echo "FALHOU: $label\n";
    }
};
$throws = function (callable $fn): bool {
    try {
        $fn();
    } catch (ModpackException) {
        return true;
    }

    return false;
};
$make = function (array $fakes) {
    $http = new Factory();
    $http->fake($fakes);

    return [new ModrinthClient($http, new Repository(new ArrayStore())), $http];
};

$searchBody = ['total_hits' => 71, 'hits' => [[
    'project_id' => 'Jkb29YJU', 'slug' => 'cobbleverse', 'title' => 'COBBLEVERSE', 'description' => 'Pokemon',
    'icon_url' => 'https://cdn.modrinth.com/data/Jkb29YJU/icon.png', 'downloads' => 123,
    'categories' => ['fabric', 'adventure', 'forge'], 'versions' => ['1.20.1', '1.21.1'], 'server_side' => 'required',
]]];

// --- search
[$c, $http] = $make(['api.modrinth.com/v2/search*' => Factory::response($searchBody)]);
$r = $c->search('cobble', 2, 20);
$t('search: total', $r['total'] === 71);
$t('search: campos do hit', $r['hits'][0]['id'] === 'Jkb29YJU' && $r['hits'][0]['slug'] === 'cobbleverse' && $r['hits'][0]['downloads'] === 123);
$t('search: ícone https é mantido', $r['hits'][0]['icon'] === 'https://cdn.modrinth.com/data/Jkb29YJU/icon.png');
$t('search: loaders filtrados das categorias', $r['hits'][0]['loaders'] === ['fabric', 'forge']);
$sent = $http->recorded()->map(fn ($pair) => $pair[0])->values();
parse_str(parse_url($sent[0]->url(), PHP_URL_QUERY), $q);
$t('search: User-Agent identificável', str_contains($sent[0]->header('User-Agent')[0] ?? '', 'LagPlace-Panel'));
$t('search: facets project_type + server_side', json_decode($q['facets'] ?? '[]', true) === [['project_type:modpack'], ['server_side:required', 'server_side:optional']]);
$t('search: query, offset e limit', ($q['query'] ?? null) === 'cobble' && (int) $q['offset'] === 20 && (int) $q['limit'] === 20);
$c->search('cobble', 2, 20);
$t('search: 2ª chamada igual vem da cache (1 pedido)', $http->recorded()->count() === 1);

[$c, $http] = $make(['api.modrinth.com/v2/search*' => Factory::response($searchBody)]);
$c->search(null, 1, 20);
parse_str(parse_url($http->recorded()->first()[0]->url(), PHP_URL_QUERY), $q);
$t('search sem texto ordena por downloads', ($q['index'] ?? null) === 'downloads' && (int) $q['offset'] === 0);

[$c] = $make(['api.modrinth.com/v2/search*' => Factory::response('boom', 500)]);
$t('search: HTTP 500 lança', $throws(fn () => $c->search('x', 1)));
[$c] = $make(['api.modrinth.com/v2/search*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
$t('search: ligação falhada lança', $throws(fn () => $c->search('x', 1)));
[$c] = $make(['api.modrinth.com/v2/search*' => Factory::response($searchBody)]);
$t('search: página inválida lança', $throws(fn () => $c->search('x', 0)));
$t('search: query gigante é cortada sem erro', is_array($c->search(str_repeat('a', 500), 1)));

// ícones que não são https (http, javascript:, data:) são descartados
$bad = $searchBody;
foreach (['http://cdn.modrinth.com/i.png', 'javascript:alert(1)', 'data:image/svg+xml;base64,AAAA', null] as $i => $url) {
    $bad['hits'][0]['project_id'] = 'P' . $i;
    $bad['hits'][0]['icon_url'] = $url;
    [$cx] = $make(['api.modrinth.com/v2/search*' => Factory::response($bad)]);
    $t('search: ícone ' . json_encode($url) . ' é descartado', $cx->search('x' . $i, 1)['hits'][0]['icon'] === null);
}

// o link oficial é montado a partir do slug e só com slugs seguros
$t('search: url oficial do pack', $r['hits'][0]['url'] === 'https://modrinth.com/modpack/cobbleverse');
$bad = $searchBody;
foreach (['a/b', 'x?y=1', '../etc', 'javascript:alert(1)', "a b", '', null] as $i => $slug) {
    $bad['hits'][0]['project_id'] = 'S' . $i;
    $bad['hits'][0]['slug'] = $slug;
    [$cx] = $make(['api.modrinth.com/v2/search*' => Factory::response($bad)]);
    $t('search: slug ' . json_encode($slug) . ' não gera url', $cx->search('s' . $i, 1)['hits'][0]['url'] === null);
}

// --- versions
$verBody = [
    ['id' => 'sgQrCEiw', 'name' => 'Cobbleverse 1.7.42', 'version_number' => '1.7.42', 'game_versions' => ['1.21.1'], 'loaders' => ['fabric'], 'date_published' => '2026-01-01T00:00:00Z', 'files' => []],
    ['id' => 'abc12345', 'name' => 'Cobbleverse 1.7.41', 'version_number' => '1.7.41', 'game_versions' => ['1.21.1'], 'loaders' => ['fabric'], 'date_published' => '2025-12-01T00:00:00Z', 'files' => []],
];
[$c, $http] = $make(['api.modrinth.com/v2/project/*/version*' => Factory::response($verBody)]);
$v = $c->versions('Jkb29YJU');
$t('versions: 2 entradas', count($v) === 2);
$t('versions: campos', $v[0]['id'] === 'sgQrCEiw' && $v[0]['versionNumber'] === '1.7.42' && $v[0]['gameVersions'] === ['1.21.1'] && $v[0]['loaders'] === ['fabric']);
$t('versions: id de projeto inválido lança', $throws(fn () => $c->versions('../../etc')));
$t('versions: id vazio lança', $throws(fn () => $c->versions('')));

// --- resolve
$one = fn (array $files) => ['id' => '4SKGla61', 'project_id' => 'Jkb29YJU', 'files' => $files];
$packFile = ['filename' => 'COBBLEVERSE 1.7.42.mrpack', 'primary' => true, 'size' => 238975006,
    'url' => 'https://cdn.modrinth.com/data/Jkb29YJU/versions/4SKGla61/COBBLEVERSE%201.7.42.mrpack', 'hashes' => ['sha1' => 'a', 'sha512' => 'b']];
[$c] = $make(['api.modrinth.com/v2/version/*' => Factory::response($one([$packFile]))]);
$r = $c->resolve('4SKGla61');
$t('resolve: url, tamanho, hash, projeto', $r['mrpackUrl'] === $packFile['url'] && $r['size'] === 238975006 && $r['sha512'] === 'b' && $r['projectId'] === 'Jkb29YJU');
[$c] = $make(['api.modrinth.com/v2/version/*' => Factory::response($one([['filename' => 'notes.txt', 'primary' => true, 'size' => 1, 'url' => 'https://cdn.modrinth.com/x/notes.txt', 'hashes' => []]]))]);
$t('resolve: sem .mrpack lança', $throws(fn () => $c->resolve('4SKGla61')));
[$c] = $make(['api.modrinth.com/v2/version/*' => Factory::response($one([array_merge($packFile, ['url' => 'https://evil.example/a.mrpack'])]))]);
$t('resolve: url fora da lista lança', $throws(fn () => $c->resolve('4SKGla61')));
[$c] = $make(['api.modrinth.com/v2/version/*' => Factory::response($one([array_merge($packFile, ['filename' => 'other.zip', 'primary' => false]), $packFile]))]);
$t('resolve: escolhe o .mrpack primário', $c->resolve('4SKGla61')['size'] === 238975006);
[$c] = $make(['api.modrinth.com/v2/version/*' => Factory::response('', 404)]);
$t('resolve: 404 lança', $throws(fn () => $c->resolve('4SKGla61')));
$t('resolve: id inválido lança', $throws(fn () => $c->resolve('a/../b')));

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
