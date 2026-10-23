<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

final class FtbSource implements ModpackSource
{
    /** @param callable(string,string):array $builds (tipo mcjars, versão do Minecraft) → builds, como McJarsService::builds() */
    public function __construct(private FtbClient $client, private $builds)
    {
    }

    public function search(?string $query, int $page, int $perPage): array
    {
        return $this->client->search($query, $page, $perPage);
    }

    public function versions(string $projectId): array
    {
        return $this->client->versions($projectId);
    }

    /** O id da versão é "{pack}-{versão}" (a API do FTB precisa dos dois). */
    public function plan(string $versionId): ModpackPlan
    {
        if (preg_match('/^(\d{1,9})-(\d{1,9})$/', $versionId, $m) !== 1) {
            throw new ModpackException('Invalid modpack version id.');
        }
        [$packId, $ver] = [(int) $m[1], (int) $m[2]];

        $payload = $this->client->versionPayload($packId, $ver);
        $pack = FtbPack::parse($payload, $this->client->packName($packId), (string) ($payload['name'] ?? $ver));

        $buildId = LoaderMap::pickBuildId(($this->builds)(LoaderMap::mcjarsType($pack->loader), $pack->minecraft), $pack->loaderVersion, $pack->minecraft);

        return new ModpackPlan($pack, $buildId, null, 0);
    }
}
