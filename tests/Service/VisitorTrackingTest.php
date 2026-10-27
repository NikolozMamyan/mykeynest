<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PageVisit;
use App\Entity\VisitorSession;
use App\EventSubscriber\VisitorTrackingSubscriber;
use App\Service\VisitorGeolocation;
use App\Service\VisitorRecorder;
use App\Service\VisitorReportProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use DoctrineMigrations\Version20261027120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class VisitorTrackingTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private VisitorRecorder $recorder;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 2).'/migrations/Version20261027120000.php';
        $schema = new Schema();
        (new Version20261027120000($this->connection, new NullLogger()))->up($schema);
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $this->entityManager = new EntityManager($this->connection, $config);
        $geolocation = new VisitorGeolocation(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('{"ip":"1.1.1.1","country":"LU"}')),
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 8, 'interval' => '1 second'], new InMemoryStorage()),
        );
        $this->recorder = new VisitorRecorder($this->connection, $geolocation, 'test-secret');
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        $this->connection->close();
    }

    public function testGroupsPagesWithoutStoringSensitiveQueryStringsAndRenewsAfterThirtyMinutes(): void
    {
        $now = new \DateTimeImmutable('2026-10-27 12:00:00 UTC');
        $this->recorder->record($this->request('/?gclid=ad-click&utm_campaign=luxembourg&token=secret'), $now);
        $this->recorder->record($this->request('/fr/blog?email=private@example.test'), $now->modify('+10 minutes'));
        $this->recorder->record($this->request('/help/center'), $now->modify('+40 minutes'));

        $sessions = $this->connection->fetchAllAssociative('SELECT * FROM visitor_session ORDER BY id');
        self::assertCount(2, $sessions);
        self::assertSame(2, (int) $sessions[0]['page_view_count']);
        self::assertSame('google', $sessions[0]['source']);
        self::assertSame('cpc', $sessions[0]['medium']);
        self::assertSame('luxembourg', $sessions[0]['campaign']);
        self::assertSame('LU', $sessions[0]['country_code']);
        self::assertSame('direct', $sessions[1]['source']);
        self::assertSame(['/', '/fr/blog', '/help/center'], $this->connection->fetchFirstColumn('SELECT path FROM page_visit ORDER BY id'));
        self::assertStringNotContainsString('secret', json_encode($sessions));
    }

    public function testHandlesMalformedUtmAndRecognizesOrganicAndReferralTraffic(): void
    {
        $organic = $this->request('/?utm_source[]=broken&utm_campaign[]=broken');
        $organic->headers->set('Referer', 'https://www.google.lu/search?q=private');
        $this->recorder->record($organic);
        $referral = $this->request('/');
        $referral->server->set('REMOTE_ADDR', '127.0.0.2');
        $referral->headers->set('Referer', 'https://example.test/private?token=secret');
        $this->recorder->record($referral);
        $rows = $this->connection->fetchAllAssociative('SELECT source, medium, campaign FROM visitor_session ORDER BY id');
        self::assertSame(['source' => 'google', 'medium' => 'organic', 'campaign' => null], $rows[0]);
        self::assertSame(['source' => 'example.test', 'medium' => 'referral', 'campaign' => null], $rows[1]);
    }

    public function testSubscriberRecordsOnlySuccessfulPublicNavigations(): void
    {
        $subscriber = new VisitorTrackingSubscriber($this->recorder, new NullLogger(), true);
        foreach ([
            ['app_landing', 'GET', 200, [], true],
            ['show_register', 'GET', 200, [], true],
            ['admin_visitors', 'GET', 200, [], false],
            ['app_credential_index', 'GET', 200, [], false],
            ['api_login', 'GET', 200, [], false],
            ['app_landing', 'POST', 200, [], false],
            ['app_landing', 'GET', 302, [], false],
            ['app_landing', 'GET', 200, ['User-Agent' => 'Googlebot'], false],
            ['app_landing', 'GET', 200, ['Sec-Purpose' => 'prefetch;prerender'], false],
            ['app_landing', 'GET', 200, ['Turbo-Frame' => 'preview'], false],
            ['app_landing', 'GET', 200, ['X-Requested-With' => 'XMLHttpRequest'], false],
        ] as [$route, $method, $status, $headers, $recorded]) {
            $before = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM page_visit');
            $request = $this->request('/');
            $request->setMethod($method);
            $request->attributes->set('_route', $route);
            $request->headers->add($headers);
            $subscriber->track(new TerminateEvent($this->createMock(HttpKernelInterface::class), $request, new Response('', $status, ['Content-Type' => 'text/html; charset=UTF-8'])));
            self::assertSame($before + (int) $recorded, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM page_visit'), $route.' '.$method.' '.json_encode($headers));
        }
    }

    public function testReportsSourcesCountriesHistoryAndIpFiltersWithoutDoubleCounting(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->recorder->record($this->request('/?gclid=click&utm_campaign=luxembourg'), $now->modify('-3 minutes'));
        $this->recorder->record($this->request('/fr/blog'), $now->modify('-2 minutes'));
        $other = $this->request('/');
        $other->server->set('REMOTE_ADDR', '127.0.0.2');
        $this->recorder->record($other, $now->modify('-1 minute'));
        $reports = new VisitorReportProvider($this->connection, $this->entityManager);
        $report = $reports->overview(Request::create('/admin/visiteurs?days=7&page=999'));
        self::assertSame(2, $report['uniqueVisitors']);
        self::assertSame(2, $report['periodSessions']);
        self::assertSame(3, $report['periodViews']);
        self::assertSame(2, $report['activeCount']);
        self::assertSame(2, $report['campaignViews']);
        self::assertCount(7, $report['chart']);
        self::assertSame(3, array_sum(array_column($report['trafficSources'], 'views')));
        self::assertSame(2, array_sum(array_column($report['countries'], 'sessions')));
        self::assertContains('Luxembourg', array_column($report['countries'], 'name'));
        self::assertContains('Non déterminé', array_column($report['countries'], 'name'));
        self::assertSame(1, $report['page']);
        $filtered = $reports->overview(Request::create('/admin/visiteurs?days=7&ip=1.1.1.1'));
        self::assertSame(1, $filtered['uniqueVisitors']);
        self::assertSame(2, $filtered['periodViews']);
        self::assertCount(1, $filtered['countries']);
        $invalid = $reports->overview(Request::create('/admin/visiteurs?days=7&ip=invalid'));
        self::assertTrue($invalid['invalidIp']);
        self::assertSame(0, $invalid['periodViews']);
        self::assertSame(0, $invalid['sessionCount']);
        $session = $this->entityManager->getRepository(VisitorSession::class)->findOneBy(['ipAddress' => '1.1.1.1']);
        $history = $reports->history($session, Request::create('/admin/visiteurs/1'));
        self::assertSame('Luxembourg', $history['countryName']);
        self::assertSame(['/', '/fr/blog'], array_map(static fn (PageVisit $visit): string => $visit->getPath(), $history['visits']));
    }

    private function request(string $path): Request
    {
        return Request::create('https://key-nest.com'.$path, 'GET', [], [], [], ['REMOTE_ADDR' => '1.1.1.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/144.0']);
    }
}
