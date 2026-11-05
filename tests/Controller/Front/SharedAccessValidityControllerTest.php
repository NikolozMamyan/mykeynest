<?php

namespace App\Tests\Controller\Front;

use App\Controller\Front\SharedAccessValidityController;
use App\Entity\Credential;
use App\Entity\SharedAccess;
use App\Entity\User;
use App\Repository\SharedAccessRepository;
use App\Service\NotificationService;
use App\Service\SharedAccessValidityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SharedAccessValidityControllerTest extends KernelTestCase
{
    public function testAnotherUserCannotManageSharingDuration(): void
    {
        [$controller, $manager, $em, $share, $request] = $this->context('GET');
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($share->getGuest(), 'main', ['ROLE_USER']));
        $this->expectException(AccessDeniedException::class);
        $controller->manage($share, $request, $manager, $em);
    }

    public function testChangingDurationRequiresCsrfToken(): void
    {
        [$controller, $manager, $em, $share, $request] = $this->context('POST');
        $em->expects(self::never())->method('flush');
        $this->expectException(AccessDeniedException::class);
        $controller->manage($share, $request, $manager, $em);
    }

    public function testOwnerCanChooseADateWithCsrfProtection(): void
    {
        [$controller, $manager, $em, $share, $request] = $this->context('POST');
        $request->request->set('_token', self::getContainer()->get('security.csrf.token_manager')->getToken('share_validity_1')->getValue());
        $request->request->set('expires_at', '2099-01-01T12:30');
        $em->expects(self::once())->method('flush');
        $response = $controller->manage($share, $request, $manager, $em);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/app/shared-access/1/validity', $response->getTargetUrl());
        self::assertSame('2099-01-01 12:30', $share->getExpiresAt()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'));
        self::assertFalse($share->isUnlimitedOnActivation());
    }

    public function testOpeningSignedEmailLinkDoesNotSendARequest(): void
    {
        [$controller, $manager, $em, $share, $request] = $this->context('GET');
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        parse_str(parse_url($manager->extensionRequestUrl($share), PHP_URL_QUERY), $query);
        $request->query->set('signature', $query['signature']);
        $em->expects(self::never())->method('getConnection');
        $response = $controller->requestExtension($share, $request, $manager);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Envoyer ma demande', $response->getContent());
        self::assertNull($share->getExtensionRequestedAt());
    }

    public function testGuestRequestRequiresCsrfEvenWithAValidSignature(): void
    {
        [$controller, $manager, $em, $share, $request] = $this->context('POST');
        parse_str(parse_url($manager->extensionRequestUrl($share), PHP_URL_QUERY), $query);
        $request->query->set('signature', $query['signature']);
        $em->expects(self::never())->method('getConnection');
        $this->expectException(AccessDeniedException::class);
        $controller->requestExtension($share, $request, $manager);
    }

    private function context(string $method): array
    {
        self::bootKernel();
        $container = self::getContainer();
        $request = Request::create('/guest/shared-access/1/extension', $method);
        $request->setLocale('fr');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->get('translator')->setLocale('fr');
        $owner = (new User())->setEmail('owner@example.com');
        $guest = (new User())->setEmail('guest@example.com')->setRoles(['ROLE_GUEST']);
        $share = (new SharedAccess())->setOwner($owner)->setGuest($guest)->setCredential((new Credential())->setName('Figma'))->setValidity(new \DateTimeImmutable('+1 day'));
        (new \ReflectionProperty(SharedAccess::class, 'id'))->setValue($share, 1);
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($owner, 'main', ['ROLE_USER']));
        $em = $this->createMock(EntityManagerInterface::class);
        $manager = new SharedAccessValidityManager($this->createMock(SharedAccessRepository::class), $em, $this->createMock(NotificationService::class), $container->get('router'), $container->get('translator'), 'test-secret');
        $controller = new SharedAccessValidityController();
        $controller->setContainer($container);

        return [$controller, $manager, $em, $share, $request];
    }
}
