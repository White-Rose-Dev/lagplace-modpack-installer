<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/**
 * Tudo o que se pode validar sem tocar no servidor: resolve a versão no Modrinth, lê o índice do .mrpack
 * (só esse ficheiro), valida-o e encontra a build exata do loader no mcjars. É usado pelo controller (para
 * recusar logo) e pelo job (para instalar), por isso nunca há divergência entre os dois.
 */
final class ModpackPlanner
{
    /**
     * @param callable(string):string $readIndex       url do .mrpack → conteúdo do modrinth.index.json
     * @param callable(string,string):array $builds    (tipo mcjars, versão do Minecraft) → builds, como McJarsService::builds()
     */
    public function __construct(private ModrinthClient $client, private $readIndex, private $builds)
    {
    }

    public function plan(string $versionId): ModpackPlan
    {
        $resolved = $this->client->resolve($versionId);

        $data = json_decode(($this->readIndex)($resolved['mrpackUrl']), true);
        if (!is_array($data)) {
            throw new ModpackException('The modpack index is not valid.');
        }
        $pack = MrpackIndex::parse($data);

        $builds = ($this->builds)(LoaderMap::mcjarsType($pack->loader), $pack->minecraft);
        $buildId = LoaderMap::pickBuildId($builds, $pack->loaderVersion, $pack->minecraft);

        return new ModpackPlan($pack, $buildId, $resolved['mrpackUrl'], $resolved['size']);
    }
}
