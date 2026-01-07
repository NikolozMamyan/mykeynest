<?php

namespace App\Service;

use App\Entity\Credential;
use App\Entity\SharedAccess;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Repository\SharedAccessRepository;
use Symfony\Bundle\SecurityBundle\Security;

class SecurityCheckerService
{
    public const ROTATION_DAYS_DEFAULT = 120; // ~4 mois

    public function __construct(
        private CredentialRepository $credentialRepository,
        private SharedAccessRepository $sharedAccessRepository,
        private EncryptionService $encryptionService,
        private Security $security,
    ) {}

    /**
     * @return array{
     *   overallScore:int,
     *   counts: array{total:int, weak:int, reused:int, expired:int, undecipherable:int},
     *   items: array<int, array<string,mixed>>
     * }
     */
    public function buildReport(User $viewer, int $rotationDays = self::ROTATION_DAYS_DEFAULT): array
    {
        $viewerKey = $viewer->getApiExtensionToken() ?? 'default_key_if_no_user';

        // 1) Credentials appartenant à l'utilisateur
        $owned = $this->credentialRepository->findBy(['user' => $viewer]);

        // 2) Credentials partagés vers l'utilisateur (guest)
        /** @var SharedAccess[] $shared */
        $shared = $this->sharedAccessRepository->findBy(['guest' => $viewer]);

        /** @var Credential[] $allCreds */
        $allCreds = $owned;
        foreach ($shared as $sa) {
            if ($sa->getCredential()) {
                $allCreds[] = $sa->getCredential();
            }
        }

        // Dédup si un credential apparaît 2 fois
        $uniq = [];
        foreach ($allCreds as $c) {
            $uniq[$c->getId()] = $c;
        }
        $allCreds = array_values($uniq);

        // 1er passage : déchiffrer et calculer hash pour détecter réutilisation
        $plainById = [];
        $hashCounts = [];

        foreach ($allCreds as $cred) {
            $owner = $cred->getUser();
            $ownerKey = $owner?->getApiExtensionToken();

            if (!$ownerKey) {
                $plainById[$cred->getId()] = null;
                continue;
            }

            // Clé = token du owner (sinon impossible de déchiffrer un password chiffré par lui)
            $this->encryptionService->setEncryptionKey($ownerKey);
            $plain = $this->encryptionService->decrypt((string) $cred->getPassword());

            // Remettre la clé du viewer (propreté)
            $this->encryptionService->setEncryptionKey($viewerKey);

            $plainById[$cred->getId()] = $plain !== '' ? $plain : null;

            if ($plain !== '') {
                $h = hash('sha256', $plain);
                $hashCounts[$h] = ($hashCounts[$h] ?? 0) + 1;
            }
        }

        // 2e passage : analyse
        $items = [];
        $sumScore = 0;
        $countScored = 0;

        $counts = [
            'total' => count($allCreds),
            'weak' => 0,
            'reused' => 0,
            'expired' => 0,
            'undecipherable' => 0,
        ];

        foreach ($allCreds as $cred) {
            $plain = $plainById[$cred->getId()] ?? null;

            $lastChangedAt = $cred->getUpdatedAt() ?? $cred->getCreatedAt();
            $ageDays = $lastChangedAt ? (int) floor((time() - $lastChangedAt->getTimestamp()) / 86400) : null;
            $rotationDue = ($ageDays !== null) ? ($ageDays >= $rotationDays) : false;

            $issues = [];
            $recommendations = [];

            $reused = false;
            if ($plain) {
                $h = hash('sha256', $plain);
                $reused = ($hashCounts[$h] ?? 0) > 1;
                if ($reused) {
                    $issues[] = 'Mot de passe réutilisé';
                    $recommendations[] = 'Utilise un mot de passe unique par service (aucune réutilisation).';
                }
            }

            if ($rotationDue) {
                $issues[] = sprintf('Mot de passe à renouveler (>%d jours)', $rotationDays);
                $recommendations[] = 'Planifie un changement de mot de passe (rotation).';
            }

            if ($plain === null) {
                $counts['undecipherable']++;
                $score = 0;
                $label = 'Inconnu';
                $issues[] = 'Impossible de déchiffrer (clé manquante ou donnée invalide)';
                $recommendations[] = 'Vérifie que le owner a bien un apiExtensionToken et que le mot de passe chiffré est valide.';
            } else {
                [$score, $label, $localIssues, $localRecs] = $this->scorePassword(
                    $plain,
                    (string) $cred->getUsername(),
                    (string) $cred->getDomain(),
                    (string) $cred->getName(),
                );

                $issues = array_values(array_unique(array_merge($issues, $localIssues)));
                $recommendations = array_values(array_unique(array_merge($recommendations, $localRecs)));

                $sumScore += $score;
                $countScored++;
            }

            if ($plain !== null && $score < 40) {
                $counts['weak']++;
            }
            if ($reused) {
                $counts['reused']++;
            }
            if ($rotationDue) {
                $counts['expired']++;
            }

            $items[] = [
                'id' => $cred->getId(),
                'name' => $cred->getName(),
                'domain' => $cred->getDomain(),
                'username' => $cred->getUsername(),
                'lastChangedAt' => $lastChangedAt,
                'ageDays' => $ageDays,
                'rotationDue' => $rotationDue,
                'score' => $score,
                'label' => $label,
                'issues' => $issues,
                'recommendations' => $recommendations,
                // On masque toujours côté UI
                'passwordMasked' => $plain ? str_repeat('•', min(12, max(8, strlen($plain)))) : '—',
                'suggestedPassword' => $this->generateStrongPassword(18),
            ];
        }

        $overallScore = $countScored > 0 ? (int) round($sumScore / $countScored) : 0;

        return [
            'overallScore' => $overallScore,
            'counts' => $counts,
            'items' => $items,
        ];
    }

