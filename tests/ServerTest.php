<?php

declare(strict_types=1);

namespace Cairo\Symfony\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Serves the fixture app (tests/fixture, set up by tests/fixture/setup.sh)
 * with `cairo serve public/index.php` (CAIRO_BINARY), through its own front
 * controller, and talks to it over HTTP.
 */
final class ServerTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixture';

    private ?Process $server = null;
    private string $base = '';

    public function testTheFrontControllerIsServed(): void
    {
        $this->start([]);
        self::assertSame([200, "Hello cairo from Symfony\n"], $this->get('/hello/cairo'));
        self::assertSame([200, "User-agent: *\nDisallow:\n"], $this->get('/robots.txt'), 'public/ is the document root, served by Go');
        self::assertSame(404, $this->get('/no-such-route')[0], "an unknown route gets Symfony's 404");
        $h = curl_init($this->base . '/form');
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => 'name=Ada', CURLOPT_USERAGENT => 'test-agent']);
        self::assertSame(['name' => 'Ada', 'agent' => 'test-agent'], json_decode((string) curl_exec($h), true));
    }

    /**
     * In fiber mode each request in flight on a thread has a kernel of its
     * own: the request state in a kernel's services is not another request's.
     */
    public function testFiberModeGivesEachRequestItsKernel(): void
    {
        $this->start(['concurrency' => 'fibers', 'threads' => 1]);
        $this->get('/hello/warm');

        $n = 20;
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < $n; $i++) {
            $handles[$i] = curl_init("{$this->base}/visit/v{$i}?wait=500000");
            curl_setopt($handles[$i], CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($multi, $handles[$i]);
        }
        $start = microtime(true);
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);
        $elapsed = microtime(true) - $start;

        $kernels = [];
        foreach ($handles as $i => $h) {
            $visit = json_decode((string) curl_multi_getcontent($h), true);
            self::assertSame("v{$i}", $visit['visitor'] ?? null, "request {$i} saw another request's kernel");
            $kernels[$visit['kernel']] = true;
        }
        self::assertCount($n, $kernels, 'the requests in flight together had a kernel each');
        // Each waits 0.5 s, on one PHP thread: together, not one after another.
        self::assertLessThan(2.0, $elapsed);
    }

    /**
     * The front controller ends the thread's script with exit() once the
     * runner returns: a recycled thread is still a clean end.
     */
    public function testThreadsAreRecycled(): void
    {
        $this->start(['threads' => 1, 'max_requests' => 3]);
        for ($i = 0; $i < 12; $i++) {
            self::assertSame([200, "Hello r{$i} from Symfony\n"], $this->get("/hello/r{$i}"));
        }
        $deadline = microtime(true) + 10;
        while (!str_contains($this->server->getErrorOutput(), 'php thread recycled')) {
            if (microtime(true) > $deadline) {
                self::fail("no thread was recycled:\n" . $this->server->getErrorOutput());
            }
            usleep(50_000);
        }
        self::assertStringNotContainsString('stopped unexpectedly', $this->server->getErrorOutput());
    }

    public function testFiberModeRefusesAnAppWithSessions(): void
    {
        $server = $this->process(['concurrency' => 'fibers', 'threads' => 1], ['TEST_SESSIONS' => '1']);
        $server->run();
        self::assertNotSame(0, $server->getExitCode());
        self::assertStringContainsString('cannot serve an app with sessions', $server->getErrorOutput());
    }

    protected function tearDown(): void
    {
        $this->server?->stop(10, \SIGINT);
        $this->server = null;
    }

    /** @param array<string, mixed> $options the runtime's, without listen */
    private function start(array $options): void
    {
        $this->server = $this->process($options);
        $this->server->start();
        $deadline = microtime(true) + 30;
        while (@file_get_contents($this->base . '/hello/up') !== "Hello up from Symfony\n") {
            if (microtime(true) > $deadline || !$this->server->isRunning()) {
                self::fail("the server did not start:\n" . $this->server->getOutput() . $this->server->getErrorOutput());
            }
            usleep(100_000);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $env
     */
    private function process(array $options, array $env = []): Process
    {
        $binary = getenv('CAIRO_BINARY');
        if (!$binary) {
            self::markTestSkipped('set CAIRO_BINARY to a cairo binary');
        }
        if (!is_file(self::FIXTURE . '/vendor/autoload_runtime.php')) {
            self::markTestSkipped('run tests/fixture/setup.sh first');
        }
        $port = self::freePort();
        $this->base = "http://127.0.0.1:{$port}";
        $options = ['listen' => "127.0.0.1:{$port}"] + $options;
        $env['APP_RUNTIME_OPTIONS'] = json_encode($options, JSON_THROW_ON_ERROR);
        return new Process([$binary, 'serve', 'public/index.php', '--offload-threads', '1'], self::FIXTURE, $env, null, 60);
    }

    /** @return array{0: int, 1: string} status and body */
    private function get(string $path): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        $body = (string) curl_exec($h);
        return [(int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $body];
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }
}
