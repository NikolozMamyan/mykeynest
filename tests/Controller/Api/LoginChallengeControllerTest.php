<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\LoginChallenge;
use App\Entity\User;
use App\Entity\UserSession;
use App\Service\DeviceIdentifier;
use App\Service\LoginChallengeManager;
use App\Service\SessionManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class LoginChallengeControllerTest extends WebTestCase
{
    public function testApprovedChallengeCompletesThroughTheRouteAndOtherTabsRecoverTheSession(): void
    {
        [$client, $challenges, $sessions, $challenge] = $this->clientWithChallenge();
        $challenges->expects($this->once())->method('claimForCompletion')->with($challenge)->willReturn(true);
        $sessions->expects($this->once())->method('trustDevice');
        $sessions->expects($this->once())->method('createSession')->willReturn([
            (new UserSession())->setUser($challenge->getUser())->setExpiresAt(new \DateTimeImmutable('+1 day')), 'auth-token', $challenge->getDeviceId(),
        ]);
        $sessions->method('findActiveSessionByPlainToken')->with('auth-token')->willReturn(
            (new UserSession())->setUser($challenge->getUser()),
        );
        $client->request('POST', '/api/login-challenge/complete');
        self::assertResponseIsSuccessful();
        self::assertSame('/app/credential', json_decode($client->getResponse()->getContent(), true)['redirectUrl']);
        self::assertNotNull($client->getCookieJar()->get('AUTH_TOKEN'));
        self::assertNull($client->getCookieJar()->get(LoginChallengeManager::COOKIE_NAME));

        $client->request('GET', '/api/login-challenge/status');
        self::assertResponseIsSuccessful();
        self::assertSame('completed', json_decode($client->getResponse()->getContent(), true)['status']);
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $client->request('POST', '/api/login-challenge/complete');
        self::assertResponseIsSuccessful();
    }

    public function testCompletionRemainsBoundToTheOriginalDevice(): void
    {
        [$client, $challenges, $sessions] = $this->clientWithChallenge(false);
        $challenges->expects($this->never())->method('claimForCompletion');
        $sessions->expects($this->never())->method('createSession');
        $client->request('POST', '/api/login-challenge/complete');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('different_device', json_decode($client->getResponse()->getContent(), true)['status']);
        self::assertNull($client->getCookieJar()->get('AUTH_TOKEN'));
    }

    public function testCompletedChallengeCannotIssueAnotherSessionWithoutAuthentication(): void
    {
        [$client, $challenges, $sessions, $challenge] = $this->clientWithChallenge();
        $challenge->setStatus(LoginChallenge::STATUS_COMPLETED);
        $challenges->expects($this->never())->method('claimForCompletion');
        $sessions->expects($this->never())->method('createSession');
        $client->request('POST', '/api/login-challenge/complete');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('completed', json_decode($client->getResponse()->getContent(), true)['status']);
        self::assertNull($client->getCookieJar()->get('AUTH_TOKEN'));
    }

    /** @dataProvider approvalBrowsers */
    public function testEmailApprovalOnlyPollsInTheOriginalBrowser(bool $originalBrowser): void
    {
        [$client, , $sessions] = $this->clientWithChallenge($originalBrowser);
        if (!$originalBrowser) {
            $client->getCookieJar()->clear();
        }
        $sessions->expects($this->never())->method('createSession');
        $crawler = $client->request('GET', '/api/login-challenge/'.str_repeat('c', 64).'/approve?lang=fr');
        self::assertResponseIsSuccessful();
        $crawler = $client->submit($crawler->selectButton('Oui, approuver la connexion')->form());
        self::assertResponseIsSuccessful();
        self::assertSame('approved', $crawler->filter('[data-controller="pending-login"]')->attr('data-state'));
        self::assertSame($originalBrowser ? 'true' : 'false', $crawler->filter('[data-controller="pending-login"]')->attr('data-pending-login-poll-value'));
        self::assertStringContainsString('navigateur', $crawler->filter('[data-pending-login-target="message"]')->text());
    }

    public static function approvalBrowsers(): array
    {
        return [[true], [false]];
    }

    public function testMissingOrExpiredCookieShowsARecoverableNotFoundResponse(): void
    {
        [$client, , $sessions] = $this->clientWithChallenge();
        $client->getCookieJar()->clear();
        $sessions->expects($this->never())->method('createSession');
        $client->request('GET', '/api/login-challenge/status');
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    private function clientWithChallenge(bool $originalDevice = true): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = (new User())->setEmail('person@example.com');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $challenge = (new LoginChallenge())->setUser($user)->setDeviceId(str_repeat('a', 64))->setStatus(LoginChallenge::STATUS_APPROVED);
        $challenges = $this->createMock(LoginChallengeManager::class);
        $challenges->method('findValidByPlainToken')->willReturn($challenge);
        $challenges->method('findByPlainToken')->willReturn($challenge);
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('getCurrentDeviceId')->willReturn($originalDevice ? $challenge->getDeviceId() : str_repeat('b', 64));
        self::getContainer()->set(LoginChallengeManager::class, $challenges);
        self::getContainer()->set(SessionManager::class, $sessions);
        $client->getCookieJar()->set(new Cookie(LoginChallengeManager::COOKIE_NAME, str_repeat('c', 64), null, '/', 'localhost'));
        $client->getCookieJar()->set(new Cookie(DeviceIdentifier::COOKIE_NAME, $challenge->getDeviceId(), null, '/', 'localhost'));

        return [$client, $challenges, $sessions, $challenge];
    }
}
