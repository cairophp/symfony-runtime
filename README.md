# cairophp/symfony-runtime

Serve a Symfony app on the [Cairo](https://github.com/cairophp/cairo)
application server through its own front controller, with Symfony's
[Runtime component](https://symfony.com/doc/current/components/runtime.html),
the way `runtime/frankenphp-symfony` does for FrankenPHP. No `server.php`,
no change to `public/index.php`:

```sh
composer require cairophp/symfony-runtime
```

Name the runtime in `composer.json` (then run `composer dump-autoload`), or
set `APP_RUNTIME=Cairo\\Symfony\\Runtime` in the environment:

```json
"extra": {
    "runtime": {
        "class": "Cairo\\Symfony\\Runtime"
    }
}
```

and serve the front controller:

```sh
cairo serve public/index.php
```

Files under `public/` are served by Cairo (Go) and every other path goes to
the kernel. Outside `cairo serve` (the console, PHP-FPM, your tests) the
runtime is Symfony's own, so the same front controller and `bin/console` keep
working everywhere. It needs Cairo 0.6 or later (with `Cairo\App::serve()`).

## Options

From `APP_RUNTIME_OPTIONS` (JSON) or `extra.runtime` in `composer.json`, next
to Symfony's own options:

| Option | Default | |
|---|---|---|
| `listen` | `:8080` | The address of the HTTP server |
| `concurrency` | `isolated` | `isolated`, or `fibers` for an app known to be fiber-safe |
| `threads` | Cairo's | PHP threads |
| `max_requests` | Cairo's | Requests a thread serves before it is replaced by a fresh one |

```sh
APP_RUNTIME_OPTIONS='{"listen":":8000","concurrency":"fibers"}' cairo serve public/index.php
```

## Isolated mode (the default)

Each PHP thread boots the kernel once and handles one request at a time;
Symfony resets the kernel's services (`kernel.reset`) before each request, as
under FrankenPHP's worker mode or RoadRunner.

## Fiber mode

Each PHP thread runs several requests at once, and while one waits on I/O
(the database, HTTP calls, `sleep()`) the others run. A kernel's services
hold one request at a time, so each request in flight on a thread gets a
kernel of its own, booted on first need and reused after `terminate()`. An
app with sessions (`framework.session`) is refused in fiber mode: PHP has one
session per thread, which the requests of a thread would share. Serve it in
isolated mode.

## Tests

The tests serve a small Symfony app (`tests/fixture`) with a `cairo` binary:

```sh
composer install
tests/fixture/setup.sh
CAIRO_BINARY=/path/to/cairo vendor/bin/phpunit
```

## License

Apache-2.0
