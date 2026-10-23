<?php
// Teste da lista de fornecedores de modpacks. Correr: php scripts/lagplace/test-modpack-providers.php
$dir = __DIR__ . '/../../app/Services/Lagplace/Modpacks/';
foreach (['ModpackException', 'ModpackProviders'] as $c) {
    require_once $dir . $c . '.php';
}

use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackProviders;

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

$all = ModpackProviders::all();
$ids = array_column($all, 'id');
$t('ids únicos', count($ids) === count(array_unique($ids)));
$t('o fornecedor por omissão existe e está ativo', in_array(ModpackProviders::DEFAULT, $ids, true) && array_values(array_filter($all, fn ($p) => $p['id'] === ModpackProviders::DEFAULT))[0]['enabled'] === true);
$t('cada entrada tem id, nome e enabled', array_reduce($all, fn ($ok, $p) => $ok && isset($p['id'], $p['name']) && is_bool($p['enabled']), true));
$t('Modrinth e Feed The Beast ativos', ModpackProviders::require('modrinth') === 'modrinth' && ModpackProviders::require('ftb') === 'ftb');
$t('vazio e null usam o fornecedor por omissão', ModpackProviders::require(null) === 'modrinth' && ModpackProviders::require('') === 'modrinth');
foreach (['curseforge', 'atlauncher', 'technic'] as $id) {
    $m = $msg(fn () => ModpackProviders::require($id));
    $t("$id existe mas não está disponível", $m !== null && str_contains($m, 'not available'));
}
$t('fornecedor desconhecido lança', str_contains((string) $msg(fn () => ModpackProviders::require('voidswrath')), 'Unknown'));
$t('injeção no id lança', $msg(fn () => ModpackProviders::require('../modrinth')) !== null);
$t('maiúsculas não passam (ids exatos)', $msg(fn () => ModpackProviders::require('Modrinth')) !== null);

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
