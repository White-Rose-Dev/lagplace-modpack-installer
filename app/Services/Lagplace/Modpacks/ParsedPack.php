<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

final class ParsedPack
{
    /** @param list<PackFile> $files  $loader: forge|neoforge|fabric|quilt */
    public function __construct(
        public readonly string $name,
        public readonly string $versionId,
        public readonly string $minecraft,
        public readonly string $loader,
        public readonly string $loaderVersion,
        public readonly array $files,
        public readonly int $totalBytes,
    ) {
    }
}
