<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

use Illuminate\Contracts\Cache\Repository as Cache;

/** Progresso, lock (uma instalação por servidor) e pedido de cancelamento, guardados na cache. */
final class ModpackState
{
    public const STAGES = ['queued', 'validating', 'backup', 'loader', 'pack', 'overrides', 'mods', 'verify', 'finalize', 'done', 'failed', 'cancelled'];

    private const STALE_AFTER = 600;
    private const STATE_TTL = 21600;
    private const CANCEL_TTL = 3600;
    private const EXTRA_KEYS = ['done', 'total', 'backup_uuid', 'error', 'wiped'];

    /** @var callable */
    private $clock;

    public function __construct(private Cache $cache, ?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    public function begin(int $serverId, string $packName): bool
    {
        $now = ($this->clock)();
        if (!$this->cache->add($this->key('lock', $serverId), $now, self::STATE_TTL)) {
            $current = $this->get($serverId);
            $active = $current !== null && !in_array($current['stage'], ['done', 'failed', 'cancelled'], true)
                && ($now - $current['updated_at']) <= self::STALE_AFTER;
            if ($active) {
                return false;
            }
            $this->cache->put($this->key('lock', $serverId), $now, self::STATE_TTL);
        }

        $this->cache->forget($this->key('cancel', $serverId));
        $this->save($serverId, [
            'stage' => 'queued', 'percent' => 0, 'pack' => $packName, 'detail' => null, 'done' => 0, 'total' => 0,
            'backup_uuid' => null, 'error' => null, 'wiped' => false, 'started_at' => $now, 'updated_at' => $now,
        ]);

        return true;
    }

    /** @param array<string, mixed> $extra só as chaves done, total, backup_uuid, error e wiped são aceites */
    public function update(int $serverId, string $stage, int $percent, ?string $detail = null, array $extra = []): void
    {
        $state = $this->get($serverId) ?? $this->blank();
        $state['stage'] = $stage;
        $state['percent'] = max(0, min(100, $percent));
        $state['detail'] = $detail;
        foreach (self::EXTRA_KEYS as $k) {
            if (array_key_exists($k, $extra)) {
                $state[$k] = $extra[$k];
            }
        }
        $state['updated_at'] = ($this->clock)();
        $this->save($serverId, $state);
    }

    public function get(int $serverId): ?array
    {
        $state = $this->cache->get($this->key('state', $serverId));

        return is_array($state) ? $state : null;
    }

    /**
     * O que se mostra ao utilizador: como get(), mas uma instalação "ativa" que não dá sinais de vida há mais de
     * STALE_AFTER segundos (worker reiniciado a meio, por exemplo) aparece como falhada em vez de ficar eternamente em curso.
     */
    public function view(int $serverId): ?array
    {
        $state = $this->get($serverId);
        if ($state !== null && !in_array($state['stage'], ['done', 'failed', 'cancelled'], true)
            && (($this->clock)() - $state['updated_at']) > self::STALE_AFTER) {
            $state['stage'] = 'failed';
            $state['detail'] = null;
            $state['error'] = 'The installation stopped responding and was interrupted.';
        }

        return $state;
    }

    public function requestCancel(int $serverId): void
    {
        $this->cache->put($this->key('cancel', $serverId), 1, self::CANCEL_TTL);
    }

    public function cancelRequested(int $serverId): bool
    {
        return $this->cache->has($this->key('cancel', $serverId));
    }

    public function finish(int $serverId, ?string $error = null, ?string $stage = null): void
    {
        $state = $this->get($serverId) ?? $this->blank();
        $state['stage'] = $stage ?? ($error === null ? 'done' : 'failed');
        $state['error'] = $error;
        $state['detail'] = null;
        if ($state['stage'] === 'done') {
            $state['percent'] = 100;
        }
        $state['updated_at'] = ($this->clock)();
        $this->save($serverId, $state);
        $this->cache->forget($this->key('lock', $serverId));
    }

    private function save(int $serverId, array $state): void
    {
        $this->cache->put($this->key('state', $serverId), $state, self::STATE_TTL);
    }

    private function blank(): array
    {
        $now = ($this->clock)();

        return ['stage' => 'queued', 'percent' => 0, 'pack' => '', 'detail' => null, 'done' => 0, 'total' => 0, 'backup_uuid' => null, 'error' => null, 'wiped' => false, 'started_at' => $now, 'updated_at' => $now];
    }

    private function key(string $what, int $serverId): string
    {
        return "lagplace:modpack:$what:$serverId";
    }
}
