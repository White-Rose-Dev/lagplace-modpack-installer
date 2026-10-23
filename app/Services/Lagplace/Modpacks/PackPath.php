<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

final class PackPath
{
    /** Devolve um caminho relativo limpo; qualquer coisa que possa sair da raiz do servidor lança. */
    public static function normalize(mixed $path): string
    {
        if (!is_string($path) || $path === '') {
            throw new ModpackException('The pack contains an empty file path.');
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1 || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new ModpackException('The pack contains an unsafe file path: ' . self::printable($path));
        }

        $clean = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new ModpackException('The pack contains an unsafe file path: ' . self::printable($path));
            }
            $clean[] = $segment;
        }
        if ($clean === []) {
            throw new ModpackException('The pack contains an empty file path.');
        }

        return implode('/', $clean);
    }

    private static function printable(string $path): string
    {
        return json_encode(mb_substr($path, 0, 80), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '?';
    }
}
