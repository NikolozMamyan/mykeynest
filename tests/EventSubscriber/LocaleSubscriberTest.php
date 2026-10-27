<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LocaleSubscriberTest extends TestCase
{
    public function testPublicLanguageDependsOnTheUrlWithoutStartingASession(): void
    {
        foreach (['/' => 'en', '/?lang=en' => 'en', '/?lang=fr' => 'fr'] as $url => $expected) {
            $request = Request::create($url);
            $request->attributes->set('_route', 'app_landing');
            $session = new Session(new MockArraySessionStorage());
            $request->setSession($session);
            $this->dispatch($request);

            self::assertSame($expected, $request->getLocale());
            self::assertFalse($session->isStarted());
        }
    }

    /** @dataProvider browserLanguages */
    public function testDetectsSupportedBrowserLanguagesAndFallsBackToEnglish(string $header, string $expected): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => $header]);
        $request->attributes->set('_route', 'app_landing');
        $this->dispatch($request);
        self::assertSame($expected, $request->getLocale());
    }

    public static function browserLanguages(): array
    {
        return [
            ['fr-FR,fr;q=0.9,en;q=0.8', 'fr'],
            ['en-US,en;q=0.9,fr;q=0.8', 'en'],
            ['fr-CA,en;q=0.8', 'fr'],
            ['de-DE,fr-LU;q=0.8,en;q=0.6', 'fr'],
            ['fr;q=0.5,en;q=0.9', 'en'],
            ['fr;q=0,en;q=0.8', 'en'],
            ['de-DE,lb;q=0.9', 'en'],
            ['', 'en'],
            ['*', 'en'],
        ];
    }

    public function testManualChoiceIsRememberedAndOverridesBrowserDetection(): void
    {
        $request = Request::create('https://key-nest.com/?lang=fr', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        $request->attributes->set('_route', 'app_landing');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $this->dispatch($request);
        $response = new Response();
        (new LocaleSubscriber(new RequestStack()))->onKernelResponse(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response,
        ));
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame(LocaleSubscriber::COOKIE_NAME, $cookies[0]->getName());
        self::assertSame('fr', $cookies[0]->getValue());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertTrue($cookies[0]->isSecure());
        self::assertFalse($session->isStarted());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));

        $next = Request::create('/help/center', 'GET', [], [LocaleSubscriber::COOKIE_NAME => $cookies[0]->getValue()], [], ['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        $next->attributes->set('_route', 'app_help_center');
        $this->dispatch($next);
        self::assertSame('fr', $next->getLocale());
        $next = Request::create('/?lang=en', 'GET', [], [LocaleSubscriber::COOKIE_NAME => 'fr'], [], ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);
        $this->dispatch($next);
        self::assertSame('en', $next->getLocale());
    }

    public function testAutomaticDetectionDoesNotSetAPreferenceCookie(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);
        $this->dispatch($request);
        $response = new Response();
        (new LocaleSubscriber(new RequestStack()))->onKernelResponse(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response,
        ));
        self::assertSame([], $response->headers->getCookies());
    }

    public function testIgnoresInvalidLanguageChoices(): void
    {
        $request = Request::create('/?lang[]=fr', 'GET', [], [LocaleSubscriber::COOKIE_NAME => 'invalid'], [], ['HTTP_ACCEPT_LANGUAGE' => 'fr-BE']);
        $this->dispatch($request);
        self::assertSame('fr', $request->getLocale());
        self::assertFalse($request->attributes->has('_locale_preference'));
    }

    public function testPrivacyPolicyCanUseDetectedAndManuallySelectedLanguages(): void
    {
        foreach (['/privacy' => 'en', '/privacy?lang=fr' => 'fr'] as $url => $expected) {
            $request = Request::create($url);
            $request->attributes->set('_route', 'legal_privacy');
            $this->dispatch($request);
            self::assertSame($expected, $request->getLocale());
        }
    }

    public function testExplicitBlogLocaleDoesNotStartASession(): void
    {
        $request = Request::create('/en/blog?lang=fr');
        $request->attributes->add(['_route' => 'blog_index', '_locale' => 'en']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $this->dispatch($request);
        self::assertSame('en', $request->getLocale());
        self::assertFalse($session->isStarted());
    }

    public function testPrivateLanguageChoiceStillPersistsInTheSession(): void
    {
        $request = Request::create('/app?lang=en');
        $request->attributes->set('_route', 'app_dashboard');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $this->dispatch($request);
        self::assertSame('en', $request->getLocale());
        self::assertSame('en', $session->get('_locale'));
    }

    public function testFrenchOnlyLegalPagesDoNotAdvertiseAnEnglishTranslation(): void
    {
        $request = Request::create('/cgu?lang=en');
        $request->attributes->set('_route', 'legal_cgu');
        $this->dispatch($request);
        self::assertSame('fr', $request->getLocale());
    }

    private function dispatch(Request $request): void
    {
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new LocaleSubscriber(new RequestStack()))->onKernelRequest($event);
    }
}
