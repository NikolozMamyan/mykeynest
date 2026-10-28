<?php

namespace App\Tests\Service;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

final class ProductGuideTemplateTest extends KernelTestCase
{
    /** @dataProvider guideStates */
    public function testGuideStateIsCompatibleWithStimulusObjectValues(?array $state): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $user = (new User())->setEmail('guide@example.invalid')->setProductGuideState($state);
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $request = Request::create('/app/credential?guide=1');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);

        $html = $container->get(Environment::class)->render('components/parts/_product_guide.html.twig', ['currentRoute' => 'app_credential']);
        $guide = (new Crawler($html))->filter('[data-controller="product-guide"]');
        if ($state === null) {
            self::assertFalse($guide->getNode(0)->hasAttribute('data-product-guide-state-value'));
        } else {
            self::assertSame($state, json_decode($guide->attr('data-product-guide-state-value'), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public static function guideStates(): array
    {
        return [
            'existing account without history' => [null],
            'new account' => [['status' => 'pending', 'step' => 1]],
            'completed guide' => [['status' => 'completed', 'step' => 4]],
        ];
    }
}
