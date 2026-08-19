<?php

namespace App\Tests\Controller\Api;

use App\Controller\Api\ExtensionPairingController;
use App\Entity\User;
use App\Service\ExtensionPairingManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ExtensionPairingControllerTest extends WebTestCase
{
    private const EXTENSION_ORIGIN = 'chrome-extension://llckfoodkfccmibgmpfiodjkpincnfid';

    public function testOfficialExtensionPreflightIsAllowed(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/api/extension-pairing/exchange', server: [
            'HTTP_ORIGIN' => self::EXTENSION_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-extension-client-id',
        ]);

        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [Response::HTTP_OK, Response::HTTP_NO_CONTENT]
        );
        self::assertSame(
            self::EXTENSION_ORIGIN,
            $client->getResponse()->headers->get('Access-Control-Allow-Origin')
        );
        self::assertStringContainsString(
            'x-extension-client-id',
            strtolower((string) $client->getResponse()->headers->get('Access-Control-Allow-Headers'))
        );
    }

    public function testInvalidPairingCodeIsRejectedWithoutAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/extension-pairing/exchange', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => self::EXTENSION_ORIGIN,
            'HTTP_X_EXTENSION_CLIENT_ID' => 'client-identifier-0004',
        ], content: json_encode(['pairingToken' => 'invalid'], JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertSame(
            self::EXTENSION_ORIGIN,
            $client->getResponse()->headers->get('Access-Control-Allow-Origin')
        );

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Code d association invalide.', $payload['error'] ?? null);
    }

    public function testStartIsSuccessfulWhenOnboardingWasAlreadyCompleted(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $user = (new User())
            ->setEmail('completed-onboarding@example.test')
            ->setPassword('hashed')
            ->completeExtensionOnboarding();

        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );

        $request = Request::create('/api/extension-pairing/start', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);

        $csrfToken = $container
            ->get(CsrfTokenManagerInterface::class)
            ->getToken('extension_pairing_start')
            ->getValue();
        $request->headers->set('X-CSRF-TOKEN', $csrfToken);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');

        $response = $container->get(ExtensionPairingController::class)->start(
            $request,
            $container->get(ExtensionPairingManager::class)
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success'] ?? false);
        self::assertTrue($payload['completed'] ?? false);
        self::assertSame('/app/dashboard', $payload['redirectUrl'] ?? null);
    }

    public function testReconnectRequestDoesNotShortCircuitCompletedOnboarding(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $user = (new User())
            ->setEmail('completed-reconnect@example.test')
            ->setPassword('hashed')
            ->completeExtensionOnboarding();

        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );

        $request = Request::create(
            '/api/extension-pairing/start',
            'POST',
            content: json_encode(['clientId' => 'short', 'reconnect' => true], JSON_THROW_ON_ERROR)
        );
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);

        $csrfToken = $container
            ->get(CsrfTokenManagerInterface::class)
            ->getToken('extension_pairing_start')
            ->getValue();
        $request->headers->set('X-CSRF-TOKEN', $csrfToken);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');

        $response = $container->get(ExtensionPairingController::class)->start(
            $request,
            $container->get(ExtensionPairingManager::class)
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Identifiant d extension invalide.', $payload['error'] ?? null);
    }

    public function testStatusReportsCompletionWithoutAllowingBrowserCache(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $user = (new User())
            ->setEmail('completed-status@example.test')
            ->setPassword('hashed')
            ->completeExtensionOnboarding();

        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );

        $response = $container->get(ExtensionPairingController::class)->status();
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($payload['completed'] ?? false);
        self::assertSame('/app/dashboard', $payload['redirectUrl'] ?? null);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
