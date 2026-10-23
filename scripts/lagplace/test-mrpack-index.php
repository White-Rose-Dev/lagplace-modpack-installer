<?php
// Teste da validação de packs Modrinth (.mrpack). Correr: php scripts/lagplace/test-mrpack-index.php (não precisa do painel a correr).
$dir = __DIR__ . '/../../app/Services/Lagplace/Modpacks/';
foreach (['ModpackException', 'PackPath', 'PackFile', 'ParsedPack', 'MrpackIndex'] as $c) {
    require_once $dir . $c . '.php';
}

use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\MrpackIndex;
use Pterodactyl\Services\Lagplace\Modpacks\PackPath;

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

$file = fn (string $path, array $over = []) => array_merge([
    'path' => $path,
    'hashes' => ['sha1' => str_repeat('a', 40), 'sha512' => str_repeat('b', 128)],
    'env' => ['server' => 'required', 'client' => 'required'],
    'downloads' => ['https://cdn.modrinth.com/data/AXY1OO9m/versions/54k31lEX/' . basename($path)],
    'fileSize' => 1000,
], $over);

$index = fn (array $files, array $deps = ['minecraft' => '1.21.1', 'fabric-loader' => '0.18.4'], array $over = []) => array_merge([
    'game' => 'minecraft', 'formatVersion' => 1, 'versionId' => '1.7.42', 'name' => 'COBBLEVERSE',
    'files' => $files, 'dependencies' => $deps,
], $over);

// --- caso real (excerto do COBBLEVERSE 1.7.42)
$p = MrpackIndex::parse($index([$file('mods/CobbleFurnies-fabric-1.2.jar'), $file('mods/b.jar', ['fileSize' => 500])]));
$t('parse: loader fabric', $p->loader === 'fabric');
$t('parse: versão do loader', $p->loaderVersion === '0.18.4');
$t('parse: minecraft', $p->minecraft === '1.21.1');
$t('parse: nome e versão do pack', $p->name === 'COBBLEVERSE' && $p->versionId === '1.7.42');
$t('parse: 2 ficheiros', count($p->files) === 2);
$t('parse: total de bytes', $p->totalBytes === 1500);
$t('parse: url escolhido', $p->files[0]->urls[0] === 'https://cdn.modrinth.com/data/AXY1OO9m/versions/54k31lEX/CobbleFurnies-fabric-1.2.jar');

// --- env.server unsupported é ignorado (nunca vai para o servidor)
$p = MrpackIndex::parse($index([$file('mods/a.jar'), $file('mods/client.jar', ['env' => ['server' => 'unsupported', 'client' => 'required']])]));
$t('unsupported ignorado', count($p->files) === 1 && $p->files[0]->path === 'mods/a.jar');
$p = MrpackIndex::parse($index([$file('mods/opt.jar', ['env' => ['server' => 'optional', 'client' => 'required']])]));
$t('optional incluído', count($p->files) === 1);
$p = MrpackIndex::parse($index([$file('mods/noenv.jar', ['env' => null])]));
$t('sem env incluído', count($p->files) === 1);

// --- loaders
foreach ([['forge', '47.4.28', 'forge'], ['neoforge', '21.1.264', 'neoforge'], ['fabric-loader', '0.18.4', 'fabric'], ['quilt-loader', '0.26.0', 'quilt']] as [$key, $ver, $expect]) {
    $p = MrpackIndex::parse($index([$file('mods/a.jar')], ['minecraft' => '1.21.1', $key => $ver]));
    $t("loader $key -> $expect", $p->loader === $expect && $p->loaderVersion === $ver);
}
$t('sem minecraft lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar')], ['fabric-loader' => '0.18.4']))));
$t('sem loader lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar')], ['minecraft' => '1.21.1']))));
$t('dois loaders lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar')], ['minecraft' => '1.21.1', 'forge' => '47.4.28', 'fabric-loader' => '0.18.4']))));
$t('loader com valor estranho lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar')], ['minecraft' => '1.21.1', 'forge' => '47; rm -rf /']))));
$t('minecraft com valor estranho lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar')], ['minecraft' => '../1.21', 'forge' => '47.4.28']))));
$t('formatVersion 2 lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar')], ['minecraft' => '1.21.1', 'forge' => '1'], ['formatVersion' => 2]))));
$t('game != minecraft lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar')], ['minecraft' => '1.21.1', 'forge' => '1'], ['game' => 'hytale']))));
$t('files em falta lança', $throws(fn () => MrpackIndex::parse(['game' => 'minecraft', 'formatVersion' => 1, 'versionId' => '1', 'name' => 'x', 'dependencies' => ['minecraft' => '1.21.1', 'forge' => '1']])));

