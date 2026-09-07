<?php

namespace App\Tests\Controller\Front;

use App\Controller\Front\CredentialPageController;
use App\Entity\Credential;
use App\Entity\User;
use App\Service\CredentialUrlPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CredentialLoginUrlControllerTest extends KernelTestCase
{
    /** @dataProvider updateCases */
    public function testOnlyTheOwnerCanSaveAValidUrlWithCsrf(bool $owner, bool $csrf, string $url, int $status): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($status === 200 ? self::once() : self::never())->method('flush');
        $container->set(EntityManagerInterface::class, $em);
        $user = (new User())->setEmail('owner@example.test');
        $viewer = $owner ? $user : (new User())->setEmail('viewer@example.test');
        $credential = (new Credential())->setUser($user)->setPassword('encrypted-password');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($viewer, 'main', $viewer->getRoles()));
        $request = Request::create('/app/credential/10/login-url', 'POST', ['loginUrl' => $url]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);
        $request->request->set('_token', $csrf ? $container->get(CsrfTokenManagerInterface::class)->getToken('credential_login_url')->getValue() : 'invalid');

        try {
            $response = $container->get(CredentialPageController::class)->updateLoginUrl($request, $credential, new CredentialUrlPolicy());
            self::assertSame($status, $response->getStatusCode());
            if ($status === 200) self::assertSame($url === '' ? null : 'https://example.com/login', $credential->getLoginUrl());
        } catch (AccessDeniedException $exception) {
            self::assertSame(403, $status);
        }
        self::assertSame('encrypted-password', $credential->getPassword());
    }

    public static function updateCases(): array
    {
        return [
            [true, true, 'example.com/login', 200],
            [true, true, '', 200],
            [true, true, 'javascript:alert(1)', 422],
            [true, false, 'example.com/login', 403],
            [false, true, 'example.com/login', 403],
        ];
    }
}
