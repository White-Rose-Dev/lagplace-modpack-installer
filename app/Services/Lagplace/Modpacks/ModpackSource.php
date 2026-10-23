<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/** Um fornecedor de modpacks (Modrinth, Feed The Beast, …): pesquisa, versões e plano de instalação. */
interface ModpackSource
{
    /**
     * @return array{total:int, hits:list<array{id:string,slug:string,title:string,description:string,icon:?string,downloads:int,loaders:list<string>,versions:list<string>}>}
     */
    public function search(?string $query, int $page, int $perPage): array;

    /** @return list<array{id:string,name:string,versionNumber:string,gameVersions:array,loaders:array,datePublished:string}> */
    public function versions(string $projectId): array;

    /** Valida tudo o que se pode validar sem tocar no servidor. Lança ModpackException com uma mensagem para o utilizador. */
    public function plan(string $versionId): ModpackPlan;
}
