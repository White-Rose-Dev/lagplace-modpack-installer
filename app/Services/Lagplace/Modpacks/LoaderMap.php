<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

final class LoaderMap
{
    private const TYPES = ['forge' => 'FORGE', 'neoforge' => 'NEOFORGE', 'fabric' => 'FABRIC', 'quilt' => 'QUILT'];

    public static function mcjarsType(string $loader): string
    {
        $type = self::TYPES[strtolower($loader)] ?? null;
        if ($type === null) {
            throw new ModpackException("Unsupported mod loader: $loader");
        }

        return $type;
    }

    /**
     * Procura a build do mcjars cujo projectVersionId é exatamente a versão do loader pedida pelo pack.
     * Nunca aproxima: um modpack depende de uma versão exata do loader.
     *
     * @param array<int, array<string, mixed>> $builds formato de McJarsService::builds()
     */
    public static function pickBuildId(array $builds, string $loaderVersion, string $minecraft): int
    {
        $wanted = [$loaderVersion];
        if (str_starts_with($loaderVersion, $minecraft . '-')) {
            $wanted[] = substr($loaderVersion, strlen($minecraft) + 1);
        }

        foreach ($builds as $build) {
            if (!is_array($build) || !isset($build['id']) || !is_numeric($build['id'])) {
                continue;
            }
            if (in_array((string) ($build['projectVersionId'] ?? ''), $wanted, true)) {
                return (int) $build['id'];
            }
        }

        throw new ModpackException("Loader version $loaderVersion is not available for Minecraft $minecraft, so this pack cannot be installed here.");
    }
}
