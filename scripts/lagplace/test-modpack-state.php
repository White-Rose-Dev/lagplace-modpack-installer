<?php
// Teste do estado/lock/cancelamento da instalação de modpacks (cache em memória). Correr: php scripts/lagplace/test-modpack-state.php
require (getenv('PANEL_DIR') ?: '/var/www/pterodactyl') . '/vendor/autoload.php';
require_once __DIR__ . '/../../app/Services/Lagplace/Modpacks/ModpackState.php';

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackState;

$n = 0;
$f = 0;
$t = function (string $label, bool $ok) use (&$n, &$f) {
    $n++;
    if (!$ok) {
        $f++;
        echo "FALHOU: $label\n";
    }
};

$now = 1_000_000;
$clock = function () use (&$now) { return $now; };
$s = new ModpackState(new Repository(new ArrayStore()), $clock);

$t('sem estado inicial', $s->get(1) === null);
$t('begin devolve true', $s->begin(1, 'COBBLEVERSE') === true);
$st = $s->get(1);
$t('estado inicial: queued, 0%, nome', $st['stage'] === 'queued' && $st['percent'] === 0 && $st['pack'] === 'COBBLEVERSE' && $st['started_at'] === $now);
$t('segundo begin é rejeitado (lock)', $s->begin(1, 'Outro') === false);
$t('begin noutro servidor passa', $s->begin(2, 'X') === true);

$now += 30;
$s->update(1, 'mods', 55, 'mods/a.jar', ['done' => 90, 'total' => 168]);
$st = $s->get(1);
$t('update: estágio, percent, detalhe, contadores', $st['stage'] === 'mods' && $st['percent'] === 55 && $st['detail'] === 'mods/a.jar' && $st['done'] === 90 && $st['total'] === 168);
$t('update: updated_at avança, started_at não', $st['updated_at'] === $now && $st['started_at'] === 1_000_000);
$s->update(1, 'mods', 250);
$t('update: percent limitado a 100', $s->get(1)['percent'] === 100);
$s->update(1, 'mods', -5);
$t('update: percent limitado a 0', $s->get(1)['percent'] === 0);
$t('update não mexe no servidor 2', $s->get(2)['stage'] === 'queued');
$s->update(1, 'backup', 5, null, ['backup_uuid' => 'abc-123']);
$s->update(1, 'loader', 10);
$t('extra persiste entre updates (backup_uuid)', $s->get(1)['backup_uuid'] === 'abc-123');

// stale: 11 min sem updates liberta o lock
$now += 11 * 60;
$t('instalação parada há 11 min é stale: begin passa', $s->begin(1, 'Novo') === true);
$t('...e o estado é o novo', $s->get(1)['pack'] === 'Novo' && $s->get(1)['stage'] === 'queued');
$now += 9 * 60;
$s->update(1, 'pack', 20);
$now += 9 * 60;
$t('9 min depois de um update ainda está ativa', $s->begin(1, 'Outro') === false);

// cancelamento
$t('cancelRequested falso por omissão', $s->cancelRequested(1) === false);
$s->requestCancel(1);
$t('cancelRequested verdadeiro depois do pedido', $s->cancelRequested(1) === true);
$t('cancelamento do servidor 1 não afeta o 2', $s->cancelRequested(2) === false);

// finish
$s->finish(1, 'Something broke');
$st = $s->get(1);
$t('finish com erro: stage failed + mensagem', $st['stage'] === 'failed' && $st['error'] === 'Something broke');
$t('finish liberta o lock', $s->begin(1, 'Depois') === true);
$t('begin limpa o pedido de cancelamento antigo', $s->cancelRequested(1) === false);
$s->requestCancel(2);
$s->finish(2);
$t('finish sem erro: stage done, 100%', $s->get(2)['stage'] === 'done' && $s->get(2)['percent'] === 100);
$s->requestCancel(1);
$s->finish(1, 'Cancelled by the user', 'cancelled');
$t('finish com estágio cancelled', $s->get(1)['stage'] === 'cancelled' && $s->get(1)['error'] === 'Cancelled by the user');
$t('terminado não bloqueia novo begin', $s->begin(1, 'Outra vez') === true);

// view(): ativa e parada há >10 min aparece como interrompida; get() continua a devolver o estado real
$now = 5_000_000;
$s->begin(7, 'Stuck');
$s->update(7, 'mods', 40, 'mods/x.jar', ['wiped' => true, 'backup_uuid' => 'bk-1']);
$v = $s->view(7);
$t('view: instalação recente mantém-se ativa', $v['stage'] === 'mods' && $v['error'] === null);
$now += 11 * 60;
$v = $s->view(7);
$t('view: parada há 11 min aparece como failed/interrompida', $v['stage'] === 'failed' && str_contains($v['error'], 'interrupted'));
$t('view: preserva wiped e backup_uuid (a dica de restauro precisa deles)', $v['wiped'] === true && $v['backup_uuid'] === 'bk-1');
$t('get: continua a devolver o estado real', $s->get(7)['stage'] === 'mods');
$s->finish(7);
$now += 3600;
$t('view: um estado terminado nunca é reescrito', $s->view(7)['stage'] === 'done' && $s->view(7)['error'] === null);
$t('view: sem estado devolve null', $s->view(999) === null);

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
