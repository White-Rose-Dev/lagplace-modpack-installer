<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/**
 * Fornecedores de modpacks que o seletor da interface mostra. Só os marcados `enabled` têm instalação implementada;
 * os outros aparecem desativados (para acrescentar um: implementar o cliente + o plano e pôr `enabled` a true).
 */
final class ModpackProviders
{
    public const DEFAULT = 'modrinth';

    /** @return list<array{id:string,name:string,enabled:bool}> */
    public static function all(): array
    {
        return [
            ['id' => 'modrinth', 'name' => 'Modrinth', 'enabled' => true],
            ['id' => 'curseforge', 'name' => 'CurseForge', 'enabled' => false],
            ['id' => 'ftb', 'name' => 'Feed The Beast', 'enabled' => true],
            ['id' => 'atlauncher', 'name' => 'ATLauncher', 'enabled' => false],
            ['id' => 'technic', 'name' => 'Technic', 'enabled' => false],
        ];
    }

    /** Devolve o id se existir e estiver ativo; lança com uma mensagem clara caso contrário. */
    public static function require(?string $id): string
    {
        $id = $id === null || $id === '' ? self::DEFAULT : $id;
        foreach (self::all() as $provider) {
            if ($provider['id'] === $id) {
                if (!$provider['enabled']) {
                    throw new ModpackException('That modpack provider is not available yet.');
                }

                return $id;
            }
        }

        throw new ModpackException('Unknown modpack provider.');
    }
}
