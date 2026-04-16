<?php

namespace App\Tests\Security\Voter;

use App\Entity\Credential;
use App\Entity\SharedAccess;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Security\Voter\CredentialVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CredentialVoterTest extends TestCase
{
    public function testOwnerCanViewEditAndDeleteCredential(): void
    {
        $owner = (new User())->setEmail('owner@example.test');
        $credential = (new Credential())
            ->setUser($owner)
            ->setName('Github')
            ->setDomain('github.com')
            ->setUsername('owner');

        $voter = new CredentialVoter();
        $token = $this->createToken($owner);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $credential, [CredentialVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $credential, [CredentialVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $credential, [CredentialVoter::DELETE]));
    }

    public function testDirectSharedGuestCanOnlyViewCredential(): void
    {
        $owner = (new User())->setEmail('owner@example.test');
        $guest = (new User())->setEmail('guest@example.test');

        $credential = (new Credential())
            ->setUser($owner)
            ->setName('Github')
            ->setDomain('github.com')
            ->setUsername('owner');

        $sharedAccess = (new SharedAccess())
            ->setOwner($owner)
            ->setGuest($guest)
            ->setCredential($credential)
            ->setCreatedAt(new \DateTimeImmutable());
        $credential->addSharedAccess($sharedAccess);

        $voter = new CredentialVoter();
        $token = $this->createToken($guest);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $credential, [CredentialVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $credential, [CredentialVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $credential, [CredentialVoter::DELETE]));
    }

    public function testTeamMemberCanOnlyViewSharedCredential(): void
    {
        $owner = (new User())->setEmail('owner@example.test');
        $memberUser = (new User())->setEmail('member@example.test');

        $credential = (new Credential())
            ->setUser($owner)
            ->setName('Github')
            ->setDomain('github.com')
            ->setUsername('owner');

        $team = (new Team())
            ->setName('Ops')
            ->setOwner($owner);
        $team->addCredential($credential);

        $member = (new TeamMember())
            ->setUser($memberUser)
            ->setRole(TeamRole::MEMBER);
        $team->addMember($member);

        $voter = new CredentialVoter();
        $token = $this->createToken($memberUser);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $credential, [CredentialVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $credential, [CredentialVoter::EDIT]));
    }

    public function testUnrelatedUserCannotAccessCredential(): void
    {
        $owner = (new User())->setEmail('owner@example.test');
        $outsider = (new User())->setEmail('outsider@example.test');

        $credential = (new Credential())
            ->setUser($owner)
            ->setName('Github')
            ->setDomain('github.com')
            ->setUsername('owner');

        $voter = new CredentialVoter();
        $token = $this->createToken($outsider);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $credential, [CredentialVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $credential, [CredentialVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $credential, [CredentialVoter::DELETE]));
    }

    private function createToken(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
