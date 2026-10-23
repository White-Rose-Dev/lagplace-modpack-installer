<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

/** Inicia o backup e espera (com polling) que o Wings o conclua; respeita o cancelamento e um tempo máximo. */
final class PollingBackupRunner implements BackupRunner
{
    /** @var callable(int):void */
    private $sleep;
    /** @var callable():int */
    private $clock;

    public function __construct(
        private BackupGateway $gateway,
        private ModpackState $state,
        private int $serverId,
        callable $sleep,
        callable $clock,
        private int $timeoutSeconds = 1800,
        private int $intervalSeconds = 5,
    ) {
        $this->sleep = $sleep;
        $this->clock = $clock;
    }

    public function runAndWait(): string
    {
        try {
            $uuid = $this->gateway->start();
        } catch (ModpackException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ModpackException('Could not create the backup that is taken before installing.', 0, $e);
        }

        $deadline = ($this->clock)() + $this->timeoutSeconds;
        while (true) {
            if ($this->state->cancelRequested($this->serverId)) {
                throw new ModpackCancelled('Cancelled by the user.');
            }

            $status = $this->gateway->status($uuid);
            if ($status['done']) {
                if (!$status['ok']) {
                    throw new ModpackException('The backup taken before installing failed, so nothing was changed.');
                }

                return $uuid;
            }
            if (($this->clock)() >= $deadline) {
                throw new ModpackException('The backup is taking too long, so the installation was stopped before changing anything.');
            }

            ($this->sleep)($this->intervalSeconds);
        }
    }
}
