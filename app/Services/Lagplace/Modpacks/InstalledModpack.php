<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/** Lê o `.modpack/manifest.json` que o instalador escreve. O dono do servidor pode editá-lo, por isso só sai o que for texto curto e válido. */
final class InstalledModpack
{
    public const PATH = '.modpack/manifest.json';
    public const MAX_BYTES = 1048576;

    /** @return array{pack:string,minecraft:?string,loader:?string,installed_at:?string}|null */
    public static function parse(string $json): ?array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['pack']) || !is_string($d['pack']) || trim($d['pack']) === '') {
            return null;
        }

        $text = fn (mixed $v, string $pattern): ?string => is_string($v) && preg_match($pattern, $v) ? $v : null;
        $at = $text($d['installed_at'] ?? null, '/^\d{4}-\d{2}-\d{2}T[0-9:.+Z-]{1,30}$/');

        return [
            'pack' => mb_substr(trim($d['pack']), 0, 100),
            'minecraft' => $text($d['minecraft'] ?? null, '/^[A-Za-z0-9._-]{1,20}$/'),
            'loader' => $text($d['loader'] ?? null, '/^(forge|neoforge|fabric|quilt)$/'),
            'installed_at' => $at !== null && strtotime($at) !== false ? $at : null,
        ];
    }
}
