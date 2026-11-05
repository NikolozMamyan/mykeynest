<?php

namespace App\Tests\Controller\Front;

use App\Controller\Front\TeamPageController;
use App\Entity\User;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\SubscriptionPlanConfigurationRepository;
use App\Repository\TeamMemberRepository;
use App\Service\MailerService;
use App\Service\OrganizationSeatManager;
use App\Service\SubscriptionPlanService;
use App\Service\TeamCreationManager;
use App\Service\TeamNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TeamCreationPageTest extends KernelTestCase
{
    public function testFreeAccountIsRedirectedToPlansBeforeCreatingAGroup(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $user = (new User())->setEmail('free@example.com');
        $request = Request::create('/app/teams/new');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $entityManager->expects(self::never())->method('persist');
        $members = $this->createMock(OrganizationMemberRepository::class);
        $controller = new TeamPageController(
            new SubscriptionPlanService($this->createMock(SubscriptionPlanConfigurationRepository::class), $entityManager, $members),
            new OrganizationSeatManager($this->createMock(OrganizationRepository::class), $members, $this->createMock(TeamMemberRepository::class), $entityManager),
        );
        $controller->setContainer($container);

        $response = $controller->new(
            $request,
            $entityManager,
            $container->get(Security::class),
            $container->get(TeamCreationManager::class),
            $container->get(TeamNotifier::class),
            $container->get(MailerService::class),
            $container->get(UrlGeneratorInterface::class),
            new NullLogger(),
            $container->get(TranslatorInterface::class),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/app/subscription', $response->getTargetUrl());
    }
}
