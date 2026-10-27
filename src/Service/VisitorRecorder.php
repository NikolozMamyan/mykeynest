<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final readonly class VisitorRecorder
{
    public function __construct(
        private Connection $connection,
        private VisitorGeolocation $geolocation,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function record(Request $request, ?\DateTimeImmutable $now = null): void
    {
        $ip = $request->getClientIp();
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        $agent = mb_substr($request->headers->get('User-Agent', ''), 0, 500);
        $key = hash_hmac('sha256', $ip."\0".$agent, $this->secret);
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s');
        $id = $this->connection->fetchOne(
            'SELECT id FROM visitor_session WHERE visitor_key = :key AND last_seen_at > :since AND last_seen_at <= :now ORDER BY last_seen_at DESC, id DESC LIMIT 1',
            ['key' => $key, 'since' => $now->modify('-30 minutes')->format('Y-m-d H:i:s'), 'now' => $timestamp],
        );
        $country = false === $id ? $this->geolocation->country($ip) : null;
        $path = mb_substr($request->getPathInfo(), 0, 1000);
        $query = $request->query->all();
        $utm = static fn (string $name, int $length): string => is_string($query[$name] ?? null) ? mb_substr(trim($query[$name]), 0, $length) : '';
        $googleAds = $request->query->has('gclid') || $request->query->has('gbraid') || $request->query->has('wbraid');
        $referrer = strtolower((string) parse_url($request->headers->get('Referer', ''), PHP_URL_HOST));
        $source = $utm('utm_source', 200);
        $medium = $utm('utm_medium', 100);
        if ('' === $source) {
            $source = $googleAds ? 'google' : ($referrer && $referrer !== $request->getHost() ? $referrer : 'direct');
        }
        if ('' === $medium) {
            $medium = $googleAds ? 'cpc' : ('direct' === $source ? '' : 'referral');
            if (!$googleAds && preg_match('/^(?:www\.)?(google\.[a-z.]+|bing\.com|search\.yahoo\.com|duckduckgo\.com)$/D', $referrer, $matches)) {
                $source = '' === $utm('utm_source', 200) ? explode('.', $matches[1])[0] : $source;
                $medium = 'organic';
            }
        }

        $this->connection->transactional(function (Connection $connection) use ($id, $ip, $agent, $key, $timestamp, $path, $country, $source, $medium, $utm): void {
            if (false === $id) {
                $connection->insert('visitor_session', [
                    'visitor_key' => $key, 'ip_address' => $ip, 'country_code' => $country, 'user_agent' => $agent,
                    'started_at' => $timestamp, 'last_seen_at' => $timestamp,
                    'entry_path' => $path, 'current_path' => $path, 'page_view_count' => 1,
                    'source' => $source, 'medium' => '' === $medium ? null : $medium,
                    'campaign' => $utm('utm_campaign', 200) ?: null,
                ]);
                $id = $connection->lastInsertId();
            } else {
                $connection->executeStatement(
                    'UPDATE visitor_session SET last_seen_at = :now, current_path = :path, page_view_count = page_view_count + 1 WHERE id = :id',
                    ['now' => $timestamp, 'path' => $path, 'id' => $id],
                );
            }
            $connection->insert('page_visit', ['session_id' => $id, 'path' => $path, 'visited_at' => $timestamp]);
        });
    }
}
