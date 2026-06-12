<?php

namespace App;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

class Router implements \Amp\Http\Server\RequestHandler
{
    private StatsHandler $statsHandler;
    private ComposerProxyHandler $proxyHandler;

    public function __construct(
        StatsHandler $statsHandler,
        ComposerProxyHandler $proxyHandler
    )
    {
        $this->statsHandler = $statsHandler;
        $this->proxyHandler = $proxyHandler;
    }

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();

        if ($path === '/stats') {
            return $this->statsHandler->handleRequest($request);
        }

        if ($path === '/download') {
            return $this->proxyHandler->handleDownload($request);
        }

        // Новый маршрут для скачивания архивов через прокси
        if ($path === '/proxy') {
            return $this->proxyHandler->handleProxyDownload($request);
        }

        return $this->proxyHandler->handleProxy($request);
    }
}