<?php

namespace App\Tests\Controller\Api;

use App\Controller\Api\Extention\ApiSharedController;
use App\Entity\Credential;
use App\Entity\ExtensionClient;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Repository\ExtensionClientRepository;
use App\Repository\SharedAccessRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class CredentialLaunchControllerTest extends KernelTestCase
{
    /** @dataProvider launchCases */
    public function testLaunchChecksAccountAccessDestinationAndSubmissionMode(int $expectedUser, bool $shared, bool $canRevealPassword, string $url, int $status, ?bool $submitAfterFill): void
    {
        [$controller, $repository] = $this->controller($shared, $canRevealPassword);
        $response = $controller->launch($this->request(['userId' => $expectedUser, 'url' => $url]), 10, $repository);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayNotHasKey('password', $data);
        if ($status === 200) {
            self::assertSame(10, $data['id']);
            self::assertSame('selected@example.test', $data['username']);
            self::assertSame($submitAfterFill, $data['submitAfterFill']);
            self::assertFalse($data['allowSubdomains']);
        }
    }

    public static function launchCases(): array
    {
        return [
            'wrong MYKEYNEST account' => [2, true, false, 'https://accounts.example.net/login', 409, null],
            'revoked access' => [1, false, false, 'https://accounts.example.net/login', 404, null],
            'unrelated host' => [1, true, false, 'https://evil.test/login', 422, null],
            'connection-only share' => [1, true, false, 'https://accounts.example.net/login', 200, true],
            'full-access share' => [1, true, true, 'https://accounts.example.net/login', 200, false],
            'credential domain is not used when a sign-in URL exists' => [1, true, false, 'https://example.com/login', 422, null],
        ];
    }

    public function testAutofillRejectsLookalikeDomainAndAccountSwitch(): void
    {
        [$controller, $repository] = $this->controller(true);
        self::assertSame(403, $controller->autofill($this->request(['domain' => 'example.com.evil.test', 'userId' => 1]), 10, $repository)->getStatusCode());
        self::assertSame(409, $controller->autofill($this->request(['domain' => 'example.com', 'userId' => 2]), 10, $repository)->getStatusCode());
        $response = $controller->autofill($this->request(['domain' => 'accounts.example.net', 'userId' => 1]), 10, $repository);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('test-password', $data['password']);
        self::assertSame('selected@example.test', $data['username']);
    }

    private function controller(bool $shared, bool $canRevealPassword = false): array
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $this->createMock(EntityManagerInterface::class);
        $container->set(EntityManagerInterface::class, $em);
        $user = (new User())->setEmail('viewer@example.test');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $owner = (new User())->setEmail('owner@example.test')->setCredentialEncryptionKey('test-key');
        $credential = (new Credential())->setUser($owner)->setDomain('example.com')->setLoginUrl('https://accounts.example.net/login')->setUsername('selected@example.test');
        (new \ReflectionProperty(Credential::class, 'id'))->setValue($credential, 10);
        $encryption = $container->get(EncryptionService::class);
        $encryption->setKeyFromUserSecret('test-key');
        $credential->setPassword($encryption->encrypt('test-password'));

        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($user);
        $container->set(UserRepository::class, $users);
        $clients = $this->createMock(ExtensionClientRepository::class);
        $client = (new ExtensionClient())->setUser($user)->setClientId('launch-test')->setClientSecretHash(hash('sha256', 'installation-test'));
        $clients->method('findByUserOrderByLastSeen')->willReturn([$client]);
        $clients->method('findOneByUserAndClientId')->willReturn($client);
        $container->set(ExtensionClientRepository::class, $clients);
        $shares = $this->createMock(SharedAccessRepository::class);
        $shares->method('userHasAccessToCredential')->willReturn($shared);
        $shares->method('userCanRevealPassword')->willReturn($canRevealPassword);
        $container->set(SharedAccessRepository::class, $shares);
        $teams = $this->createMock(TeamRepository::class);
        $teams->method('userHasTeamAccessToCredential')->willReturn(false);
        $container->set(TeamRepository::class, $teams);
        $repository = $this->createMock(CredentialRepository::class);
        $repository->method('find')->willReturn($credential);

        return [$container->get(ApiSharedController::class), $repository];
    }

    private function request(array $payload): Request
    {
        return Request::create('/extention/api/credentials/10/launch', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer launch-test-token',
            'HTTP_X_EXTENSION_CLIENT_ID' => 'launch-test',
            'HTTP_X_EXTENSION_INSTALLATION_TOKEN' => 'installation-test',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
