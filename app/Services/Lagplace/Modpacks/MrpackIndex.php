<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/**
 * Valida o modrinth.index.json de um .mrpack. O índice é input não confiável: um ParsedPack devolvido
 * tem sempre caminhos seguros, URLs na lista permitida e dentro dos limites.
 */
final class MrpackIndex
{
    /** Limites por omissão (os packs grandes do FTB têm 1000–1500 ficheiros). */
    public const MAX_FILES = 3000;
    public const MAX_BYTES = 4294967296;

    public const ALLOWED_HOSTS = ['cdn.modrinth.com', 'github.com', 'raw.githubusercontent.com', 'gitlab.com'];

    /** Destinos finais legítimos dos redirects de github.com (o painel resolve o redirect porque o Wings não os segue). */
    public const REDIRECT_TARGET_HOSTS = ['objects.githubusercontent.com', 'release-assets.githubusercontent.com', 'github-releases.githubusercontent.com', 'mediafilez.forgecdn.net'];

    private const LOADER_KEYS = ['forge' => 'forge', 'neoforge' => 'neoforge', 'fabric-loader' => 'fabric', 'quilt-loader' => 'quilt'];
    private const VERSION_RE = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/';

    /** @param array{maxFiles?:int,maxBytes?:int} $limits */
    public static function parse(array $index, array $limits = []): ParsedPack
    {
        $maxFiles = $limits['maxFiles'] ?? self::MAX_FILES;
        $maxBytes = $limits['maxBytes'] ?? self::MAX_BYTES;

        if (($index['formatVersion'] ?? null) !== 1) {
            throw new ModpackException('Unsupported modpack format (only .mrpack format 1 is supported).');
        }
        if (($index['game'] ?? null) !== 'minecraft') {
            throw new ModpackException('This pack is not a Minecraft modpack.');
        }

        $deps = $index['dependencies'] ?? null;
        if (!is_array($deps)) {
            throw new ModpackException('The pack does not declare its dependencies.');
        }
        $minecraft = $deps['minecraft'] ?? null;
        if (!is_string($minecraft) || preg_match(self::VERSION_RE, $minecraft) !== 1) {
            throw new ModpackException('The pack does not declare a valid Minecraft version.');
        }

        $found = array_values(array_filter(array_keys(self::LOADER_KEYS), fn ($k) => isset($deps[$k])));
        if (count($found) !== 1) {
            throw new ModpackException($found === [] ? 'The pack does not declare a mod loader.' : 'The pack declares more than one mod loader.');
        }
        $loaderVersion = $deps[$found[0]];
        if (!is_string($loaderVersion) || preg_match(self::VERSION_RE, $loaderVersion) !== 1) {
            throw new ModpackException('The pack declares an invalid loader version.');
        }

        if (!isset($index['files']) || !is_array($index['files'])) {
            throw new ModpackException('The pack has no file list.');
        }

        $files = [];
        $seen = [];
        $total = 0;
        foreach ($index['files'] as $raw) {
            if (!is_array($raw)) {
                throw new ModpackException('The pack has a malformed file entry.');
            }
            if (($raw['env']['server'] ?? null) === 'unsupported') {
                continue;
            }

            $path = PackPath::normalize($raw['path'] ?? null);
            if (isset($seen[$path])) {
                throw new ModpackException("The pack lists the same file twice: $path");
            }
            $seen[$path] = true;

            $size = $raw['fileSize'] ?? null;
            if (!is_int($size) || $size < 0) {
                throw new ModpackException("The pack gives an invalid size for $path");
            }

            $urls = array_values(array_filter(is_array($raw['downloads'] ?? null) ? $raw['downloads'] : [], fn ($u) => is_string($u) && self::isAllowedUrl($u)));
            if ($urls === []) {
                throw new ModpackException("No allowed download source for $path (allowed: " . implode(', ', self::ALLOWED_HOSTS) . ').');
            }

            $total += $size;
            if (count($files) + 1 > $maxFiles || $total > $maxBytes) {
                throw new ModpackException('The pack is too large to install on a server here.');
            }

            $hashes = is_array($raw['hashes'] ?? null) ? $raw['hashes'] : [];
            $files[] = new PackFile(
                $path,
                $urls,
                $size,
                isset($hashes['sha1']) && is_string($hashes['sha1']) ? $hashes['sha1'] : null,
                isset($hashes['sha512']) && is_string($hashes['sha512']) ? $hashes['sha512'] : null,
            );
        }

        $name = isset($index['name']) && is_string($index['name']) && $index['name'] !== '' ? mb_substr($index['name'], 0, 80) : 'modpack';
        $versionId = isset($index['versionId']) && is_string($index['versionId']) ? mb_substr($index['versionId'], 0, 64) : '';

        return new ParsedPack($name, $versionId, $minecraft, self::LOADER_KEYS[$found[0]], $loaderVersion, $files, $total);
    }

    /** Como isAllowedUrl, mas também aceita os destinos finais conhecidos de um redirect. */
    public static function isAllowedFinalUrl(string $url): bool
    {
        if (self::isAllowedUrl($url)) {
            return true;
        }
        $p = parse_url($url);

        if ($p === false || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return false;
        }

        return in_array(strtolower($p['host'] ?? ''), self::REDIRECT_TARGET_HOSTS, true);
    }

    public static function isAllowedUrl(string $url): bool
    {
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return false;
        }

        return in_array(strtolower($p['host'] ?? ''), self::ALLOWED_HOSTS, true);
    }
}
