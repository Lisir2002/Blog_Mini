<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use PenBlog\Kernel\Kernel;
use PenBlog\Kernel\NotInstalledException;

require __DIR__ . '/../vendor/autoload.php';

try {
    $kernel = Kernel::boot();
    $psr17 = new Psr17Factory();
    $request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();
    $response = $kernel->handle($request);
} catch (NotInstalledException $e) {
    $response = new Response(503, ['Content-Type' => 'text/plain; charset=utf-8'], "PenBlog is not installed yet.\nRun: php bin/install.php\n");
}

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}
echo $response->getBody();
