<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Countries;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class VisitorGeolocation
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        #[Autowire(service: 'limiter.visitor_geolocation')]
        private RateLimiterFactory $limiter,
    ) {
    }

    public function country(string $ip): ?string
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return null;
        }

        try {
            $item = $this->cache->getItem('visitor_country_'.hash('sha256', $ip));
            if ($item->isHit()) {
                return $item->get();
            }
            if (!$this->limiter->create('country-is')->consume()->isAccepted()) {
                return null;
            }

            $country = null;
            try {
                $data = $this->httpClient->request('GET', 'https://api.country.is/'.rawurlencode($ip), [
                    'timeout' => 1.5, 'max_duration' => 2, 'max_redirects' => 0,
                ])->toArray();
                if (isset($data['ip'], $data['country']) && is_string($data['ip']) && is_string($data['country'])
                    && false !== filter_var($data['ip'], FILTER_VALIDATE_IP)
                    && inet_pton($data['ip']) === inet_pton($ip) && Countries::exists($data['country'])) {
                    $country = $data['country'];
                }
            } catch (\Throwable) {
                // A failed lookup must not prevent recording the visit.
            }

            $this->cache->save($item->set($country)->expiresAfter(null === $country ? 60 : 604800));

            return $country;
        } catch (\Throwable) {
            return null;
        }
    }
}
