<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Tests\Integration;

use RuntimeException;

/**
 * A listener the signing service can reach, for SPEC-043.
 *
 * SPEC-043 asserts that the service makes NO request to a URL taken from an
 * uploaded file. An absence is also what a listener the container cannot reach
 * would show, so every absence here is paired with a positive control in the
 * same run (AC3): the service's own timestamp request, pointed at this
 * listener by the profile.
 *
 * The profile declares itself with CC_REMOTE_PROBE=1; the address the
 * container uses for the host is CC_REMOTE_PROBE_HOST (default
 * host.docker.internal) and the port CC_REMOTE_PROBE_PORT (default 8765).
 */
final class RemoteProbe
{
    /** @var resource|null */
    private static $process = null;

    private static ?string $log = null;

    public static function profileActive(): bool
    {
        return getenv('CC_REMOTE_PROBE') === '1';
    }

    public static function port(): int
    {
        $port = (int) (getenv('CC_REMOTE_PROBE_PORT') ?: 8765);

        return $port > 0 ? $port : 8765;
    }

    /** The URL the CONTAINER uses to reach this listener at $path. */
    public static function url(string $path): string
    {
        $host = getenv('CC_REMOTE_PROBE_HOST') ?: 'host.docker.internal';

        return sprintf('http://%s:%d/%s', $host, self::port(), ltrim($path, '/'));
    }

    /** Starts the listener once per process; it serves $store under /manifest-…. */
    public static function start(): void
    {
        if (self::$process !== null) {
            return;
        }

        $dir = sys_get_temp_dir().'/spec043-'.getmypid();
        @mkdir($dir);
        self::$log = $dir.'/hits.log';
        file_put_contents(self::$log, '');

        $store = $dir.'/store.c2pa';
        file_put_contents($store, self::storeFromSignedPng());

        $env = ['REMOTE_PROBE_LOG' => self::$log, 'REMOTE_PROBE_STORE' => $store] + getenv();
        $process = proc_open(
            [PHP_BINARY, '-S', '0.0.0.0:'.self::port(), __DIR__.'/remote-probe-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $dir.'/server.out', 'a'], 2 => ['file', $dir.'/server.out', 'a']],
            $pipes,
            null,
            $env,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('could not start the SPEC-043 listener');
        }

        self::$process = $process;
        register_shutdown_function(static function (): void {
            if (is_resource(self::$process)) {
                proc_terminate(self::$process);
            }
        });

        // Ready when it accepts a connection on loopback. Until then every
        // attempt is refused, which PHP reports as a warning that `@` does not
        // keep from Pest; a refusal is the expected answer here, so the
        // handler swallows it for the length of the wait only.
        set_error_handler(static fn (): bool => true);

        try {
            for ($i = 0; $i < 50; $i++) {
                $socket = stream_socket_client('tcp://127.0.0.1:'.self::port(), $errno, $errstr, 1);

                if (is_resource($socket)) {
                    fclose($socket);

                    return;
                }
                usleep(100_000);
            }
        } finally {
            restore_error_handler();
        }

        throw new RuntimeException('the SPEC-043 listener did not come up on port '.self::port());
    }

    /** @return list<string> every request line received so far, METHOD PATH */
    public static function hits(): array
    {
        $raw = self::$log !== null ? @file_get_contents(self::$log) : false;

        return is_string($raw) ? array_values(array_filter(explode("\n", $raw), fn (string $l) => $l !== '')) : [];
    }

    /** Whether any received request line contains $needle. */
    public static function received(string $needle): bool
    {
        foreach (self::hits() as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** A unique path segment, so one test's request cannot satisfy another's. */
    public static function nonce(string $prefix): string
    {
        return $prefix.'-'.bin2hex(random_bytes(6));
    }

    /**
     * The unsigned JPEG fixture with one XMP segment declaring its manifest at
     * $url (`dcterms:provenance`, C2PA 2.4 §11.4). The same construction as
     * SPEC-042's committed remote-manifest fixture, with the URL chosen here.
     */
    public static function remoteOnlyJpeg(string $url): string
    {
        $jpeg = (string) file_get_contents(dirname(__DIR__).'/Fixtures/fixture.jpg');
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description rdf:about="" xmlns:dcterms="http://purl.org/dc/terms/" dcterms:provenance="'
            .htmlspecialchars($url, ENT_QUOTES | ENT_XML1).'"/></rdf:RDF></x:xmpmeta>';
        $payload = "http://ns.adobe.com/xap/1.0/\0".$xmp;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    /** The JUMBF manifest store out of the committed signed PNG (its caBX chunk). */
    private static function storeFromSignedPng(): string
    {
        $png = (string) file_get_contents(dirname(__DIR__).'/Fixtures/signed-spec042.png');
        $at = strpos($png, 'caBX');

        if ($at === false || $at < 4) {
            throw new RuntimeException('signed-spec042.png carries no caBX chunk');
        }

        $length = unpack('N', substr($png, $at - 4, 4));
        $bytes = is_array($length) && is_int($length[1] ?? null) ? $length[1] : 0;

        return substr($png, $at + 4, $bytes);
    }
}
