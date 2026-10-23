<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;

/** Cliente mínimo da API pública do Feed The Beast (api.modpacks.ch). A pesquisa só devolve ids; os detalhes vêm de pedidos em paralelo. */
final class FtbClient
{
    private const BASE = 'https://api.modpacks.ch/public';
    private const ELIGIBLE = ['release', 'beta', 'alpha'];
    private const TTL = 300;
    private const DETAIL_TTL = 3600;

    public function __construct(private Http $http, private Cache $cache)
    {
    }

    /** @return array{total:int, hits:list<array<string,mixed>>} */
    public function search(?string $query, int $page, int $perPage = 20): array
    {
        if ($page < 1) {
            throw new ModpackException('Invalid page.');
        }
        $perPage = max(1, min(50, $perPage));
        $query = mb_substr(trim((string) $query), 0, 100);
        // A lista de ids é barata e fica em cache; só os detalhes da página pedida são buscados. 100 é o máximo que a API devolve.
        $limit = 100;

        $ids = $this->cache->remember('lagplace:modpacks:ftb:search:' . md5("$query|$limit"), self::TTL, function () use ($query, $limit) {
            $body = $query === ''
                ? $this->get("/modpack/popular/installs/$limit")
                : $this->get("/modpack/search/$limit", ['term' => $query]);

            // A pesquisa por termo também devolve ids de packs alojados no CurseForge ("curseforge"): não se usam aqui.
            return array_values(array_filter(array_map('intval', is_array($body['packs'] ?? null) ? $body['packs'] : []), fn ($id) => $id > 0));
        });

        $hits = [];
        foreach ($this->details(array_slice($ids, ($page - 1) * $perPage, $perPage)) as $detail) {
            $hits[] = $this->hit($detail);
        }

        return ['total' => count($ids), 'hits' => array_values(array_filter($hits))];
    }

    /** @return list<array{id:string,name:string,versionNumber:string,gameVersions:array,loaders:array,datePublished:string}> */
    public function versions(string $packId): array
    {
        $id = $this->packId($packId);
        $detail = $this->details([$id])[$id] ?? throw new ModpackException('That modpack or version was not found.');

        $out = [];
        foreach ($this->eligibleVersions($detail) as $v) {
            [$mc, $loader] = $this->versionTargets($v);
            $out[] = [
                'id' => $id . '-' . $v['id'],
                'name' => (string) $v['name'],
                'versionNumber' => $v['name'] . ($v['type'] === 'release' ? '' : ' (' . $v['type'] . ')'),
                'gameVersions' => $mc === null ? [] : [$mc],
                'loaders' => $loader === null ? [] : [$loader],
                'datePublished' => gmdate('c', (int) ($v['updated'] ?? 0)),
            ];
        }

        return array_slice($out, 0, 50);
    }

    /** Resposta completa de uma versão (ficheiros e alvos). */
    public function versionPayload(int $packId, int $versionId): array
    {
        return $this->cache->remember("lagplace:modpacks:ftb:version:$packId:$versionId", 600, fn () => $this->get("/modpack/$packId/$versionId"));
    }

    public function packName(int $packId): string
    {
        $detail = $this->details([$packId])[$packId] ?? null;

        return is_array($detail) && is_string($detail['name'] ?? null) ? $detail['name'] : 'FTB modpack';
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string,mixed>> detalhes por id (packs privados ou que falham ficam de fora)
     */
    private function details(array $ids): array
    {
        $out = [];
        $missing = [];
        foreach ($ids as $id) {
            $cached = $this->cache->get("lagplace:modpacks:ftb:pack:$id");
            if (is_array($cached)) {
                // packs privados ficam marcados na cache para não serem pedidos outra vez a cada pesquisa
                if (empty($cached['_skip'])) {
                    $out[$id] = $cached;
                }
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            try {
                $responses = $this->http->pool(fn (Pool $pool) => array_map(
                    fn (int $id) => $pool->as((string) $id)->withHeaders($this->headers())->timeout(15)->get(self::BASE . "/modpack/$id"),
                    $missing
                ));
            } catch (ConnectionException) {
                throw new ModpackException('Feed The Beast is not reachable right now.');
            }
            foreach ($missing as $id) {
                $res = $responses[(string) $id] ?? null;
                $body = is_object($res) && method_exists($res, 'ok') && $res->ok() ? $res->json() : null;
                if (is_array($body) && ($body['status'] ?? null) === 'success') {
                    if (!empty($body['private'])) {
                        $this->cache->put("lagplace:modpacks:ftb:pack:$id", ['_skip' => true], self::DETAIL_TTL);
                    } else {
                        $this->cache->put("lagplace:modpacks:ftb:pack:$id", $body, self::DETAIL_TTL);
                        $out[$id] = $body;
                    }
                }
            }
        }

        // mantém a ordem pedida
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($out[$id])) {
                $ordered[$id] = $out[$id];
            }
        }

        return $ordered;
    }

