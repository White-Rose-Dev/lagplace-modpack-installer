<?php
// Teste da leitura do .modpack/manifest.json. Correr: php scripts/lagplace/test-modpack-installed.php (não precisa do painel a correr).
require_once __DIR__ . '/../../app/Services/Lagplace/Modpacks/InstalledModpack.php';

use Pterodactyl\Services\Lagplace\Modpacks\InstalledModpack;

$n = 0;
$f = 0;
$t = function (string $label, bool $ok) use (&$n, &$f) {
    $n++;
    if (!$ok) {
        $f++;
        echo "FALHOU: $label\n";
    }
};

$real = json_encode(['pack' => 'Zombie Invade 100 Days', 'versionId' => 'x', 'minecraft' => '1.20.1', 'loader' => 'forge', 'loaderVersion' => '47.4.0', 'installed_at' => '2026-10-24T01:02:03+00:00', 'files' => ['mods/a.jar']]);
$r = InstalledModpack::parse($real);
$t('caso real', $r === ['pack' => 'Zombie Invade 100 Days', 'minecraft' => '1.20.1', 'loader' => 'forge', 'installed_at' => '2026-10-24T01:02:03+00:00']);
$t('a lista de ficheiros não sai', !array_key_exists('files', $r));

$t('JSON inválido -> null', InstalledModpack::parse('{nao e json') === null);
$t('vazio -> null', InstalledModpack::parse('') === null);
$t('lista em vez de objeto -> null', InstalledModpack::parse('[1,2]') === null);
$t('sem pack -> null', InstalledModpack::parse('{"minecraft":"1.20.1"}') === null);
$t('pack não é texto -> null', InstalledModpack::parse('{"pack":{"a":1}}') === null);
$t('pack em branco -> null', InstalledModpack::parse('{"pack":"   "}') === null);

$long = InstalledModpack::parse(json_encode(['pack' => str_repeat('é', 500)]));
$t('pack é cortado a 100 caracteres', mb_strlen($long['pack']) === 100);

$bad = InstalledModpack::parse(json_encode(['pack' => 'P', 'minecraft' => '<script>', 'loader' => 'evil', 'installed_at' => 'ontem']));
$t('campos estranhos viram null', $bad === ['pack' => 'P', 'minecraft' => null, 'loader' => null, 'installed_at' => null]);
$t('minecraft com barra é recusado', InstalledModpack::parse('{"pack":"P","minecraft":"../1"}')['minecraft'] === null);
$t('minecraft não-texto é recusado', InstalledModpack::parse('{"pack":"P","minecraft":120}')['minecraft'] === null);
foreach (['forge', 'neoforge', 'fabric', 'quilt'] as $l) {
    $t("loader $l aceite", InstalledModpack::parse(json_encode(['pack' => 'P', 'loader' => $l]))['loader'] === $l);
}
$t('minimo: só o nome', InstalledModpack::parse('{"pack":"P"}') === ['pack' => 'P', 'minecraft' => null, 'loader' => null, 'installed_at' => null]);

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
