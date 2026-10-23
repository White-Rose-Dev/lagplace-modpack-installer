<?php
// Teste do resolvedor de redirects (o Wings não os segue). HTTP simulado. Correr: php scripts/lagplace/test-modpack-redirects.php
require (getenv('PANEL_DIR') ?: '/var/www/pterodactyl') . '/vendor/autoload.php';
$dir = __DIR__ . '/../../app/Services/Lagplace/Modpacks/';
foreach (['ModpackException', 'PackPath', 'PackFile', 'ParsedPack', 'MrpackIndex', 'ModrinthClient', 'RedirectResolver'] as $c) {
    require_once $dir . $c . '.php';
}

use Illuminate\Http\Client\Factory;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\RedirectResolver;

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
$make = function (array $fakes) {
    $http = new Factory();
    $http->fake($fakes);

    return [new RedirectResolver($http), $http];
};

$gh = 'https://github.com/o/r/releases/download/v1/m.jar';
$final = 'https://release-assets.githubusercontent.com/github-production-release-asset/1/m.jar?sig=abc';

// sem redirect: devolve o próprio URL
[$r] = $make(['*' => Factory::response('', 200)]);
$t('200 direto devolve o mesmo URL', $r->resolve($gh) === $gh);

// 302 -> destino final permitido
[$r, $http] = $make([$gh => Factory::response('', 302, ['Location' => $final]), 'release-assets.githubusercontent.com/*' => Factory::response('', 200)]);
$t('segue o redirect até ao destino final permitido', $r->resolve($gh) === $final);
$t('__invoke equivale a resolve', $r($gh) === $final);
$reqs = $http->recorded()->map(fn ($p) => $p[0]);
$t('usa HEAD e o User-Agent identificável', $reqs[0]->method() === 'HEAD' && str_contains($reqs[0]->header('User-Agent')[0] ?? '', 'LagPlace-Panel'));

// redirect para fora da lista
[$r] = $make([$gh => Factory::response('', 302, ['Location' => 'https://evil.example/stolen.jar'])]);
$t('redirect para host fora da lista lança', $msg(fn () => $r->resolve($gh)) !== null);
[$r] = $make([$gh => Factory::response('', 302, ['Location' => 'http://objects.githubusercontent.com/x'])]);
$t('redirect para http (não https) lança', $msg(fn () => $r->resolve($gh)) !== null);
[$r] = $make([$gh => Factory::response('', 302, ['Location' => 'https://127.0.0.1/x'])]);
$t('redirect para IP interno lança', $msg(fn () => $r->resolve($gh)) !== null);

// Location relativo
[$r] = $make([$gh => Factory::response('', 301, ['Location' => '/o/r/releases/download/v2/m.jar']), 'github.com/o/r/releases/download/v2/*' => Factory::response('', 200)]);
$t('Location relativo é resolvido contra o host atual', $r->resolve($gh) === 'https://github.com/o/r/releases/download/v2/m.jar');

// cadeia longa
$chain = [];
for ($i = 0; $i < 10; $i++) {
    $chain["github.com/hop$i"] = Factory::response('', 302, ['Location' => 'https://github.com/hop' . ($i + 1)]);
}
[$r] = $make($chain);
$m = $msg(fn () => $r->resolve('https://github.com/hop0'));
$t('demasiados redirects lança', $m !== null && str_contains($m, 'redirects'));

// 404/500
[$r] = $make(['*' => Factory::response('', 404)]);
$t('404 lança com o código', str_contains((string) $msg(fn () => $r->resolve($gh)), '404'));
[$r] = $make(['*' => Factory::response('', 500)]);
$t('500 lança', $msg(fn () => $r->resolve($gh)) !== null);

// ligação falhada
[$r] = $make(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
$t('ligação falhada lança', $msg(fn () => $r->resolve($gh)) !== null);

// HEAD recusado: usa GET com Range
$calls = [];
[$r, $http] = $make(['*' => function ($req) use (&$calls) {
    $calls[] = $req->method();

    return $req->method() === 'HEAD' ? Factory::response('', 405) : Factory::response('x', 206);
}]);
$t('HEAD 405: recorre a GET e aceita 206', $r->resolve($gh) === $gh && $calls === ['HEAD', 'GET']);

// URL inicial já fora da lista nunca é pedido
[$r, $http] = $make(['*' => Factory::response('', 200)]);
$t('URL inicial não permitido lança sem fazer pedidos', $msg(fn () => $r->resolve('https://evil.example/a.jar')) !== null && $http->recorded()->count() === 0);

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
