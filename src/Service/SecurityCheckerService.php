<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Credential;
use App\Entity\Notification;
use App\Entity\SharedAccess;
use App\Repository\CredentialRepository;
use App\Repository\NotificationRepository;
use App\Repository\SharedAccessRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\RouterInterface;

class SecurityCheckerService
{
    public const ROTATION_DAYS_DEFAULT = 120; // ~4 mois

    public function __construct(
        private CredentialRepository $credentialRepository,
        private SharedAccessRepository $sharedAccessRepository,
        private EncryptionService $encryptionService,
        private Security $security,
        private NotificationService $notificationService,
        private NotificationRepository $notificationRepository,
        private RouterInterface $router,
    ) {}

    /**
     * @return array{
     *   overallScore:int,
     *   counts: array{total:int, weak:int, reused:int, expired:int, undecipherable:int},
     *   items: array<int, array<string,mixed>>
     * }
     */
    public function buildReportAndNotify(User $viewer, int $rotationDays = self::ROTATION_DAYS_DEFAULT): array
    {
        $report = $this->buildReport($viewer, $rotationDays);

        foreach (($report['items'] ?? []) as $item) {
            $score = (int) ($item['score'] ?? 100);

            if ($score < 40) {
                $credId = (int) ($item['id'] ?? 0);
                if ($credId <= 0) continue;

                $uniqueKey = 'security_checker_'.$viewer->getId().'_cred_'.$credId;

                if (!$this->notificationRepository->existsByUniqueKey($uniqueKey)) {
                    $name = (string) ($item['name'] ?? '');
                    $domain = (string) ($item['domain'] ?? '');

                    // ✅ On envoie des textes simples (ou tu peux faire traduire dans NotificationService)
                    // Ici je te mets la version "clé" en dur + params dans le message (prod propre)
                    $title = 'security_checker.notifications.weak.title';
                    $message = 'security_checker.notifications.weak.message';

                    $this->notificationService->createNotification(
                        $viewer,
                        $title,
                        $message,
                        type: Notification::TYPE_WARNING,
                        actionUrl: $this->router->generate('app_security_checker'),
                        icon: 'shield-exclamation',
                        priority: Notification::PRIORITY_HIGH,
                        uniqueKey: $uniqueKey,
                        // si ton NotificationService supporte des params:
                        // params: ['%name%' => $name, '%domain%' => $domain]
                    );
                }
            }
        }

        return $report;
    }

