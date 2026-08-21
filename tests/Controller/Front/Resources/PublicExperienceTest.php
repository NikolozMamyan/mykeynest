<?php

namespace App\Tests\Controller\Front\Resources;

use App\Form\ResetPasswordRequestFormType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicExperienceTest extends WebTestCase
{
    /**
     * @dataProvider publicPageProvider
     */
    public function testPublicPagesUseTheSharedNavigationAndFooter(string $path): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.landing-nav');
        self::assertSelectorNotExists('.theme-btn');
        self::assertSelectorExists('footer.public-footer');
        self::assertStringNotContainsString(
            'Solution cybersécurité PME',
            $crawler->filter('nav.landing-nav')->text()
        );
        self::assertSame(
            1,
            $crawler->filter('footer.public-footer a[href="/install-app"]')->count(),
            sprintf('The mobile installation entry should appear once in the public footer on "%s".', $path)
        );
        self::assertSame(
            0,
            $crawler->filter('nav.landing-nav a[href="/install-app"]')->count(),
            sprintf('The mobile installation entry must not appear in the public menu on "%s".', $path)
        );
    }

    public function testHelpArticleUsesARealProductScreenshot(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $template = file_get_contents($projectDir.'/templates/help_center/article.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString("'creer-son-compte'", $template);
        self::assertStringContainsString("src: 'images/help/register-account.png'", $template);
        self::assertStringContainsString('class="art-guide-action"', $template);
        self::assertStringContainsString(
            'https://chromewebstore.google.com/detail/mykeynest/llckfoodkfccmibgmpfiodjkpincnfid',
            $template
        );
        self::assertFileExists($projectDir.'/public/images/help/register-account.png');
        self::assertFileExists($projectDir.'/public/images/help/install-mobile.png');
        self::assertFileExists($projectDir.'/public/images/help/password-generator.png');
        self::assertFileExists($projectDir.'/public/images/help/browser-extension.png');
    }

    /**
     * @dataProvider helpGuideActionProvider
     */
    public function testHelpGuidesExposeAWorkingDirectAction(string $articleSlug, string $routeName, string $expectedHref): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $projectDir = $container->getParameter('kernel.project_dir');
        $template = file_get_contents($projectDir.'/templates/help_center/article.html.twig');
        $router = $container->get('router');

        self::assertIsString($template);
        self::assertStringContainsString(
            sprintf("'%s': { href: path('%s')", $articleSlug, $routeName),
            $template
        );
        self::assertSame($expectedHref, $router->generate($routeName));
    }

    public function testMobileAndFirefoxGuidesOnlyAdvertiseAvailableInstallations(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $controller = file_get_contents($projectDir.'/src/Controller/Front/Resources/HelpCenterController.php');

        self::assertIsString($controller);
        self::assertStringContainsString('MYKEYNEST est une application web installable', $controller);
        self::assertStringContainsString('La version Firefox n’est pas encore publiée', $controller);
        self::assertStringNotContainsString('L\'application est disponible sur l\'<strong>App Store</strong>', $controller);
        self::assertStringNotContainsString('Allez sur <strong>addons.mozilla.org</strong>', $controller);
    }

    public function testComparisonShowsItsOfficialSources(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/comparatif-password-manager-entreprise');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('a[href="https://bitwarden.com/pricing/business/"]')->count());
        self::assertSame(1, $crawler->filter('a[href="https://1password.com/pricing/business"]')->count());
        self::assertSelectorTextContains('.cmp-methodology', 'Sources officielles');
    }

    public function testPasswordResetRequestUsesTheProfessionalLocalizedLayout(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/reset-password?lang=fr');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main.reset-password-main');
        self::assertSelectorExists('section.reset-password-card');
        self::assertSelectorTextContains('#reset-request-title', 'Mot de passe oublié');
        self::assertSelectorNotExists('[data-controller="support-chat"]');
        self::assertStringNotContainsString(
            'Solution cybersécurité PME',
            $crawler->filter('nav.landing-nav')->text()
        );
    }

    public function testPasswordResetRequestRejectsMalformedEmailAddresses(): void
    {
        self::bootKernel();
        $form = self::getContainer()->get('form.factory')->create(ResetPasswordRequestFormType::class);
        $form->submit(['email' => 'not-an-email']);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('email')->getErrors(true)->count());
    }

    public function testPasswordResetRequestAcceptsItsSessionCsrfTokenWithoutAReferer(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/reset-password?lang=fr');
        $csrfToken = $crawler->filter('#reset_password_request_form__token')->attr('value');

        self::assertNotNull($csrfToken);
        self::assertNotSame('csrf-token', $csrfToken);

        $client->request('POST', '/reset-password', [
            'reset_password_request_form' => [
                // Keep the request away from persistence: this assertion is
                // specifically about CSRF behavior without browser headers.
                'email' => 'not-an-email',
                '_token' => $csrfToken,
            ],
        ], [], [
            'HTTP_REFERER' => '',
            'HTTP_ORIGIN' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('jeton CSRF', (string) $client->getResponse()->getContent());
    }

    public static function publicPageProvider(): iterable
    {
        yield 'help center' => ['/help/center'];
        yield 'generator' => ['/generator'];
        yield 'comparison' => ['/comparatif-password-manager-entreprise'];
        yield 'audit' => ['/audit-cybersecurite-pme'];
        yield 'privacy' => ['/privacy'];
    }

    public static function helpGuideActionProvider(): iterable
    {
        yield 'account creation' => ['creer-son-compte', 'show_register', '/register'];
        yield 'mobile install' => ['application-mobile', 'app_install_app', '/install-app'];
        yield 'password generator' => ['utiliser-le-generateur', 'app_public_generator', '/generator'];
        yield 'subscription' => ['annuler-abonnement', 'app_subscription', '/app/subscription'];
    }
}
