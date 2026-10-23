<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * O Wings não segue redirects, por isso resolvemos aqui o URL final de um download (ex.: github.com →
 * *.githubusercontent.com). Cada salto é revalidado contra a lista permitida; nunca se faz um pedido a um
 * host que não esteja nela, e o corpo da resposta nunca é lido.
 */
final class RedirectResolver
{
    public function __construct(private Http $http, private int $maxHops = 3)
    {
    }

    public function __invoke(string $url): string
    {
        return $this->resolve($url);
    }

    public function resolve(string $url): string
    {
        $current = $url;
        for ($hop = 0; $hop <= $this->maxHops; $hop++) {
            if (!MrpackIndex::isAllowedFinalUrl($current)) {
                throw new ModpackException('A download source redirects somewhere that is not allowed.');
            }

            $res = $this->request('HEAD', $current);
            if (in_array($res->status(), [403, 405, 501], true)) {
                $res = $this->request('GET', $current);
            }

            $status = $res->status();
            if ($status >= 200 && $status < 300) {
                return $current;
            }
            if ($status >= 300 && $status < 400) {
                $current = $this->absolute($res->header('Location'), $current);

                continue;
            }

            throw new ModpackException("A download source answered with HTTP $status.");
        }

        throw new ModpackException('Too many redirects while resolving a download.');
    }

    private function request(string $method, string $url): \Illuminate\Http\Client\Response
    {
        $req = $this->http->withHeaders(['User-Agent' => ModrinthClient::USER_AGENT])->withoutRedirecting()->timeout(15);
        try {
            return $method === 'HEAD'
                ? $req->head($url)
                : $req->withHeaders(['Range' => 'bytes=0-0'])->withOptions(['stream' => true])->get($url);
        } catch (ConnectionException) {
            throw new ModpackException('A download source could not be reached.');
        }
    }

    private function absolute(string $location, string $current): string
    {
        if ($location === '') {
            throw new ModpackException('A download source sent an empty redirect.');
        }
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $p = parse_url($current);

            return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . $location;
        }

        throw new ModpackException('A download source sent an invalid redirect.');
    }
}
