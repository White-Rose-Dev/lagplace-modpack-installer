<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/** Registo dos fornecedores implementados, por id (os ids são os de ModpackProviders). */
final class ModpackSources
{
    /** @param array<string, ModpackSource> $sources */
    public function __construct(private array $sources)
    {
    }

    public function get(string $provider): ModpackSource
    {
        // ModpackProviders::require() já recusou ids desconhecidos ou desativados; isto cobre um fornecedor ativo sem implementação.
        return $this->sources[$provider] ?? throw new ModpackException('That modpack provider is not available yet.');
    }
}
