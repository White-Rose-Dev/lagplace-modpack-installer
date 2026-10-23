<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

final class ModrinthSource implements ModpackSource
{
    public function __construct(private ModrinthClient $client, private ModpackPlanner $planner)
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

    public function plan(string $versionId): ModpackPlan
    {
        return $this->planner->plan($versionId);
    }
}
