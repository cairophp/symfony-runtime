<?php

declare(strict_types=1);

namespace Cairo\Symfony;

use Cairo\App;
use Cairo\Context;
use Cairo\Http\Adapter;
use Cairo\Http\Request;
use Cairo\Http\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Declares the HTTP server for the kernel and serves it on this PHP thread,
 * until the thread is told to stop. Symfony's front controller then ends the
 * script with exit(0), which Cairo counts as a clean end.
 */
final class Runner implements RunnerInterface
{
    /**
     * @param \Closure(): mixed $factory
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly \Closure $factory,
        private readonly array $options,
    ) {
    }

    public function run(): int
    {
        $app = (new App())->concurrency((string) ($this->options['concurrency'] ?? 'isolated'));
        if (isset($this->options['threads'])) {
            $app->threads($this->options['threads']);
        }
        if (isset($this->options['max_requests'])) {
            $app->maxRequests((int) $this->options['max_requests']);
        }
        $http = $app->http((string) ($this->options['listen'] ?? ':8080'));
        $public = ($this->options['project_dir'] ?? dirname((string) $_SERVER['SCRIPT_FILENAME'], 2)) . '/public';
        if (is_dir($public)) {
            $http->publicDir($public);
        }
        if (!$app->isolated()) {
            $this->refuseSessions();
        }
        $http->fallback($app->isolated() ? $this->kernel : $this->kernels());
        $app->serve();
        return 0;
    }

    /**
     * Symfony's sessions are PHP's, one per thread: in fiber mode the
     * requests in flight on a thread would share one, a user's with
     * another's.
     */
    private function refuseSessions(): void
    {
        if (!$this->kernel instanceof KernelInterface) {
            return;
        }
        $this->kernel->boot();
        if ($this->kernel->getContainer()->hasParameter('session.storage.options')) {
            throw new \LogicException('Cairo\\Symfony\\Runtime: concurrency "fibers" cannot serve an app with sessions (framework.session), because PHP has one session per thread and the requests of a thread would share it. Serve it in isolated mode');
        }
    }

    /**
     * A handler that gives each request in flight a kernel of its own: a
     * kernel's services hold one request at a time. A kernel goes back to the
     * thread's free ones after terminate(), and Symfony resets its services
     * when it handles the next request.
     */
    private function kernels(): \Closure
    {
        $free = [$this->kernel];
        $factory = $this->factory;
        return static function (Request $req) use (&$free, $factory): Response {
            $kernel = array_pop($free) ?? $factory();
            $request = Adapter::toSymfony($req);
            try {
                $response = $kernel->handle($request);
            } catch (\Throwable $e) {
                $free[] = $kernel;
                throw $e;
            }
            Context::defer(static function () use ($kernel, $request, $response, &$free): void {
                try {
                    if ($kernel instanceof TerminableInterface) {
                        $kernel->terminate($request, $response);
                    }
                } finally {
                    $free[] = $kernel;
                }
            });
            return Adapter::fromSymfony($response, $request);
        };
    }
}