    /** @return array<string,mixed>|null */
    private function hit(array $d): ?array
    {
        $versions = $this->eligibleVersions($d);
        if ($versions === []) {
            return null;
        }
        [$mc, $loader] = $this->versionTargets($versions[0]);
        $icon = null;
        foreach (is_array($d['art'] ?? null) ? $d['art'] : [] as $art) {
            if (($art['type'] ?? null) === 'square' && is_string($art['url'] ?? null) && str_starts_with($art['url'], 'https://')) {
                $icon = $art['url'];
                break;
            }
        }

        return [
            'id' => (string) $d['id'],
            'slug' => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($d['name'] ?? ''))), '-'),
            'title' => (string) ($d['name'] ?? ''),
            'description' => mb_substr((string) ($d['synopsis'] ?? ''), 0, 300),
            'icon' => $icon,
            'downloads' => (int) ($d['installs'] ?? 0),
            'loaders' => $loader === null ? [] : [$loader],
            'versions' => $mc === null ? [] : [$mc],
        ];
    }

    /** Versões instaláveis (release/beta/alpha, com alvos), da mais recente para a mais antiga. @return list<array<string,mixed>> */
    private function eligibleVersions(array $detail): array
    {
        $v = array_values(array_filter(
            is_array($detail['versions'] ?? null) ? $detail['versions'] : [],
            fn ($x) => is_array($x) && in_array($x['type'] ?? null, self::ELIGIBLE, true) && !empty($x['targets']) && empty($x['private']) && isset($x['id'])
        ));
        usort($v, fn ($a, $b) => [(int) ($b['updated'] ?? 0), (int) $b['id']] <=> [(int) ($a['updated'] ?? 0), (int) $a['id']]);

        return $v;
    }

    /** @return array{0:?string,1:?string} minecraft, loader */
    private function versionTargets(array $version): array
    {
        $mc = $loader = null;
        foreach (is_array($version['targets'] ?? null) ? $version['targets'] : [] as $t) {
            if (($t['type'] ?? null) === 'game' && ($t['name'] ?? null) === 'minecraft') {
                $mc = (string) ($t['version'] ?? '');
            } elseif (($t['type'] ?? null) === 'modloader') {
                $loader = strtolower((string) ($t['name'] ?? ''));
            }
        }

        return [$mc ?: null, $loader ?: null];
    }

    private function packId(string $id): int
    {
        if (preg_match('/^\d{1,9}$/', $id) !== 1) {
            throw new ModpackException('Invalid modpack id.');
        }

        return (int) $id;
    }

    private function headers(): array
    {
        return ['User-Agent' => ModrinthClient::USER_AGENT, 'Accept' => 'application/json'];
    }

    private function request(): PendingRequest
    {
        return $this->http->withHeaders($this->headers())->timeout(20);
    }

    private function get(string $path, array $query = []): array
    {
        try {
            $res = $this->request()->get(self::BASE . $path, $query);
        } catch (ConnectionException) {
            throw new ModpackException('Feed The Beast is not reachable right now.');
        }
        if ($res->status() === 404) {
            throw new ModpackException('That modpack or version was not found.');
        }
        if (!$res->ok() || !is_array($res->json())) {
            throw new ModpackException('Feed The Beast is not reachable right now.');
        }

        return $res->json();
    }
}
