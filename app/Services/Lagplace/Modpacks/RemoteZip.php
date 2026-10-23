<?php

namespace Pterodactyl\Services\Lagplace\Modpacks;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Lê uma única entrada de um zip remoto (o modrinth.index.json de um .mrpack) com pedidos HTTP por
 * intervalo, sem descarregar o arquivo: só o fim (EOCD), o diretório central e a própria entrada.
 */
final class RemoteZip
{
    private const MAX_DIRECTORY = 8388608;

    /** @param callable(string,int,int):array{body:string,total:int} $fetchRange intervalo inclusivo + tamanho total do arquivo */
    public function __construct(private $fetchRange)
    {
    }

    public function readEntry(string $url, string $name, int $maxBytes = 5242880): string
    {
        $total = $this->range($url, 0, 0, 1)['total'];
        if ($total < 22) {
            throw $this->bad();
        }

        $tailLen = min($total, 65557);
        $tail = $this->range($url, $total - $tailLen, $total - 1, $tailLen)['body'];
        $eocd = $this->findEocd($tail);
        $e = unpack('vdisk/vcdDisk/vcount/vtotalCount/VcdSize/VcdOffset/vcommentLen', $tail, $eocd + 4);
        if ($e['totalCount'] === 0xFFFF || $e['cdSize'] === 0xFFFFFFFF || $e['cdOffset'] === 0xFFFFFFFF) {
            throw new ModpackException('ZIP64 modpack files are not supported.');
        }
        if ($e['cdSize'] === 0 || $e['cdSize'] > self::MAX_DIRECTORY || $e['cdOffset'] + $e['cdSize'] > $total) {
            throw $this->bad();
        }

        $cd = $this->range($url, $e['cdOffset'], $e['cdOffset'] + $e['cdSize'] - 1, $e['cdSize'])['body'];
        $entry = $this->findEntry($cd, $name);
        if ($entry === null) {
            throw new ModpackException("The modpack file does not contain $name.");
        }
        if (($entry['flags'] & 0x1) !== 0 || ($entry['method'] !== 0 && $entry['method'] !== 8)) {
            throw new ModpackException('The modpack file uses an unsupported compression or encryption.');
        }
        if ($entry['comp'] === 0xFFFFFFFF || $entry['uncomp'] === 0xFFFFFFFF) {
            throw new ModpackException('ZIP64 modpack files are not supported.');
        }
        if ($entry['uncomp'] > $maxBytes) {
            throw new ModpackException("$name is unexpectedly large.");
        }
        if ($entry['lho'] + 30 > $total) {
            throw $this->bad();
        }

        $local = $this->range($url, $entry['lho'], $entry['lho'] + 29, 30)['body'];
        if (substr($local, 0, 4) !== "PK\x03\x04") {
            throw $this->bad();
        }
        $l = unpack('vnameLen/vextraLen', $local, 26);
        $start = $entry['lho'] + 30 + $l['nameLen'] + $l['extraLen'];
        if ($start + $entry['comp'] > $total) {
            throw $this->bad();
        }

        $data = $entry['comp'] === 0 ? '' : $this->range($url, $start, $start + $entry['comp'] - 1, $entry['comp'])['body'];
        $out = $entry['method'] === 0 ? $data : @gzinflate($data, $maxBytes + 1);
        if (!is_string($out) || strlen($out) !== $entry['uncomp'] || crc32($out) !== $entry['crc']) {
            throw new ModpackException("$name inside the modpack file is corrupted.");
        }

        return $out;
    }

    /** Fetcher de produção: Laravel Http com Range, só hosts permitidos, sem redirects, leitura limitada. */
    public static function httpFetcher(?Http $http = null): callable
    {
        return function (string $url, int $start, int $end) use ($http): array {
            if (!MrpackIndex::isAllowedUrl($url)) {
                throw new ModpackException('The modpack file is hosted somewhere that is not allowed.');
            }
            $factory = $http ?? app(Http::class);
            try {
                $res = $factory->withHeaders(['User-Agent' => ModrinthClient::USER_AGENT, 'Range' => "bytes=$start-$end"])
                    ->withoutRedirecting()->withOptions(['stream' => true])->timeout(30)->get($url);
            } catch (ConnectionException) {
                throw new ModpackException('The modpack file could not be reached.');
            }
            if ($res->status() !== 206 || !preg_match('#/(\d+)$#', $res->header('Content-Range'), $m)) {
                throw new ModpackException('The modpack host does not support partial downloads.');
            }

            $want = $end - $start + 1;
            $stream = $res->toPsrResponse()->getBody();
            $body = '';
            while (strlen($body) < $want && !$stream->eof()) {
                $body .= $stream->read($want - strlen($body));
            }

            return ['body' => $body, 'total' => (int) $m[1]];
        };
    }

    /** @return array{body:string,total:int} */
    private function range(string $url, int $start, int $end, int $expected): array
    {
        $r = ($this->fetchRange)($url, $start, $end);
        if (!is_array($r) || !isset($r['body'], $r['total']) || !is_string($r['body']) || strlen($r['body']) !== $expected) {
            throw $this->bad();
        }

        return ['body' => $r['body'], 'total' => (int) $r['total']];
    }

    private function findEocd(string $tail): int
    {
        $pos = strlen($tail) - 22;
        for (; $pos >= 0; $pos--) {
            if (substr($tail, $pos, 4) !== "PK\x05\x06") {
                continue;
            }
            $commentLen = unpack('v', $tail, $pos + 20)[1];
            if ($pos + 22 + $commentLen === strlen($tail)) {
                return $pos;
            }
        }

        throw $this->bad();
    }

    /** @return array{flags:int,method:int,crc:int,comp:int,uncomp:int,lho:int}|null */
    private function findEntry(string $cd, string $name): ?array
    {
        $off = 0;
        $len = strlen($cd);
        while ($off + 46 <= $len) {
            if (substr($cd, $off, 4) !== "PK\x01\x02") {
                throw $this->bad();
            }
            $h = unpack('vflags/vmethod', $cd, $off + 8);
            $c = unpack('Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen/vcommentLen', $cd, $off + 16);
            $lho = unpack('V', $cd, $off + 42)[1];
            if ($off + 46 + $c['nameLen'] > $len) {
                throw $this->bad();
            }
            if (substr($cd, $off + 46, $c['nameLen']) === $name) {
                return ['flags' => $h['flags'], 'method' => $h['method'], 'crc' => $c['crc'], 'comp' => $c['comp'], 'uncomp' => $c['uncomp'], 'lho' => $lho];
            }
            $off += 46 + $c['nameLen'] + $c['extraLen'] + $c['commentLen'];
        }

        return null;
    }

    private function bad(): ModpackException
    {
        return new ModpackException('The modpack file is not a valid archive or could not be read.');
    }
}
