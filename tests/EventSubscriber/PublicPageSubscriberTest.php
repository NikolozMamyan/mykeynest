<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\PublicPageSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PublicPageSubscriberTest extends TestCase
{
    public function testRedirectsWwwAndPreservesThePathAndQuery(): void
    {
        foreach (['GET' => 301, 'POST' => 308] as $method => $status) {
            $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('https://www.key-nest.com/help/center?lang=en', $method), HttpKernelInterface::MAIN_REQUEST);
            (new PublicPageSubscriber())->redirectCanonicalHost($event);
            self::assertSame($status, $event->getResponse()->getStatusCode());
            self::assertSame('https://key-nest.com/help/center?lang=en', $event->getResponse()->headers->get('Location'));
        }
    }

    public function testDoesNotRedirectLocalhost(): void
    {
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('http://localhost/'), HttpKernelInterface::MAIN_REQUEST);
        (new PublicPageSubscriber())->redirectCanonicalHost($event);
        self::assertFalse($event->hasResponse());
    }

    public function testCachesOnlyAnonymousPublicResponsesAndVariesByCredentials(): void
    {
        $request = Request::create('https://key-nest.com/');
        $request->attributes->set('_route', 'app_landing');
        $response = $this->respond($request);
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame(300, $response->getMaxAge());
        self::assertContains('Cookie', $response->getVary());
        self::assertContains('Authorization', $response->getVary());
        self::assertContains('Accept-Language', $response->getVary());

        $request->cookies->set('AUTH_TOKEN', 'test-token');
        self::assertTrue($this->respond($request)->headers->hasCacheControlDirective('private'));
        $request->cookies->remove('AUTH_TOKEN');
        $request->headers->set('Authorization', 'Bearer test-token');
        self::assertTrue($this->respond($request)->headers->hasCacheControlDirective('private'));
        $request->headers->remove('Authorization');
        $response = new Response();
        $response->headers->setCookie(new Cookie('PHPSESSID', 'test-session'));
        self::assertTrue($this->respond($request, $response)->headers->hasCacheControlDirective('private'));
    }

    private function respond(Request $request, ?Response $response = null): Response
    {
        $response ??= new Response();
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        (new PublicPageSubscriber())->cacheAnonymousPages($event);

        return $response;
    }
}
