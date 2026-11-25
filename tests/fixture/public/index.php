<?php

// Symfony's own front controller, unchanged. composer.json names the runtime
// (extra.runtime.class), so `cairo serve public/index.php` serves the kernel.

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
