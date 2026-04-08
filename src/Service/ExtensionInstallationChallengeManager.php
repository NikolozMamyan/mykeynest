<?php

namespace App\Service;

use App\Entity\ExtensionInstallationChallenge;
use App\Entity\User;
use App\Repository\ExtensionInstallationChallengeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class ExtensionInstallationChallengeManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private ExtensionInstallationChallengeRepository $repository
    ) {
    }

    public function createChallenge(User $user, Request $request, string $clientId): array
    {
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $challenge = new ExtensionInstallationChallenge();
        $challenge->setUser($user);
        $challenge->setTokenHash($tokenHash);
        $challenge->setRequestedClientId($clientId);
        $challenge->setDeviceLabel($this->cleanNullable($request->headers->get('X-Device-Label')));
        $challenge->setBrowserName($this->cleanNullable($request->headers->get('X-Browser-Name')));
        $challenge->setBrowserVersion($this->cleanNullable($request->headers->get('X-Browser-Version')));
        $challenge->setOsName($this->cleanNullable($request->headers->get('X-OS-Name')));
        $challenge->setOsVersion($this->cleanNullable($request->headers->get('X-OS-Version')));
        $challenge->setExtensionVersion($this->cleanNullable($request->headers->get('X-Extension-Version')));
        $challenge->setManifestVersion($this->cleanNullable($request->headers->get('X-Extension-Manifest-Version')));
        $challenge->setOriginType($this->cleanNullable($request->headers->get('X-Extension-Origin')));
        $challenge->setIpAddress($request->getClientIp());
        $challenge->setUserAgent($request->headers->get('User-Agent'));

        $this->em->persist($challenge);
        $this->em->flush();

        return [$challenge, $plainToken];
    }

    public function findLatestByUserAndClientId(User $user, string $clientId): ?ExtensionInstallationChallenge
    {
        return $this->repository->findLatestByUserAndClientId($user, $clientId);
    }

    public function findByPlainToken(string $plainToken): ?ExtensionInstallationChallenge
    {
        return $this->repository->findOneBy([
            'tokenHash' => hash('sha256', $plainToken),
        ]);
    }

    public function findValidByPlainToken(string $plainToken): ?ExtensionInstallationChallenge
    {
        $challenge = $this->findByPlainToken($plainToken);

        if (!$challenge) {
            return null;
        }

        if ($challenge->isExpired() && $challenge->getStatus() === ExtensionInstallationChallenge::STATUS_PENDING) {
            $challenge->setStatus(ExtensionInstallationChallenge::STATUS_EXPIRED);
            $this->em->flush();
        }

        if ($challenge->isExpired()) {
            return null;
        }

        return $challenge;
    }

    public function approve(ExtensionInstallationChallenge $challenge): void
    {
        if ($challenge->isExpired()) {
            $challenge->setStatus(ExtensionInstallationChallenge::STATUS_EXPIRED);
            $this->em->flush();

            return;
        }

        if (!$challenge->isPending()) {
            return;
        }

        $challenge->setStatus(ExtensionInstallationChallenge::STATUS_APPROVED);
        $challenge->setApprovedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function reject(ExtensionInstallationChallenge $challenge): void
    {
        if ($challenge->isExpired()) {
            $challenge->setStatus(ExtensionInstallationChallenge::STATUS_EXPIRED);
            $this->em->flush();

            return;
        }

        if (!$challenge->isPending()) {
            return;
        }

        $challenge->setStatus(ExtensionInstallationChallenge::STATUS_REJECTED);
        $challenge->setRejectedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function complete(ExtensionInstallationChallenge $challenge): void
    {
        $challenge->setStatus(ExtensionInstallationChallenge::STATUS_COMPLETED);
        $challenge->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function cleanNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }
}
