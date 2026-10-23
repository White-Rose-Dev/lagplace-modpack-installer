<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Jobs\Lagplace\ModpackInstallJob;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackException;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackFeature;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackInstaller;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackProviders;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackSources;
use Pterodactyl\Services\Lagplace\Modpacks\ModpackState;

/**
 * Aba Modpacks: pesquisa no Modrinth e instalação de um modpack como servidor completo.
 * As rotas já passam por RequireServerPermission:minecraft.versions + RequireAdvancedAccess; instalar e cancelar
 * exigem ainda ser o dono ou root_admin (substitui todos os ficheiros), como a aba Versions.
 */
class ModpacksController extends Controller
{
    private const UNIVERSAL_EGG_NAME = 'Universal Proxy Ready';
    private const PER_PAGE = 20;

    public function __construct(
        private ModpackSources $sources,
        private ModpackState $state,
        private ModpackFeature $feature,
    ) {
    }

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->ensureEnabled($request);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'provider' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->guard(function () use ($data) {
            $provider = ModpackProviders::require($data['provider'] ?? null);
            $page = (int) ($data['page'] ?? 1);

            return new JsonResponse(
                ['provider' => $provider, 'providers' => ModpackProviders::all(), 'page' => $page, 'per_page' => self::PER_PAGE]
                + $this->sources->get($provider)->search($data['q'] ?? null, $page, self::PER_PAGE)
            );
        });
    }

    public function versions(Request $request, Server $server, string $project): JsonResponse
    {
        $this->ensureEnabled($request);
        $data = $request->validate(['provider' => ['nullable', 'string', 'max:20']]);

        return $this->guard(function () use ($data, $project) {
            $provider = ModpackProviders::require($data['provider'] ?? null);

            return new JsonResponse(['versions' => $this->sources->get($provider)->versions($project)]);
        });
    }

    public function install(Request $request, Server $server): JsonResponse
    {
        $this->ensureEnabled($request);
        $this->ensureOwnerOrAdmin($request, $server);
        $data = $request->validate([
            'version_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            'provider' => ['nullable', 'string', 'max:20'],
            // A instalação escreve eula.txt=true: só avança se o utilizador aceitou o EULA da Mojang.
            'accept_eula' => ['required', 'accepted'],
        ]);

        return $this->guard(function () use ($server, $data) {
            $provider = ModpackProviders::require($data['provider'] ?? null);
            if (($server->egg->name ?? '') !== self::UNIVERSAL_EGG_NAME) {
                throw new ModpackException('Modpacks can only be installed on servers that use the universal server type.');
            }
            if ((int) $server->backup_limit < 1) {
                throw new ModpackException('This server cannot create backups, and a backup is taken before the files are replaced. Ask an admin to allow at least one backup.');
            }

            $plan = $this->sources->get($provider)->plan($data['version_id']);
            ModpackInstaller::checkDisk($plan->pack->totalBytes, $plan->mrpackSize, (int) $server->disk);

            if (!$this->state->begin($server->id, $plan->pack->name)) {
                return new JsonResponse(['message' => 'A modpack installation is already running on this server.', 'state' => $this->state->view($server->id)], 409);
            }
            ModpackInstallJob::dispatch($server->id, $data['version_id'], $provider);

            return new JsonResponse(['state' => $this->state->view($server->id)], 202);
        });
    }

    public function status(Request $request, Server $server): JsonResponse
    {
        $this->ensureEnabled($request);

        return new JsonResponse(['state' => $this->state->view($server->id)]);
    }

    public function cancel(Request $request, Server $server): JsonResponse
    {
        $this->ensureEnabled($request);
        $this->ensureOwnerOrAdmin($request, $server);

        $state = $this->state->view($server->id);
        if ($state === null || in_array($state['stage'], ['done', 'failed', 'cancelled'], true)) {
            return new JsonResponse(['message' => 'There is no installation to cancel.'], 409);
        }
        $this->state->requestCancel($server->id);

        return new JsonResponse(['state' => $this->state->view($server->id)], 202);
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (ModpackException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }
    }

    /** Desligado = 404 para toda a gente exceto admins (que o ligam e testam primeiro). */
    private function ensureEnabled(Request $request): void
    {
        if (!$request->user()->root_admin && !$this->feature->enabled()) {
            abort(404);
        }
    }

    private function ensureOwnerOrAdmin(Request $request, Server $server): void
    {
        $user = $request->user();
        if (!$user->root_admin && $user->id !== $server->owner_id) {
            abort(403);
        }
    }
}
