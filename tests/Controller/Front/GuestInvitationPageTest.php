<?php

namespace App\Tests\Controller\Front;

use App\Entity\Credential;
use App\Entity\SharedAccess;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class GuestInvitationPageTest extends KernelTestCase
{
    public function testActivationKeepsInvitationAndRequiredFields(): void
    {
        $data = $this->pageData();
        $html = self::getContainer()->get('twig')->render('guest/register.html.twig', $data);
        $crawler = new Crawler($html);

        self::assertSame('preview-token', $crawler->filter('input[name="token"]')->attr('value'));
        self::assertCount(3, $crawler->filter('form input[required]'));
        self::assertSame('8', $crawler->filter('input[name="password"]')->attr('minlength'));
        self::assertCount(1, $crawler->filter('input[name="password_confirm"]'));
        self::assertStringContainsString('camille@example.invalid', $html);
        self::assertStringNotContainsString('guest_register.validation.', $html);
        self::assertSensitivePage($crawler, $html);
    }

    public function testPreviewNeverIncludesRestrictedPasswordOrCopyControl(): void
    {
        $data = $this->pageData();
        $html = self::getContainer()->get('twig')->render('guest/shared_preview.html.twig', $data);
        $crawler = new Crawler($html);

        self::assertStringContainsString('visible-secret-for-preview', $html);
        self::assertStringNotContainsString('restricted-secret-must-not-render', $html);
        self::assertCount(1, $crawler->filter('#guest-credential-1 [data-password-visible]'));
        self::assertCount(0, $crawler->filter('#guest-credential-2 [data-password-visible], #guest-credential-2 [data-action="guest-access#toggleSecret"]'));
        self::assertCount(1, $crawler->filter('#guest-credential-2 .ga-extension-cta'));
        self::assertCount(1, $crawler->filter('.ga-request-extension'));
        self::assertCount(2, $crawler->filter('[data-guest-access-target="choice"]'));
        self::assertStringContainsString('token=preview-token', $crawler->filter('.ga-preview-hero a')->attr('href'));
        parse_str(parse_url($crawler->filter('.ga-preview-hero a')->attr('href'), PHP_URL_QUERY), $query);
        self::assertSame('camille@example.invalid', $query['email']);
        self::assertSensitivePage($crawler, $html);
    }

    public function testEmptyEnglishInvitationKeepsActivationLink(): void
    {
        $data = $this->pageData('en');
        $data['sharedCredentials'] = [];
        $html = self::getContainer()->get('twig')->render('guest/shared_preview.html.twig', $data);
        $crawler = new Crawler($html);

        self::assertCount(1, $crawler->filter('.ga-no-access'));
        self::assertCount(1, $crawler->filter('.ga-preview-hero a'));
        self::assertStringContainsString('Activate my account', $html);
        self::assertStringNotContainsString('guest_access.', $crawler->text());
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
    }

    public function testExpiredShareDoesNotRenderUsernameOrSecret(): void
    {
        $data = $this->pageData();
        $data['sharedCredentials'][0]['sharedAccess']->setValidity(new \DateTimeImmutable('-1 minute'));
        $data['sharedCredentials'][0]['credential']->setUsername('expired-username-must-not-render');
        $html = self::getContainer()->get('twig')->render('guest/shared_preview.html.twig', $data);

        self::assertStringNotContainsString('visible-secret-for-preview', $html);
        self::assertStringNotContainsString('expired-username-must-not-render', $html);
        self::assertCount(1, (new Crawler($html))->filter('#guest-credential-1 .ga-expired-access'));
    }

    private static function assertSensitivePage(Crawler $crawler, string $html): void
    {
        self::assertSame('noindex,nofollow,noarchive', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('no-referrer', $crawler->filter('meta[name="referrer"]')->attr('content'));
        self::assertStringNotContainsString('googletagmanager.com', $html);
        self::assertStringNotContainsString('cdnjs.cloudflare.com', $html);
    }

    private function pageData(string $locale = 'fr'): array
    {
        self::bootKernel();
        $container = self::getContainer();
        $request = Request::create('/guest/register?token=preview-token&email=camille%40example.invalid');
        $request->setLocale($locale);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->get('translator')->setLocale($locale);
        $guest = (new User())->setEmail('camille@example.invalid')->setCompany('Atelier Nova')->setRoles(['ROLE_GUEST'])->setTokenExpiresAt(new \DateTimeImmutable('+3 days'));
        $owner = (new User())->setEmail('alex@example.invalid')->setCompany('Atelier Nova');
        $items = [];
        foreach (['Figma', 'Notion'] as $index => $name) {
            $credential = (new Credential())->setName($name)->setDomain(strtolower($name).'.com')->setUsername('studio@example.invalid')->setUser($owner);
            $share = (new SharedAccess())->setOwner($owner)->setGuest($guest)->setCredential($credential)->setCreatedAt(new \DateTimeImmutable('2026-11-05 10:30:00'))->setValidity(new \DateTimeImmutable('+3 days'));
            (new \ReflectionProperty(SharedAccess::class, 'id'))->setValue($share, $index + 1);
            $items[] = [
                'credential' => $credential,
                'owner' => $owner,
                'sharedAccess' => $share,
                'extensionUrl' => 'https://key-nest.com/guest/shared-access/'.($index + 1).'/extension?signature=preview',
                'canRevealPassword' => $index === 0,
                'decryptedPassword' => $index === 0 ? 'visible-secret-for-preview' : 'restricted-secret-must-not-render',
            ];
        }

        return ['user' => $guest, 'token' => 'preview-token', 'sharedCredentials' => $items];
    }
}
