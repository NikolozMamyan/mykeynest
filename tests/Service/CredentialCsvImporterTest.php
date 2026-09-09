<?php

namespace App\Tests\Service;

use App\Entity\Credential;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Service\CredentialCsvImporter;
use App\Service\CredentialManager;
use App\Service\CredentialUrlPolicy;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CredentialCsvImporterTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
    }

    public function testItNormalizesAWebsiteAndEncryptsTheImportedPassword(): void
    {
        $repository = $this->createMock(CredentialRepository::class);
        $repository->expects(self::once())->method('findIdentityPairsByUser')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            },
        );
        $entityManager->expects(self::once())->method('flush');
        $encryption = new EncryptionService();
        $manager = new CredentialManager($encryption, $entityManager, new CredentialUrlPolicy());
        $importer = new CredentialCsvImporter($manager, $repository, new CredentialUrlPolicy(), new NullLogger());
        $user = (new User())->setEmail('import@example.test')->setPassword('hashed');
        $file = $this->csv(<<<'CSV'
name,url,username,password
"Example account","https://www.example.com/login","user@example.com","p,a;ss"
CSV);

        $result = $importer->import($file, $user, null, 0);

        self::assertNull($result['fatal']);
        self::assertSame(1, $result['imported']);
        self::assertInstanceOf(Credential::class, $persisted);
        self::assertSame('example.com', $persisted->getDomain());
        self::assertSame('https://www.example.com/login', $persisted->getLoginUrl());
        self::assertSame('user@example.com', $persisted->getUsername());
        self::assertNotSame('p,a;ss', $persisted->getPassword());
        self::assertSame('p,a;ss', $manager->decryptPassword($persisted));
    }

    public function testItSkipsAnExistingCredentialAfterDomainNormalization(): void
    {
        $repository = $this->createMock(CredentialRepository::class);
        $repository->expects(self::once())->method('findIdentityPairsByUser')->willReturn([
            ['domain' => 'example.com', 'username' => 'User@Example.com'],
        ]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $manager = new CredentialManager(new EncryptionService(), $entityManager, new CredentialUrlPolicy());
        $importer = new CredentialCsvImporter($manager, $repository, new CredentialUrlPolicy(), new NullLogger());
        $user = (new User())->setEmail('import@example.test')->setPassword('hashed');
        $file = $this->csv("name,domain,username,password\nExample,https://www.example.com/login,user@example.com,secret\n");

        $result = $importer->import($file, $user, null, 1);

        self::assertSame(0, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertSame('credential.import.results.duplicate', $result['items'][0]['key']);
    }

    /** @dataProvider supportedHeaders */
    public function testItRecognizesCommonPasswordManagerHeaders(string $csv): void
    {
        $repository = $this->createMock(CredentialRepository::class);
        $repository->expects(self::once())->method('findIdentityPairsByUser')->willReturn([]);
        $manager = new CredentialManager(
            new EncryptionService(),
            $this->createMock(EntityManagerInterface::class),
            new CredentialUrlPolicy(),
        );
        $importer = new CredentialCsvImporter($manager, $repository, new CredentialUrlPolicy(), new NullLogger());
        $user = (new User())->setEmail('import@example.test')->setPassword('hashed');

        $result = $importer->import($this->csv($csv), $user, 0, 0);

        self::assertNull($result['fatal']);
        self::assertSame('credential.import.results.limit_reached', $result['items'][0]['key']);
    }

    public static function supportedHeaders(): array
    {
        return [
            'Chrome' => ["name,url,username,password,note\nExample,https://example.com,user,secret,\n"],
            'Firefox' => ["url,username,password,httpRealm\nhttps://example.com,user,secret,\n"],
            'Bitwarden' => ["type,name,login_uri,login_username,login_password\nlogin,Example,https://example.com,user,secret\n"],
            '1Password' => ["Title,Url,Username,Password,OTPAuth\nExample,https://example.com,user,secret,\n"],
            'LastPass' => ["url,username,password,extra,name\nhttps://example.com,user,secret,,Example\n"],
            'Dashlane' => ["username,title,password,url\nuser,Example,secret,https://example.com\n"],
            'Excel separator directive' => ["sep=;\nname;domain;username;password\nExample;example.com;user;secret\n"],
            'French template' => ["nom,domaine,identifiant,mot de passe\nExemple,example.com,user,secret\n"],
        ];
    }

    public function testItRejectsAFileWithoutRequiredColumnsBeforeWriting(): void
    {
        $repository = $this->createMock(CredentialRepository::class);
        $repository->expects(self::never())->method('findIdentityPairsByUser');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $manager = new CredentialManager(new EncryptionService(), $entityManager, new CredentialUrlPolicy());
        $importer = new CredentialCsvImporter($manager, $repository, new CredentialUrlPolicy(), new NullLogger());
        $user = (new User())->setEmail('import@example.test')->setPassword('hashed');

        $result = $importer->import($this->csv("name,domain\nExample,example.com\n"), $user, null, 0);

        self::assertSame('credential.import.errors.missing_columns', $result['fatal']['key']);
        self::assertSame(0, $result['processed']);
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mykeynest_csv_');
        if ($path === false) {
            self::fail('Unable to create the temporary CSV file.');
        }

        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
