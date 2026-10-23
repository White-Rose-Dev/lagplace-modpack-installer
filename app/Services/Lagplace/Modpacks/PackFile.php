<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

final class PackFile
{
    /** @param list<string> $urls */
    public function __construct(
        public readonly string $path,
        public readonly array $urls,
        public readonly int $size,
        public readonly ?string $sha1,
        public readonly ?string $sha512,
    ) {
    }
}
