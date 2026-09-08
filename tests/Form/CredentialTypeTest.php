<?php

namespace App\Tests\Form;

use App\Entity\Credential;
use App\Form\CredentialType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class CredentialTypeTest extends KernelTestCase
{
    public function testACompleteUrlBecomesASiteAndAnAutomaticLaunchPage(): void
    {
        self::bootKernel();
        $credential = new Credential();
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(CredentialType::class, $credential, [
            'csrf_protection' => false,
        ]);

        $form->submit([
            'name' => 'Example',
            'domain' => 'https://www.example.com/auth/login?source=app',
            'loginUrl' => '',
            'username' => 'user@example.com',
            'password' => 'test-password',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('example.com', $credential->getDomain());
        self::assertSame('https://www.example.com/auth/login?source=app', $credential->getLoginUrl());
    }

    public function testChangingTheSiteClearsAnObsoleteLaunchPage(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $credential = (new Credential())
            ->setName('Example')
            ->setDomain('example.com')
            ->setLoginUrl('https://login.example.com/sign-in')
            ->setUsername('user@example.com')
            ->setPassword('encrypted-password');
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(CredentialType::class, $credential, [
            'csrf_protection' => false,
            'is_edit' => true,
        ]);

        $form->submit([
            'name' => 'Other',
            'domain' => 'other.example',
            'loginUrl' => 'https://login.example.com/sign-in',
            'username' => 'user@other.example',
            'password' => '',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('other.example', $credential->getDomain());
        self::assertNull($credential->getLoginUrl());
    }
}
