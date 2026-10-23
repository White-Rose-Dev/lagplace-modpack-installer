<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

/** Interruptor global do instalador de modpacks (setting lagplace::modpacks_enabled, desligado por omissão). */
final class ModpackFeature
{
    private const KEY = 'lagplace::modpacks_enabled';

    /** @var callable(string,mixed):mixed */
    private $get;
    /** @var callable(string,string):void */
    private $set;

    public function __construct(callable $get, callable $set)
    {
        $this->get = $get;
        $this->set = $set;
    }

    public static function fromSettings(SettingsRepositoryInterface $settings): self
    {
        return new self(fn (string $k, mixed $d) => $settings->get($k, $d), fn (string $k, string $v) => $settings->set($k, $v));
    }

    public function enabled(): bool
    {
        return in_array(($this->get)(self::KEY, '0'), ['1', 'true', 1, true], true);
    }

    public function set(bool $on): void
    {
        ($this->set)(self::KEY, $on ? '1' : '0');
    }
}
