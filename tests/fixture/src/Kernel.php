<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** Request state in a service, as most apps have it (the user, the tenant). */
    private ?string $visitor = null;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/cairo-symfony-runtime-test/' . $this->environment . ($_SERVER['TEST_SESSIONS'] ?? '') . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/cairo-symfony-runtime-test/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = ['secret' => 'not-a-secret-of-the-test', 'http_method_override' => false];
        // An app with sessions, which fiber mode refuses.
        if (($_SERVER['TEST_SESSIONS'] ?? '') === '1') {
            $framework['session'] = ['storage_factory_id' => 'session.storage.factory.native'];
        }
        $container->extension('framework', $framework);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('hello', '/hello/{name}')->controller('kernel::hello');
        $routes->add('visit', '/visit/{name}')->controller('kernel::visit');
        $routes->add('form', '/form')->controller('kernel::form')->methods(['POST']);
    }

    public function hello(string $name): Response
    {
        return new Response("Hello {$name} from Symfony\n");
    }

    /**
     * Keeps the visitor in the kernel while the request waits: with one
     * kernel for every request of a thread, another request would overwrite
     * it. sleep() suspends only this request's fiber under Cairo.
     */
    public function visit(string $name, Request $request): JsonResponse
    {
        $this->visitor = $name;
        usleep((int) $request->query->get('wait', '0'));
        return new JsonResponse(['visitor' => $this->visitor, 'kernel' => spl_object_id($this)]);
    }

    public function form(Request $request): JsonResponse
    {
        return new JsonResponse(['name' => $request->request->get('name'), 'agent' => $request->headers->get('User-Agent')]);
    }
}
