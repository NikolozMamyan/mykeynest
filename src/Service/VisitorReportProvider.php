<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PageVisit;
use App\Entity\VisitorSession;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;

final readonly class VisitorReportProvider
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function overview(Request $request, bool $includeSessions = true): array
    {
        $days = $request->query->getInt('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $ip = trim($request->query->getString('ip'));
        $invalidIp = '' !== $ip && false === filter_var($ip, FILTER_VALIDATE_IP);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $today = $now->setTimezone(new \DateTimeZone('Europe/Paris'))->setTime(0, 0);
        $chartDays = $days;
        $start = $today->modify('-'.($chartDays - 1).' days');
        $utc = new \DateTimeZone('UTC');
        $query = $this->entityManager->createQueryBuilder()->from(VisitorSession::class, 'session');
        $query->andWhere('session.lastSeenAt >= :since')->setParameter('since', $start->setTimezone($utc));
        if ('' !== $ip) {
            $query->andWhere('session.ipAddress = :ip')->setParameter('ip', $invalidIp ? 'invalid' : $ip);
        }
        $count = (int) (clone $query)->select('COUNT(session.id)')->getQuery()->getSingleScalarResult();
        $uniqueVisitors = (int) (clone $query)->select('COUNT(DISTINCT session.visitorKey)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($count / 25));
        $page = min($pageCount, max(1, $request->query->getInt('page', 1)));
        $sessions = $includeSessions ? $query->select('session')->orderBy('session.lastSeenAt', 'DESC')->addOrderBy('session.id', 'DESC')
            ->setFirstResult(($page - 1) * 25)->setMaxResults(25)->getQuery()->getResult() : [];

        $chart = [];
        $dailySources = [];
        $contributions = ['sources' => [], 'campaigns' => []];
        for ($index = 0; $index < $chartDays; ++$index) {
            $date = $start->modify('+'.$index.' days')->format('Y-m-d');
            $chart[$date] = ['date' => $date, 'sessions' => 0, 'views' => 0, 'campaignViews' => 0];
        }
        $chartParameters = [
            'since' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'until' => $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
        $ipWhere = '' === $ip ? '' : ' AND session.ip_address = :ip';
        if ('' !== $ip) {
            $chartParameters['ip'] = $invalidIp ? 'invalid' : $ip;
        }
        foreach (['sessions' => ['visitor_session session', 'session.started_at'], 'views' => ['page_visit visit JOIN visitor_session session ON session.id = visit.session_id', 'visit.visited_at']] as $metric => [$table, $column]) {
            $dimensions = 'views' === $metric ? ', session.source, session.medium, session.campaign' : '';
            $hour = 'postgresql' === $this->connection->getDatabasePlatform()->getName()
                ? "TO_CHAR(".$column.", 'YYYY-MM-DD HH24')" : 'SUBSTR('.$column.', 1, 13)';
            $rows = $this->connection->fetchAllAssociative(
                'SELECT '.$hour.' AS hour'.$dimensions.', COUNT(*) AS total FROM '.$table.' WHERE '.$column.' >= :since AND '.$column.' < :until'.$ipWhere.' GROUP BY '.$hour.$dimensions,
                $chartParameters,
            );
            foreach ($rows as $row) {
                $date = (new \DateTimeImmutable($row['hour'].':00:00', $utc))->setTimezone($today->getTimezone())->format('Y-m-d');
                $total = (int) $row['total'];
                $chart[$date][$metric] += $total;
                if ('views' === $metric) {
                    $source = (string) $row['source'];
                    $medium = (string) ($row['medium'] ?? '');
                    $campaign = (string) ($row['campaign'] ?? '');
                    $sourceKey = json_encode([$source, $medium], JSON_THROW_ON_ERROR);
                    $campaignKey = '' === $campaign ? 'none' : json_encode([$campaign, $source, $medium], JSON_THROW_ON_ERROR);
                    $contributions['sources'][$sourceKey] ??= ['source' => $source, 'medium' => $medium, 'views' => 0];
                    $contributions['sources'][$sourceKey]['views'] += $total;
                    $contributions['campaigns'][$campaignKey] ??= ['campaign' => $campaign, 'source' => '' === $campaign ? '' : $source, 'medium' => '' === $campaign ? '' : $medium, 'views' => 0];
                    $contributions['campaigns'][$campaignKey]['views'] += $total;
                    $dailySources[$date][$sourceKey] = ($dailySources[$date][$sourceKey] ?? 0) + $total;
                    if ('' !== $campaign) {
                        $chart[$date]['campaignViews'] += $total;
                    }
                }
            }
        }
        $periodViews = array_sum(array_column($chart, 'views'));
        $campaignViews = array_sum(array_column($chart, 'campaignViews'));
        $peakDay = null;
        foreach ($chart as &$day) {
            $day['campaignShare'] = $day['views'] > 0 ? $day['campaignViews'] / $day['views'] * 100 : 0;
            $day['periodShare'] = $periodViews > 0 ? $day['views'] / $periodViews * 100 : 0;
            $day['topSource'] = null;
            foreach ($dailySources[$day['date']] ?? [] as $key => $views) {
                if (null === $day['topSource'] || $views > $day['topSource']['views']) {
                    $day['topSource'] = [...$contributions['sources'][$key], 'views' => $views, 'share' => $views / $day['views'] * 100];
                }
            }
            if ($day['views'] > 0 && (null === $peakDay || $day['views'] >= $peakDay['views'])) {
                $peakDay = $day;
            }
        }
        unset($day);
        foreach ($contributions as &$groups) {
            ksort($groups);
            $groups = array_values($groups);
            usort($groups, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);
            foreach ($groups as &$group) {
                $group['share'] = $periodViews > 0 ? $group['views'] / $periodViews * 100 : 0;
            }
            unset($group);
        }
        unset($groups);
        $activeParameters = ['since' => $now->modify('-5 minutes')->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s')];
        if ('' !== $ip) {
            $activeParameters['ip'] = $invalidIp ? 'invalid' : $ip;
        }
        $activeCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM visitor_session session WHERE last_seen_at >= :since AND last_seen_at <= :now'.$ipWhere,
            $activeParameters,
        );
        $maxValue = max([...array_column($chart, 'views'), ...array_column($chart, 'sessions')]);

        $countries = $this->connection->fetchAllAssociative(
            'SELECT session.country_code AS code, COUNT(*) AS sessions FROM visitor_session session WHERE session.started_at >= :since AND session.started_at < :until'.$ipWhere.' GROUP BY session.country_code ORDER BY sessions DESC',
            $chartParameters,
        );
        $periodSessions = array_sum(array_column($chart, 'sessions'));
        foreach ($countries as &$country) {
            $country['name'] = $country['code'] && Countries::exists($country['code']) ? Countries::getName($country['code'], 'fr') : 'Non déterminé';
            $country['share'] = $periodSessions > 0 ? (int) $country['sessions'] / $periodSessions * 100 : 0;
        }
        unset($country);
        $topPages = $this->connection->fetchAllAssociative(
            'SELECT visit.path, COUNT(*) AS views FROM page_visit visit JOIN visitor_session session ON session.id = visit.session_id WHERE visit.visited_at >= :since AND visit.visited_at < :until'.$ipWhere.' GROUP BY visit.path ORDER BY views DESC, visit.path ASC LIMIT 10',
            $chartParameters,
        );

        return [
            'sessions' => $sessions, 'sessionCount' => $count, 'page' => $page, 'pageCount' => $pageCount,
            'countryNames' => Countries::getNames('fr'), 'countries' => $countries, 'topPages' => $topPages,
            'uniqueVisitors' => $uniqueVisitors,
            'days' => $days, 'chartDays' => $chartDays, 'ip' => $ip, 'invalidIp' => $invalidIp,
            'activeSince' => $now->modify('-5 minutes'), 'activeCount' => $activeCount,
            'periodSessions' => $periodSessions,
            'periodViews' => $periodViews, 'todayViews' => $chart[$today->format('Y-m-d')]['views'],
            'campaignViews' => $campaignViews, 'campaignShare' => $periodViews > 0 ? $campaignViews / $periodViews * 100 : 0,
            'averageDailyViews' => $periodViews / $chartDays, 'peakDay' => $peakDay,
            'trafficSources' => $contributions['sources'], 'trafficCampaigns' => $contributions['campaigns'],
            'chart' => array_values($chart), 'chartMax' => max(4, (int) ceil($maxValue / 4) * 4), 'updatedAt' => $now,
        ];
    }

    public function history(VisitorSession $session, Request $request): array
    {
        $repository = $this->entityManager->getRepository(PageVisit::class);
        $count = $repository->count(['session' => $session]);
        $pageCount = max(1, (int) ceil($count / 50));
        $page = min($pageCount, max(1, $request->query->getInt('page', 1)));

        return [
            'session' => $session,
            'countryName' => $session->getCountryCode() && Countries::exists($session->getCountryCode()) ? Countries::getName($session->getCountryCode(), 'fr') : 'Non déterminé',
            'visits' => $repository->findBy(['session' => $session], ['visitedAt' => 'ASC', 'id' => 'ASC'], 50, ($page - 1) * 50),
            'page' => $page, 'pageCount' => $pageCount,
            'otherSessions' => $this->entityManager->createQueryBuilder()->select('session')->from(VisitorSession::class, 'session')
                ->where('session.visitorKey = :key')->andWhere('session.id != :id')
                ->setParameter('key', $session->getVisitorKey())->setParameter('id', $session->getId())
                ->orderBy('session.startedAt', 'DESC')->setMaxResults(10)->getQuery()->getResult(),
            'updatedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
    }
}