    /**
     * @return array{0:int,1:string,2:array,3:array}
     */
    private function scorePassword(string $pwd, string $username, string $domain, string $name): array
    {
        $issues = [];
        $recs = [];

        $len = mb_strlen($pwd);
        $score = 0;

        // Longueur
        if ($len >= 16) $score += 40;
        elseif ($len >= 12) $score += 30;
        elseif ($len >= 10) $score += 20;
        elseif ($len >= 8) $score += 10;
        else {
            $issues[] = 'Trop court';
            $recs[] = 'Passe à 12–16+ caractères (idéalement 16+).';
        }

        // Diversité
        $hasLower = (bool) preg_match('/[a-z]/', $pwd);
        $hasUpper = (bool) preg_match('/[A-Z]/', $pwd);
        $hasDigit = (bool) preg_match('/\d/', $pwd);
        $hasSymbol = (bool) preg_match('/[^a-zA-Z0-9]/', $pwd);

        $variety = (int)$hasLower + (int)$hasUpper + (int)$hasDigit + (int)$hasSymbol;
        $score += $variety * 10;

        if (!$hasUpper) $recs[] = 'Ajoute au moins une majuscule.';
        if (!$hasLower) $recs[] = 'Ajoute au moins une minuscule.';
        if (!$hasDigit) $recs[] = 'Ajoute au moins un chiffre.';
        if (!$hasSymbol) $recs[] = 'Ajoute au moins un caractère spécial (ex: !@#…).';

        // Mot de passe “trop commun” (mini liste)
        $common = ['password','123456','123456789','qwerty','azerty','admin','welcome','iloveyou'];
        if (in_array(mb_strtolower($pwd), $common, true)) {
            $score -= 50;
            $issues[] = 'Mot de passe trop commun';
            $recs[] = 'Évite les mots de passe connus/communs (faciles à deviner).';
        }

        // Contient username / nom / domaine
        $u = mb_strtolower($username);
        $n = mb_strtolower($name);
        $d = mb_strtolower(preg_replace('#^https?://#', '', $domain));
        $p = mb_strtolower($pwd);

        $leaks = array_filter([$u, $n, $d], fn($x) => $x && mb_strlen($x) >= 3);
        foreach ($leaks as $token) {
            if (str_contains($p, $token)) {
                $score -= 20;
                $issues[] = 'Contient des infos liées au compte';
                $recs[] = 'Évite username/nom/domaine dans le mot de passe.';
                break;
            }
        }

        // Séquences simples
        if (preg_match('/(0123|1234|2345|3456|4567|5678|6789|abcd|qwerty|azerty)/i', $pwd)) {
            $score -= 15;
            $issues[] = 'Séquence simple détectée';
            $recs[] = 'Évite les suites (1234, azerty, qwerty…).';
        }

        // Répétitions
        if (preg_match('/(.)\1\1/', $pwd)) {
            $score -= 10;
            $issues[] = 'Répétitions détectées';
            $recs[] = 'Évite les répétitions (aaa, 111…).';
        }

        $score = max(0, min(100, $score));
        $label = match (true) {
            $score >= 80 => 'Fort',
            $score >= 60 => 'Bon',
            $score >= 40 => 'Moyen',
            default => 'Faible',
        };

        if ($score < 40) {
            $issues[] = 'Mot de passe faible';
            $recs[] = 'Utilise une phrase de passe (4+ mots) ou un générateur.';
        }

        return [$score, $label, array_values(array_unique($issues)), array_values(array_unique($recs))];
    }

    private function generateStrongPassword(int $length = 18): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}:;,.?';
        $max = strlen($alphabet) - 1;

        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