// --- caminhos perigosos
foreach (['../x', '/etc/passwd', 'a\\b', 'C:\\x', 'mods/../../x', "mods/a\0.jar", '', '.', './', 'mods/..'] as $bad) {
    $t('PackPath rejeita ' . json_encode($bad), $throws(fn () => PackPath::normalize($bad)));
    $t('parse rejeita caminho ' . json_encode($bad), $throws(fn () => MrpackIndex::parse($index([$file($bad)]))));
}
$t('PackPath normaliza ./mods//a.jar', PackPath::normalize('./mods//a.jar') === 'mods/a.jar');
$t('PackPath mantém ficheiros com ponto', PackPath::normalize('config/.hidden/x.toml') === 'config/.hidden/x.toml');

// --- duplicados
$t('caminhos duplicados lançam', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar'), $file('mods/./a.jar')]))));

// --- URLs
foreach (['https://evil.example/a.jar', 'http://cdn.modrinth.com/a.jar', 'https://cdn.modrinth.com:8443/a.jar', 'https://user@cdn.modrinth.com/a.jar', 'https://cdn.modrinth.com.evil.com/a.jar', 'ftp://cdn.modrinth.com/a.jar', 'https://127.0.0.1/a.jar', 'https:///a.jar', 'javascript:alert(1)', 'https://github.com@evil.com/a.jar'] as $u) {
    $t("isAllowedUrl rejeita $u", MrpackIndex::isAllowedUrl($u) === false);
}
foreach (['https://cdn.modrinth.com/data/a/b.jar', 'https://github.com/o/r/releases/download/v1/a.jar', 'https://raw.githubusercontent.com/o/r/main/a.jar', 'https://gitlab.com/o/r/-/raw/main/a.jar'] as $u) {
    $t("isAllowedUrl aceita $u", MrpackIndex::isAllowedUrl($u) === true);
}
$t('url fora da lista aborta o pack', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar', ['downloads' => ['https://evil.example/a.jar']])]))));
$t('sem downloads aborta', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar', ['downloads' => []])]))));
$p = MrpackIndex::parse($index([$file('mods/a.jar', ['downloads' => ['https://evil.example/a.jar', 'https://cdn.modrinth.com/data/x/a.jar']])]));
$t('escolhe o primeiro url permitido', $p->files[0]->urls[0] === 'https://cdn.modrinth.com/data/x/a.jar');

// --- destinos finais de redirects (github.com -> githubusercontent)
foreach (['https://objects.githubusercontent.com/github-production-release-asset/1/x', 'https://release-assets.githubusercontent.com/a/b', 'https://cdn.modrinth.com/a.jar'] as $u) {
    $t("isAllowedFinalUrl aceita $u", MrpackIndex::isAllowedFinalUrl($u) === true);
}
foreach (['https://evil.example/a', 'http://objects.githubusercontent.com/a', 'https://objects.githubusercontent.com:444/a', 'https://objects.githubusercontent.com.evil.com/a', 'https://u@objects.githubusercontent.com/a'] as $u) {
    $t("isAllowedFinalUrl rejeita $u", MrpackIndex::isAllowedFinalUrl($u) === false);
}
$t('um índice NÃO pode apontar diretamente para githubusercontent (só destinos de redirect)', MrpackIndex::isAllowedUrl('https://objects.githubusercontent.com/a') === false);

// --- tamanhos e limites
$t('fileSize negativo lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar', ['fileSize' => -1])]))));
$t('fileSize não numérico lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar', ['fileSize' => 'grande'])]))));
$many = [];
for ($i = 0; $i < 3001; $i++) {
    $many[] = $file("mods/m$i.jar");
}
$t('3001 ficheiros lança', $throws(fn () => MrpackIndex::parse($index($many))));
$t('3000 ficheiros passa', count(MrpackIndex::parse($index(array_slice($many, 0, 3000)))->files) === 3000);
$t('total acima do teto lança', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar', ['fileSize' => 4294967297])]))));
$t('limites configuráveis', $throws(fn () => MrpackIndex::parse($index([$file('mods/a.jar'), $file('mods/b.jar')]), ['maxFiles' => 1, 'maxBytes' => 4294967296])));

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
