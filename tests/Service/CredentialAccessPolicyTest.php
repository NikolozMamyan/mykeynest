<?php

namespace App\Tests\Service;

use App\Entity\Credential;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\SharedAccessRepository;
use App\Repository\TeamCredentialPermissionRepository;
use App\Repository\TeamRepository;
use App\Service\CredentialAccessPolicy;
use PHPUnit\Framework\TestCase;

final class CredentialAccessPolicyTest extends TestCase
{
    public function testOwnerCanAlwaysAccessRevealAndEdit(): void
    {
        $owner = (new User())->setEmail('owner@example.test');
        $credential = $this->credentialOwnedBy($owner);
        $policy = $this->createPolicy();

        self::assertTrue($policy->canAccess($owner, $credential));
        self::assertTrue($policy->canRevealPassword($owner, $credential));
        self::assertTrue($policy->canEdit($owner, $credential));
    }

    public function testConnectionOnlyDirectShareCanAutofillButCannotRevealOrEdit(): void
    {
        $owner = (new User())->setEmail('owner@example.test');
        $guest = (new User())->setEmail('guest@example.test');
        $credential = $this->credentialOwnedBy($owner);

        $sharedAccessRepository = $this->createMock(SharedAccessRepository::class);
        $sharedAccessRepository->method('userHasAccessToCredential')->with($guest, $credential)->willReturn(true);
        $sharedAccessRepository->method('userCanRevealPassword')->with($guest, $credential)->willReturn(false);

        $teamRepository = $this->createMock(TeamRepository::class);
        $teamRepository->method('userHasTeamAccessToCredential')->willReturn(false);
        $teamRepository->method('findTeamsForUserAndCredential')->willReturn([]);

        $policy = new CredentialAccessPolicy(
            $sharedAccessRepository,
            $teamRepository,
            $this->createMock(TeamCredentialPermissionRepository::class),
        );

        self::assertTrue($policy->canAccess($guest, $credential));
        self::assertFalse($policy->canRevealPassword($guest, $credential));
        self::assertFalse($policy->canEdit($guest, $credential));
    }

    public function testAnyFullAccessPathAllowsRevealWithoutAllowingEdit(): void
    {
        $owner = (new User())->setEmail('owner@example.test');
        $guest = (new User())->setEmail('guest@example.test');
        $credential = $this->credentialOwnedBy($owner);
        $team = (new Team())->setName('Partners')->setOwner($owner);

        $sharedAccessRepository = $this->createMock(SharedAccessRepository::class);
        $sharedAccessRepository->method('userCanRevealPassword')->willReturn(false);

        $teamRepository = $this->createMock(TeamRepository::class);
        $teamRepository->method('findTeamsForUserAndCredential')->with($guest, $credential)->willReturn([$team]);

        $permissionRepository = $this->createMock(TeamCredentialPermissionRepository::class);
        $permissionRepository->method('allowsPasswordReveal')->with($team, $credential)->willReturn(true);

        $policy = new CredentialAccessPolicy($sharedAccessRepository, $teamRepository, $permissionRepository);

        self::assertTrue($policy->canRevealPassword($guest, $credential));
        self::assertFalse($policy->canEdit($guest, $credential));
    }

    private function createPolicy(): CredentialAccessPolicy
    {
        return new CredentialAccessPolicy(
            $this->createMock(SharedAccessRepository::class),
            $this->createMock(TeamRepository::class),
            $this->createMock(TeamCredentialPermissionRepository::class),
        );
    }

    private function credentialOwnedBy(User $owner): Credential
    {
        return (new Credential())
            ->setUser($owner)
            ->setName('Account')
            ->setDomain('example.test')
            ->setUsername('owner');
    }
}
