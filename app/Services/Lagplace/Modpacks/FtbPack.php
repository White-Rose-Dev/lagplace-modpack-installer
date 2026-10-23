<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/**
 * Converte a versão de um pack do Feed The Beast (API modpacks.ch) num ParsedPack seguro. A resposta é input não confiável:
 * caminhos normalizados, domínios permitidos, tipos de ficheiro conhecidos e limites de tamanho.
 */
final class FtbPack
{
    public const ALLOWED_HOSTS = ['dist.modpacks.ch', 'edge.forgecdn.net', 'mediafilez.forgecdn.net'];

    private const FILE_TYPES = ['mod', 'config', 'resource', 'script'];
    private const LOADERS = ['forge', 'neoforge', 'fabric', 'quilt'];
    private const VERSION_RE = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/';

    /** @param array{maxFiles?:int,maxBytes?:int} $limits */
    public static function parse(array $payload, string $packName, string $versionName, array $limits = []): ParsedPack
    {
        $maxFiles = $limits['maxFiles'] ?? MrpackIndex::MAX_FILES;
        $maxBytes = $limits['maxBytes'] ?? MrpackIndex::MAX_BYTES;

        if (($payload['status'] ?? null) !== 'success' || !isset($payload['files'], $payload['targets']) || !is_array($payload['files']) || !is_array($payload['targets'])) {
            throw new ModpackException('The modpack provider returned an unexpected answer for this version.');
        }

        [$minecraft, $loader, $loaderVersion] = self::targets($payload['targets']);

        $files = [];
        $seen = [];
        $total = 0;
        foreach ($payload['files'] as $raw) {
            if (!is_array($raw)) {
                throw new ModpackException('The pack has a malformed file entry.');
            }
            if (!empty($raw['clientonly'])) {
                continue;
            }
            $type = $raw['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::FILE_TYPES, true)) {
                throw new ModpackException('The pack contains a file type that cannot be installed automatically.');
            }

            $dir = trim((string) ($raw['path'] ?? ''));
            $name = (string) ($raw['name'] ?? '');
            if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
                throw new ModpackException('The pack contains a file with an invalid name.');
            }
            $path = PackPath::normalize(($dir === '' || $dir === '.' || $dir === './') ? $name : rtrim($dir, '/') . '/' . $name);
            if (isset($seen[$path])) {
                throw new ModpackException("The pack lists the same file twice: $path");
            }
            $seen[$path] = true;

            $url = $raw['url'] ?? null;
            if (!is_string($url) || !self::isAllowedUrl($url)) {
                throw new ModpackException("No allowed download source for $path (allowed: " . implode(', ', self::ALLOWED_HOSTS) . ').');
            }
            $size = $raw['size'] ?? null;
            if (!is_int($size) || $size < 0) {
                throw new ModpackException("The pack gives an invalid size for $path");
            }

            $total += $size;
            if (count($files) + 1 > $maxFiles || $total > $maxBytes) {
                throw new ModpackException('The pack is too large to install on a server here.');
            }

            $files[] = new PackFile($path, [$url], $size, isset($raw['sha1']) && is_string($raw['sha1']) ? $raw['sha1'] : null, null);
        }

        return new ParsedPack(mb_substr($packName, 0, 80) ?: 'modpack', mb_substr($versionName, 0, 64), $minecraft, $loader, $loaderVersion, $files, $total);
    }

    /** @return array{0:string,1:string,2:string} minecraft, loader, versão do loader */
    public static function targets(array $targets): array
    {
        $minecraft = $loader = $loaderVersion = null;
        foreach ($targets as $t) {
            if (!is_array($t)) {
                continue;
            }
            $type = $t['type'] ?? null;
            $name = strtolower((string) ($t['name'] ?? ''));
            $version = (string) ($t['version'] ?? '');
            if ($type === 'game' && $name === 'minecraft') {
                $minecraft = $version;
            } elseif ($type === 'modloader') {
                if (!in_array($name, self::LOADERS, true)) {
                    throw new ModpackException('This pack uses a mod loader that is not supported (' . mb_substr($name, 0, 20) . ').');
                }
                $loader = $name;
                $loaderVersion = $version;
            }
        }

        foreach ([$minecraft, $loaderVersion] as $v) {
            if ($v === null || preg_match(self::VERSION_RE, $v) !== 1) {
                throw new ModpackException('The pack does not declare a valid Minecraft and mod loader version.');
            }
        }
        if ($loader === null) {
            throw new ModpackException('The pack does not declare a mod loader.');
        }

        return [$minecraft, $loader, $loaderVersion];
    }

    public static function isAllowedUrl(string $url): bool
    {
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return false;
        }

        return in_array(strtolower($p['host'] ?? ''), self::ALLOWED_HOSTS, true);
    }
}
