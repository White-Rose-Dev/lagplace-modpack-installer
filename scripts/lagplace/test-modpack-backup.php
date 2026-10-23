<?php
// Teste do BackupRunner por polling (gateway simulado, relógio e sleep simulados). Correr: php scripts/lagplace/test-modpack-backup.php
require (getenv('PANEL_DIR') ?: '/var/www/pterodactyl') . '/vendor/autoload.php';
$dir = __DIR__ . '/../../app/Services/Lagplace/Modpacks/';
foreach (['ModpackException', 'ModpackCancelled', 'ModpackState', 'BackupRunner', 'BackupGateway', 'PollingBackupRunner'] as $c) {
    require_once $dir . $c . '.php';
}

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Pterodactyl\Services\Lagplace\Modpacks\BackupGateway;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackCancelled;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackState;
use Pterodactyl\Services\Lagplace\Modpacks\PollingBackupRunner;

final class FakeGateway implements BackupGateway
{
    public int $started = 0;
    public int $polls = 0;
    /** @param list<array{done:bool,ok:bool}> $script */
    public function __construct(public array $script, public ?\Throwable $startFails = null)
    {
    }

    public function start(): string
    {
        $this->started++;
        if ($this->startFails) {
            throw $this->startFails;
        }

        return 'uuid-9';
    }

    public function status(string $uuid): array
    {
        $this->polls++;

        return array_shift($this->script) ?? ['done' => false, 'ok' => false];
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
$catch = function (callable $fn): ?\Throwable {
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
};

$now = 0;
$sleeps = [];
$clock = function () use (&$now) { return $now; };
$sleep = function (int $s) use (&$now, &$sleeps) { $sleeps[] = $s; $now += $s; };
$mk = function (FakeGateway $gw, ?ModpackState &$state = null) use ($clock, $sleep) {
    $state = new ModpackState(new Repository(new ArrayStore()));
    $state->begin(1, 'P');

    return new PollingBackupRunner($gw, $state, 1, $sleep, $clock, 100, 5);
};

$gw = new FakeGateway([['done' => false, 'ok' => false], ['done' => false, 'ok' => false], ['done' => true, 'ok' => true]]);
$r = $mk($gw);
$t('devolve o uuid quando o backup termina com sucesso', $r->runAndWait() === 'uuid-9');
$t('fez polling 3 vezes, dormiu 2 entre elas, iniciou 1 vez', $gw->polls === 3 && $sleeps === [5, 5] && $gw->started === 1);

$gw = new FakeGateway([['done' => true, 'ok' => false]]);
$e = $catch(fn () => $mk($gw)->runAndWait());
$t('backup concluído sem sucesso lança ModpackException', $e instanceof ModpackException && !($e instanceof ModpackCancelled) && str_contains($e->getMessage(), 'failed'));

$now = 0;
$gw = new FakeGateway([]);
$e = $catch(fn () => $mk($gw)->runAndWait());
$t('timeout lança com mensagem a dizer que demora demasiado', $e instanceof ModpackException && str_contains($e->getMessage(), 'too long'));
$t('...e não ficou a dormir para sempre (<= 100 s + 1 intervalo)', $now <= 105);

$now = 0;
$state = null;
$gw = new FakeGateway([['done' => false, 'ok' => false], ['done' => false, 'ok' => false], ['done' => true, 'ok' => true]]);
$runner = $mk($gw, $state);
$state->requestCancel(1);
$e = $catch(fn () => $runner->runAndWait());
$t('cancelamento durante a espera lança ModpackCancelled', $e instanceof ModpackCancelled);
$t('...logo à primeira verificação (1 poll no máximo)', $gw->polls <= 1);

$gw = new FakeGateway([], new ModpackException('Cannot create a new backup, this server has reached its limit of 0 backups.'));
$e = $catch(fn () => $mk($gw)->runAndWait());
$t('falha ao iniciar (limite de backups) mantém a mensagem original', $e instanceof ModpackException && str_contains($e->getMessage(), 'limit of 0 backups'));
$gw = new FakeGateway([], new \RuntimeException('connection refused to wings'));
$e = $catch(fn () => $mk($gw)->runAndWait());
$t('falha genérica ao iniciar vira ModpackException sem expor detalhes internos', $e instanceof ModpackException && !str_contains($e->getMessage(), 'wings') && str_contains($e->getMessage(), 'backup'));
$t('...e nunca chegou a fazer polling', $gw->polls === 0);

echo $f === 0 ? "TODOS OS $n TESTES PASSARAM\n" : "$f de $n FALHARAM\n";
exit($f === 0 ? 0 : 1);
