<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LocaleSubscriberTest extends TestCase
{
    public function testPublicLanguageDependsOnTheUrlWithoutStartingASession(): void
    {
        foreach (['/' => 'fr', '/?lang=en' => 'en'] as $url => $expected) {
            $request = Request::create($url);
            $request->attributes->set('_route', 'app_landing');
            $session = new Session(new MockArraySessionStorage());
            $request->setSession($session);
            $this->dispatch($request);

            self::assertSame($expected, $request->getLocale());
            self::assertFalse($session->isStarted());
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
