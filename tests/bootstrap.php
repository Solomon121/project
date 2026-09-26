<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Stand-ins for the parts of the WHMCS runtime the module entry points touch.
require __DIR__ . '/Support/WhmcsRuntimeStubs.php';