    public function buildReport(User $viewer, int $rotationDays = self::ROTATION_DAYS_DEFAULT): array
    {
        // 1) Credentials appartenant à l'utilisateur
        $owned = $this->credentialRepository->findBy(['user' => $viewer]);

        // 2) Credentials partagés vers l'utilisateur (guest)
        /** @var SharedAccess[] $shared */
        $shared = $this->sharedAccessRepository->findBy(['guest' => $viewer]);

        /** @var Credential[] $allCreds */
        $allCreds = $owned;
        foreach ($shared as $sa) {
            if ($sa->getCredential()) $allCreds[] = $sa->getCredential();
        }

        // Dédup
        $uniq = [];
        foreach ($allCreds as $c) $uniq[$c->getId()] = $c;
        $allCreds = array_values($uniq);

        // 1er passage : déchiffrer + hash reuse
        $plainById = [];
        $hashCounts = [];

        $viewerKey = $viewer->getApiExtensionToken() ?? '';

        foreach ($allCreds as $cred) {
            $owner = $cred->getUser();
            $ownerKey = $owner?->getApiExtensionToken();

            if (!$ownerKey) {
                $plainById[$cred->getId()] = null;
                continue;
            }

            $this->encryptionService->setKeyFromUserToken($ownerKey);
            $plain = $this->encryptionService->decrypt((string) $cred->getPassword());

            if ($viewerKey !== '') {
                $this->encryptionService->setKeyFromUserToken($viewerKey);
            }

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

            // ✅ issues/recs deviennent des "messages" structurés
            $issues = [];          // array<int, array{key:string, params:array}>
            $recommendations = []; // idem

            $reused = false;
            if ($plain) {
                $h = hash('sha256', $plain);
                $reused = ($hashCounts[$h] ?? 0) > 1;
                if ($reused) {
                    $issues[] = ['key' => 'security_checker.issues.reused', 'params' => []];
                    $recommendations[] = ['key' => 'security_checker.recs.unique_per_service', 'params' => []];
                }
            }

            if ($rotationDue) {
                $issues[] = ['key' => 'security_checker.issues.rotation_due', 'params' => ['%days%' => $rotationDays]];
                $recommendations[] = ['key' => 'security_checker.recs.plan_rotation', 'params' => []];
            }

            if ($plain === null) {
                $counts['undecipherable']++;
                $score = 0;
                $labelKey = 'unknown';
                $issues[] = ['key' => 'security_checker.issues.undecipherable', 'params' => []];
                $recommendations[] = ['key' => 'security_checker.recs.check_owner_key', 'params' => []];
            } else {
                [$score, $labelKey, $localIssues, $localRecs] = $this->scorePassword(
                    $plain,
                    (string) $cred->getUsername(),
                    (string) $cred->getDomain(),
                    (string) $cred->getName(),
                );

                $issues = $this->mergeMessages($issues, $localIssues);
                $recommendations = $this->mergeMessages($recommendations, $localRecs);

                $sumScore += $score;
                $countScored++;
            }

            if ($plain !== null && $score < 40) $counts['weak']++;
            if ($reused) $counts['reused']++;
            if ($rotationDue) $counts['expired']++;

            $items[] = [
                'id' => $cred->getId(),
                'name' => $cred->getName(),
                'domain' => $cred->getDomain(),
                'username' => $cred->getUsername(),
                'lastChangedAt' => $lastChangedAt,
                'ageDays' => $ageDays,
                'rotationDue' => $rotationDue,
                'score' => $score,

                // ✅ labelKey au lieu de 'Fort/Bon...'
                'labelKey' => $labelKey,

                // ✅ messages structurés
                'issues' => $issues,
                'recommendations' => $recommendations,

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
     * @return array{0:int,1:string,2:array<int,array{key:string,params:array}>,3:array<int,array{key:string,params:array}>}
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
            $issues[] = ['key' => 'security_checker.issues.too_short', 'params' => []];
            $recs[]   = ['key' => 'security_checker.recs.length_12_16', 'params' => []];
        }

        // Diversité
        $hasLower = (bool) preg_match('/[a-z]/', $pwd);
        $hasUpper = (bool) preg_match('/[A-Z]/', $pwd);
        $hasDigit = (bool) preg_match('/\d/', $pwd);
        $hasSymbol = (bool) preg_match('/[^a-zA-Z0-9]/', $pwd);

        $variety = (int)$hasLower + (int)$hasUpper + (int)$hasDigit + (int)$hasSymbol;
        $score += $variety * 10;

        if (!$hasUpper)  $recs[] = ['key' => 'security_checker.recs.add_upper', 'params' => []];
        if (!$hasLower)  $recs[] = ['key' => 'security_checker.recs.add_lower', 'params' => []];
        if (!$hasDigit)  $recs[] = ['key' => 'security_checker.recs.add_digit', 'params' => []];
        if (!$hasSymbol) $recs[] = ['key' => 'security_checker.recs.add_symbol', 'params' => []];

        // Trop commun
        $common = ['password','123456','123456789','qwerty','azerty','admin','welcome','iloveyou'];
        if (in_array(mb_strtolower($pwd), $common, true)) {
            $score -= 50;
            $issues[] = ['key' => 'security_checker.issues.too_common', 'params' => []];
            $recs[]   = ['key' => 'security_checker.recs.avoid_common', 'params' => []];
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
                $issues[] = ['key' => 'security_checker.issues.contains_account_info', 'params' => []];
                $recs[]   = ['key' => 'security_checker.recs.avoid_account_info', 'params' => []];
                break;
            }
        }

        // Séquences simples
        if (preg_match('/(0123|1234|2345|3456|4567|5678|6789|abcd|qwerty|azerty)/i', $pwd)) {
            $score -= 15;
            $issues[] = ['key' => 'security_checker.issues.simple_sequence', 'params' => []];
            $recs[]   = ['key' => 'security_checker.recs.avoid_sequences', 'params' => []];
        }

        // Répétitions
        if (preg_match('/(.)\1\1/', $pwd)) {
            $score -= 10;
            $issues[] = ['key' => 'security_checker.issues.repetitions', 'params' => []];
            $recs[]   = ['key' => 'security_checker.recs.avoid_repetitions', 'params' => []];
        }

        $score = max(0, min(100, $score));

        $labelKey = match (true) {
            $score >= 80 => 'strong',
            $score >= 60 => 'good',
            $score >= 40 => 'medium',
            default => 'weak',
        };

        if ($score < 40) {
            $issues[] = ['key' => 'security_checker.issues.weak_password', 'params' => []];
            $recs[]   = ['key' => 'security_checker.recs.use_passphrase_or_generator', 'params' => []];
        }

        return [$score, $labelKey, $issues, $recs];
    }

    /**
     * Merge sans doublons par key+params sérialisés
     * @param array<int,array{key:string,params:array}> $a
     * @param array<int,array{key:string,params:array}> $b
     * @return array<int,array{key:string,params:array}>
     */
    private function mergeMessages(array $a, array $b): array
    {
        $seen = [];
        $out = [];

        foreach (array_merge($a, $b) as $m) {
            $k = $m['key'].'|'.json_encode($m['params'] ?? [], JSON_UNESCAPED_UNICODE);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $m;
        }

        return $out;
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
