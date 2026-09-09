<?php

namespace App\Tests\Controller\Front;

use App\Controller\Front\CredentialPageController;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Service\CredentialCsvImporter;
use App\Service\SubscriptionPlanService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CredentialImportPageTest extends KernelTestCase
{
    public function testImportPageProvidesAProtectedFormAndDownloadableExample(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $user = (new User())
            ->setEmail('csv-import@example.test')
            ->setPassword('hashed')
            ->setCompany('MYKEYNEST');
        $request = Request::create('/app/import/pass');
        $request->attributes->set('_route', 'credential_import');
        $request->setLocale('fr');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->get('translator')->setLocale('fr');
        $container->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );

        $repository = $this->createMock(CredentialRepository::class);
        $repository->expects(self::once())->method('count')->with(['user' => $user])->willReturn(3);
        $container->set(CredentialRepository::class, $repository);
        $this->enableImportFeature($container->get(SubscriptionPlanService::class));

        $controller = $container->get(CredentialPageController::class);
        $response = $controller->importCredentials(
            $request,
            $container->get(CredentialCsvImporter::class),
            $container->get('translator'),
        );
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $crawler->filter('.credential-import[data-controller="credential-import"]')->count());
        self::assertSame(1, $crawler->filter('a[href="/app/import/pass/example.csv"][download]')->count());
        self::assertNotSame('', $crawler->filter('input[name="_token"]')->attr('value'));
        self::assertStringContainsString('12 emplacement(s)', $crawler->filter('.credential-import__capacity')->text());

        $download = $controller->downloadImportExample($request);
        self::assertSame('text/csv; charset=UTF-8', $download->headers->get('Content-Type'));
        self::assertStringContainsString('attachment;', (string) $download->headers->get('Content-Disposition'));
        self::assertStringStartsWith("\xEF\xBB\xBFname,domain,username,password", (string) $download->getContent());
    }

    private function enableImportFeature(SubscriptionPlanService $plans): void
    {
        $resolvedPlans = new \ReflectionProperty(SubscriptionPlanService::class, 'resolvedPlans');
        $resolvedPlans->setValue($plans, [
            SubscriptionPlanService::PLAN_FREE => [
                'code' => SubscriptionPlanService::PLAN_FREE,
                'label' => 'Free',
                'limits' => [SubscriptionPlanService::LIMIT_CREDENTIALS => 15],
                'features' => [SubscriptionPlanService::FEATURE_CREDENTIAL_IMPORT => true],
                'editable' => true,
                'updatedAt' => null,
            ],
        ]);
    }
}
