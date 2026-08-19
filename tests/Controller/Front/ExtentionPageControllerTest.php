<?php

namespace App\Tests\Controller\Front;

use App\Controller\Front\ExtentionPageController;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ExtentionPageControllerTest extends WebTestCase
{
    public function testCompletedOnboardingUrlRedirectsToDashboard(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $user = (new User())
            ->setEmail('completed-extension-page@example.test')
            ->setPassword('hashed')
            ->completeExtensionOnboarding();

        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );

        $response = $container->get(ExtentionPageController::class)->index(
            Request::create('/app/extention?onboarding=1', 'GET')
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/app/dashboard', $response->getTargetUrl());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testCompletedUserCanOpenSecureReconnectFlow(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $user = (new User())
            ->setEmail('reconnect-extension-page@example.test')
            ->setPassword('hashed')
            ->completeExtensionOnboarding();

        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );

        $request = Request::create('/app/extention?reconnect=1', 'GET');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);
        $response = $container->get(ExtentionPageController::class)->index($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('data-reconnect="1"', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
