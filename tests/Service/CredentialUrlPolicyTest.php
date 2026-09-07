<?php

namespace App\Tests\Service;

use App\Entity\Credential;
use App\Service\CredentialManager;
use App\Service\CredentialUrlPolicy;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class CredentialUrlPolicyTest extends TestCase
{
    /** @dataProvider invalidUrls */
    public function testUnsafeAddressesAreRejected(string $url): void
    {
        self::assertNull((new CredentialUrlPolicy())->normalize($url));
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        self::assertGreaterThan(0, $validator->validateProperty((new Credential())->setLoginUrl($url), 'loginUrl')->count());
    }

    public static function invalidUrls(): array
    {
        return [
            ['javascript:alert(1)'],
            ['data:text/html,test'],
            ['https://user:password@example.com/login'],
            ['https://example.com\\@evil.test'],
            ["https://example.com/\nlogin"],
            ['https://example.com/' . str_repeat('a', 2048)],
        ];
    }

    public function testDomainAndExplicitSignInHostAreAllowedWithoutLookalikes(): void
    {
        $urls = new CredentialUrlPolicy();
        $credential = (new Credential())->setDomain('example.com')->setLoginUrl('https://accounts.example.net/login');
        self::assertSame('https://example.com/login', $urls->normalize(' example.com/login '));
        self::assertTrue($urls->allows($credential, 'https://www.example.com/login'));
        self::assertTrue($urls->allows($credential, 'https://login.example.com/'));
        self::assertTrue($urls->allows($credential, 'https://accounts.example.net/step-two'));
        self::assertFalse($urls->allows($credential, 'https://example.com.evil.test'));
        self::assertFalse($urls->allows($credential, 'https://evil-example.com'));
        self::assertFalse($urls->allows($credential, 'http://accounts.example.net/'));
        self::assertFalse($urls->allows($credential, 'https://accounts.example.net:444/'));
    }

    public function testSavingAndClearingTheUrlPreserveTheEncryptedPassword(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('flush');
        $encryption = $this->createMock(EncryptionService::class);
        $encryption->expects(self::never())->method('encrypt');
        $encryption->expects(self::never())->method('decrypt');
        $manager = new CredentialManager($encryption, $em);
        $credential = (new Credential())->setPassword('encrypted-password');
        $manager->updateLoginUrl($credential, 'https://example.com/login');
        self::assertSame('https://example.com/login', $credential->getLoginUrl());
        $manager->updateLoginUrl($credential, null);
        self::assertNull($credential->getLoginUrl());
        self::assertSame('encrypted-password', $credential->getPassword());
    }
}
