<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

final class ModpackPlan
{
    /** $mrpackUrl é null nos fornecedores sem arquivo do pack (ex.: Feed The Beast: a lista de ficheiros já traz tudo). */
    public function __construct(
        public readonly ParsedPack $pack,
        public readonly int $loaderBuildId,
        public readonly ?string $mrpackUrl,
        public readonly int $mrpackSize,
    ) {
    }
}
