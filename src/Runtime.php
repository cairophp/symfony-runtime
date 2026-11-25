<?php

declare(strict_types=1);

namespace Cairo\Symfony;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\ResolverInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * Symfony's runtime, with a runner that serves the kernel on Cairo when the
 * front controller runs under `cairo serve`, and Symfony's own runner
 * anywhere else (the console, PHP-FPM, tests).
 *
 * Its options (APP_RUNTIME_OPTIONS, or extra.runtime in composer.json):
 * listen (':8080'), concurrency ('isolated', or 'fibers' for an app known to
 * be fiber-safe), threads, max_requests, and Symfony's own.
 */
final class Runtime extends SymfonyRuntime
{
    /** @var ?\Closure(): mixed makes the app anew, for one kernel per request in flight */
    private ?\Closure $factory = null;

    public function getResolver(callable $callable, ?\ReflectionFunction $reflector = null): ResolverInterface
    {
        $resolver = parent::getResolver($callable, $reflector);
        $this->factory = static function () use ($resolver): mixed {
            [$app, $args] = $resolver->resolve();
            return $app(...$args);
        };
        return $resolver;
    }

    public function getRunner(?object $application): RunnerInterface
    {
        if (PHP_SAPI !== 'cairo') {
            return parent::getRunner($application);
        }
        if (!$application instanceof HttpKernelInterface) {
            throw new \LogicException(sprintf('cairo serve runs a Symfony app whose front controller returns a kernel (HttpKernelInterface), not %s', get_debug_type($application)));
        }
        return new Runner($application, $this->factory ?? static fn (): HttpKernelInterface => $application, $this->options);
    }
}
