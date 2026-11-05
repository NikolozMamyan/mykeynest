<?php

namespace App\Tests\Controller\Front;

use App\Controller\Front\SharedAccessController;
use App\Entity\Credential;
use App\Entity\SharedAccess;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Repository\SharedAccessRepository;
use App\Repository\UserRepository;
use App\Service\MailerService;
use App\Service\NotificationService;
use App\Service\ProductGuide;
use App\Service\SharedAccessNotifier;
use App\Service\SharedAccessValidityManager;
use App\Service\SubscriptionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class SharedAccessSubmissionTest extends KernelTestCase
{
    /** @dataProvider sharingPaths */
    public function testBothSharingPathsApplyTheChosenDurationAndOnlyEmailAfterPersistence(bool $quick, bool $registered, ?string $date): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $owner = (new User())->setEmail('owner@example.com');
        (new \ReflectionProperty(User::class, 'id'))->setValue($owner, 1);
        $guest = $registered ? (new User())->setEmail('recipient@example.com') : null;
        if ($guest) (new \ReflectionProperty(User::class, 'id'))->setValue($guest, 2);
        $credential = (new Credential())->setName('Figma')->setDomain('figma.com')->setUser($owner);
        (new \ReflectionProperty(Credential::class, 'id'))->setValue($credential, 10);
        $request = Request::create($quick ? '/app/shared-access/quick' : '/app/shared-access/new', 'POST', [
            'email' => 'recipient@example.com', 'credentials' => [10], 'credential_id' => 10, 'expires_at' => $date ?? '',
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($owner, 'main', ['ROLE_USER']));
        $request->request->set('_token', $container->get('security.csrf.token_manager')->getToken($quick ? 'quick_share' : 'share_access')->getValue());
        $plans = $container->get(SubscriptionPlanService::class);
        (new \ReflectionProperty(SubscriptionPlanService::class, 'organizationTeamAccess'))->setValue($plans, ['user_1' => true]);
        (new \ReflectionProperty(SubscriptionPlanService::class, 'resolvedPlans'))->setValue($plans, ['team' => ['code' => 'team', 'label' => 'Team', 'limits' => [], 'features' => [], 'editable' => false, 'updatedAt' => null]]);
        $em = $this->createMock(EntityManagerInterface::class);
        $shares = $this->createMock(SharedAccessRepository::class);
        $em->method('getRepository')->willReturn($shares);
        $persisted = [];
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void { $persisted[] = $entity; });
        $flushed = false;
        $em->method('flush')->willReturnCallback(static function () use (&$flushed, &$persisted): void {
            foreach ($persisted as $entity) {
                if ($entity->getId() === null) (new \ReflectionProperty($entity, 'id'))->setValue($entity, 20);
            }
            $flushed = true;
        });
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($guest);
        $credentials = $this->createMock(CredentialRepository::class);
        $credentials->method('find')->with(10)->willReturn($credential);
        $mailer = $this->createMock(MailerService::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(static function () use (&$flushed): void { self::assertTrue($flushed); });
        $notifications = $this->createMock(NotificationService::class);
        $urls = $container->get('router');
        $translator = $container->get('translator');
        $validity = new SharedAccessValidityManager($shares, $em, $notifications, $urls, $translator, 'test-secret');
        $controller = new SharedAccessController(new RateLimiterFactory(['id' => 'test-share', 'policy' => 'fixed_window', 'limit' => 20, 'interval' => '1 hour'], new InMemoryStorage()), $plans, $translator, new ProductGuide(), $validity);
        $controller->setContainer($container);
        $notifier = new SharedAccessNotifier($notifications, $urls, $translator);
        $response = $quick ? $controller->quickShare($request, $em, $users, $mailer, new NullLogger(), $urls, $credentials, $notifier)
            : $controller->new($request, $em, $users, $mailer, new NullLogger(), $urls, $credentials, $notifier);
        self::assertSame(302, $response->getStatusCode());
        $created = array_values(array_filter($persisted, static fn (object $entity) => $entity instanceof SharedAccess));
        self::assertCount(1, $created);
        if ($date !== null) {
            self::assertSame($date, $created[0]->getExpiresAt()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d\TH:i'));
            self::assertFalse($created[0]->isUnlimitedOnActivation());
        } elseif ($registered) self::assertNull($created[0]->getExpiresAt());
        else {
            self::assertTrue($created[0]->isUnlimitedOnActivation());
            self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $created[0]->getExpiresAt());
        }
    }

    public static function sharingPaths(): array
    {
        return [[false, false, null], [true, false, null], [false, true, null], [true, true, null], [false, false, '2099-01-01T12:30'], [true, true, '2099-01-01T12:30']];
    }
}
