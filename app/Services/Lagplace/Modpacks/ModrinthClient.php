<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;

/** Cliente mínimo da API v2 do Modrinth, só para modpacks que servem para servidor. */
final class ModrinthClient
{
    public const USER_AGENT = 'LagPlace-Panel/1.0 (suporte@lagplace.xyz)';
    private const BASE = 'https://api.modrinth.com/v2';
    private const LOADERS = ['forge', 'neoforge', 'fabric', 'quilt'];
    private const TTL = 300;

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

        return $this->cache->remember('lagplace:modpacks:search:' . md5("$query|$page|$perPage"), self::TTL, function () use ($query, $page, $perPage) {
            $body = $this->get('/search', [
                'query' => $query,
                'facets' => json_encode([['project_type:modpack'], ['server_side:required', 'server_side:optional']]),
                'limit' => $perPage,
                'offset' => ($page - 1) * $perPage,
                'index' => $query === '' ? 'downloads' : 'relevance',
            ]);

            $hits = [];
            foreach (is_array($body['hits'] ?? null) ? $body['hits'] : [] as $h) {
                $hits[] = [
                    'id' => (string) ($h['project_id'] ?? ''),
                    'slug' => (string) ($h['slug'] ?? ''),
                    'url' => isset($h['slug']) && is_string($h['slug']) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $h['slug']) ? 'https://modrinth.com/modpack/' . $h['slug'] : null,
                    'title' => (string) ($h['title'] ?? ''),
                    'description' => (string) ($h['description'] ?? ''),
                    'icon' => isset($h['icon_url']) && is_string($h['icon_url']) && str_starts_with($h['icon_url'], 'https://') ? $h['icon_url'] : null,
                    'downloads' => (int) ($h['downloads'] ?? 0),
                    'loaders' => array_values(array_filter(is_array($h['categories'] ?? null) ? $h['categories'] : [], fn ($c) => in_array($c, self::LOADERS, true))),
                    'versions' => array_slice(array_values(is_array($h['versions'] ?? null) ? $h['versions'] : []), -8),
                ];
            }

            return ['total' => (int) ($body['total_hits'] ?? 0), 'hits' => $hits];
        });
    }

    /** @return list<array{id:string,name:string,versionNumber:string,gameVersions:array,loaders:array,datePublished:string}> */
    public function versions(string $projectId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $projectId) !== 1) {
            throw new ModpackException('Invalid modpack id.');
        }

        return $this->cache->remember("lagplace:modpacks:versions:$projectId", self::TTL, function () use ($projectId) {
            $body = $this->get("/project/$projectId/version");
            $out = [];
            foreach (array_slice(is_array($body) ? $body : [], 0, 50) as $v) {
                $out[] = [
                    'id' => (string) ($v['id'] ?? ''),
                    'name' => (string) ($v['name'] ?? ''),
                    'versionNumber' => (string) ($v['version_number'] ?? ''),
                    'gameVersions' => array_values(is_array($v['game_versions'] ?? null) ? $v['game_versions'] : []),
                    'loaders' => array_values(is_array($v['loaders'] ?? null) ? $v['loaders'] : []),
                    'datePublished' => (string) ($v['date_published'] ?? ''),
                ];
            }

            return $out;
        });
    }

    /** @return array{mrpackUrl:string,size:int,sha512:string,projectId:string} */
    public function resolve(string $versionId): array
    {
        if (preg_match('/^[A-Za-z0-9]{1,32}$/', $versionId) !== 1) {
            throw new ModpackException('Invalid modpack version id.');
        }

        $body = $this->get("/version/$versionId");
        $packs = array_values(array_filter(
            is_array($body['files'] ?? null) ? $body['files'] : [],
            fn ($f) => is_array($f) && is_string($f['filename'] ?? null) && str_ends_with(strtolower($f['filename']), '.mrpack')
        ));
        usort($packs, fn ($a, $b) => (int) ($b['primary'] ?? false) <=> (int) ($a['primary'] ?? false));
        $file = $packs[0] ?? null;
        if ($file === null) {
            throw new ModpackException('This version has no .mrpack file, so it cannot be installed automatically.');
        }
        $url = (string) ($file['url'] ?? '');
        if (!MrpackIndex::isAllowedUrl($url)) {
            throw new ModpackException('The modpack file is hosted somewhere that is not allowed.');
        }

        return [
            'mrpackUrl' => $url,
            'size' => (int) ($file['size'] ?? 0),
            'sha512' => (string) ($file['hashes']['sha512'] ?? ''),
            'projectId' => (string) ($body['project_id'] ?? ''),
        ];
    }

    private function request(): PendingRequest
    {
        return $this->http->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])->timeout(15);
    }

    private function get(string $path, array $query = []): array
    {
        try {
            $res = $this->request()->get(self::BASE . $path, $query);
        } catch (ConnectionException) {
            throw new ModpackException('Modrinth is not reachable right now.');
        }
        if ($res->status() === 404) {
            throw new ModpackException('That modpack or version was not found on Modrinth.');
        }
        if (!$res->ok() || !is_array($res->json())) {
            throw new ModpackException('Modrinth is not reachable right now.');
        }

        return $res->json();
    }
}
