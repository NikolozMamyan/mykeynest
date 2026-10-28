<?php

namespace App\Tests\Controller\Api;

use App\Controller\Api\ProductGuideController;
use App\Entity\User;
use App\Service\ProductGuide;
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

final class ProductGuideControllerTest extends KernelTestCase
{
    /** @dataProvider updateCases */
    public function testGuideUpdatesRequireTheCurrentAccountAndCsrf(bool $authenticated, bool $csrf, array $data, int $status): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $user = (new User())->setEmail('guide@example.invalid');
        $other = new User();
        $container->get(TokenStorageInterface::class)->setToken($authenticated ? new UsernamePasswordToken($user, 'main', $user->getRoles()) : null);
        $request = Request::create('/api/product-guide', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data));
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);
        $request->headers->set('X-CSRF-TOKEN', $csrf ? $container->get(CsrfTokenManagerInterface::class)->getToken('product_guide')->getValue() : 'invalid');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($status === 200 ? self::once() : self::never())->method('flush');
        try {
            $response = $container->get(ProductGuideController::class)->update($request, new ProductGuide(), $em);
            self::assertSame($status, $response->getStatusCode());
            if ($status === 200) {
                self::assertSame(2, $user->getProductGuideState()['step']);
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            }
        } catch (AccessDeniedException) {
            self::assertSame(403, $status);
        }
        self::assertSame(['status' => 'pending', 'step' => 1], $other->getProductGuideState());
        if ($status !== 200) self::assertSame(['status' => 'pending', 'step' => 1], $user->getProductGuideState());
    }

    public static function updateCases(): array
    {
        return [
            [true, true, ['action' => 'progress', 'step' => 2, 'userId' => 999999], 200],
            [false, true, ['action' => 'progress', 'step' => 2], 403],
            [true, false, ['action' => 'progress', 'step' => 2], 403],
            [true, true, ['action' => 'progress', 'step' => 8], 422],
            [true, true, ['action' => 'progress', 'step' => '2'], 422],
            [true, true, ['action' => ['invalid']], 422],
        ];
    }
}
