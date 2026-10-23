<?php
// Teste do planeador (resolve a versão, lê o índice, valida e escolhe a build do loader). HTTP e mcjars simulados.
// Correr: php scripts/lagplace/test-modpack-planner.php
require (getenv('PANEL_DIR') ?: '/var/www/pterodactyl') . '/vendor/autoload.php';
$dir = __DIR__ . '/../../app/Services/Lagplace/Modpacks/';
foreach (['ModpackException', 'PackPath', 'PackFile', 'ParsedPack', 'MrpackIndex', 'LoaderMap', 'ModrinthClient', 'ModpackPlan', 'ModpackPlanner'] as $c) {
    require_once $dir . $c . '.php';
}

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackPlanner;
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
$msg = function (callable $fn): ?string {
    try {
        $fn();
    } catch (ModpackException $e) {
        return $e->getMessage();
    }

    return null;
};

$mrpackUrl = 'https://cdn.modrinth.com/data/P/versions/V/pack.mrpack';
$version = ['id' => 'V1234567', 'project_id' => 'P1234567', 'files' => [['filename' => 'pack.mrpack', 'primary' => true, 'size' => 5000, 'url' => $mrpackUrl, 'hashes' => ['sha512' => 'b']]]];
$index = fn (array $deps = ['minecraft' => '1.20.1', 'forge' => '47.4.28']) => json_encode([
    'formatVersion' => 1, 'game' => 'minecraft', 'name' => 'Pack', 'versionId' => '1.0', 'dependencies' => $deps,
    'files' => [['path' => 'mods/a.jar', 'hashes' => [], 'env' => ['server' => 'required'], 'downloads' => ['https://cdn.modrinth.com/data/x/a.jar'], 'fileSize' => 100]],
], JSON_UNESCAPED_SLASHES);

$make = function (array $http, callable $readIndex, callable $builds) {
    $f = new Factory();
    $f->fake($http);
    $calls = (object) ['index' => 0, 'builds' => []];
    $client = new ModrinthClient($f, new Repository(new ArrayStore()));
    $planner = new ModpackPlanner(
        $client,
        function (string $url) use ($readIndex, $calls) { $calls->index++; return $readIndex($url); },
        function (string $type, string $mc) use ($builds, $calls) { $calls->builds[] = [$type, $mc]; return $builds($type, $mc); },
    );

    return [$planner, $calls];
};
$forgeBuilds = fn () => [['id' => 9003, 'projectVersionId' => '47.4.28'], ['id' => 9002, 'projectVersionId' => '47.4.27']];

[$p, $calls] = $make(['api.modrinth.com/v2/version/*' => Factory::response($version)], fn () => $index(), $forgeBuilds);
$plan = $p->plan('V1234567');
$t('plano: pack validado', $plan->pack->name === 'Pack' && $plan->pack->loader === 'forge' && $plan->pack->minecraft === '1.20.1' && count($plan->pack->files) === 1);
$t('plano: build id exato do loader', $plan->loaderBuildId === 9003);
$t('plano: url e tamanho do .mrpack', $plan->mrpackUrl === $mrpackUrl && $plan->mrpackSize === 5000);
$t('plano: leu o índice 1 vez a partir do url resolvido', $calls->index === 1);
$t('plano: consultou o mcjars com FORGE e a versão do Minecraft', $calls->builds === [['FORGE', '1.20.1']]);

// loader inexistente no mcjars (NeoForge 1.20.1): recusa com mensagem clara
[$p] = $make(['api.modrinth.com/v2/version/*' => Factory::response($version)], fn () => $index(['minecraft' => '1.20.1', 'neoforge' => '47.1.0']), fn () => []);
$m = $msg(fn () => $p->plan('V1234567'));
$t('loader indisponível: mensagem clara com versão do loader e do Minecraft', $m !== null && str_contains($m, '47.1.0') && str_contains($m, '1.20.1'));

// versão do loader que o mcjars não tem
[$p] = $make(['api.modrinth.com/v2/version/*' => Factory::response($version)], fn () => $index(['minecraft' => '1.20.1', 'forge' => '99.9.9']), $forgeBuilds);
$t('versão do loader inexistente lança', $msg(fn () => $p->plan('V1234567')) !== null);

// índice inválido / não JSON / perigoso
[$p] = $make(['api.modrinth.com/v2/version/*' => Factory::response($version)], fn () => 'isto não é json', $forgeBuilds);
$t('índice que não é JSON lança', $msg(fn () => $p->plan('V1234567')) !== null);
[$p] = $make(['api.modrinth.com/v2/version/*' => Factory::response($version)], fn () => '"uma string"', $forgeBuilds);
$t('índice que não é objeto lança', $msg(fn () => $p->plan('V1234567')) !== null);
[$p] = $make(['api.modrinth.com/v2/version/*' => Factory::response($version)], fn () => str_replace('mods/a.jar', '../evil.jar', $index()), $forgeBuilds);
$t('índice com caminho perigoso lança', $msg(fn () => $p->plan('V1234567')) !== null);

// erros do Modrinth/leitura propagam-se
[$p] = $make(['api.modrinth.com/v2/version/*' => Factory::response('', 404)], fn () => $index(), $forgeBuilds);
$t('versão inexistente no Modrinth lança', $msg(fn () => $p->plan('V1234567')) !== null);
[$p] = $make(['api.modrinth.com/v2/version/*' => Factory::response($version)], fn () => throw new ModpackException('The modpack file is not a valid archive or could not be read.'), $forgeBuilds);
$t('falha a ler o .mrpack propaga a mensagem', str_contains((string) $msg(fn () => $p->plan('V1234567')), 'valid archive'));
[$p, $calls] = $make(['api.modrinth.com/v2/version/*' => Factory::response($version)], fn () => $index(), $forgeBuilds);
$msg(fn () => $p->plan('../etc'));
$t('id de versão inválido lança antes de qualquer pedido', $calls->index === 0);

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
