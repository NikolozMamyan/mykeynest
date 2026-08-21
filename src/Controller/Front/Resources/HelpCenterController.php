<?php

namespace App\Controller\Front\Resources;

use App\Service\SubscriptionPlanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

final class HelpCenterController extends AbstractController
{
    public function __construct(private readonly SubscriptionPlanService $subscriptionPlans)
    {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // i18n helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function pick(mixed $value, string $locale): mixed
    {
        // If already a scalar/string (not localized), return as-is
        if (!is_array($value)) return $value;

        // Localized array: ['fr' => '...', 'en' => '...']
        if (array_key_exists($locale, $value)) return $value[$locale];

        // Fallbacks
        if (array_key_exists('en', $value)) return $value['en'];
        if (array_key_exists('fr', $value)) return $value['fr'];

        // Last resort: first element
        return reset($value);
    }

    private function localizeCategory(array $category, string $locale): array
    {
        $category['title'] = $this->pick($category['title'], $locale);
        $category['description'] = $this->pick($category['description'], $locale);
        return $category;
    }

    private function localizeArticle(array $article, string $locale): array
    {
        $article['title'] = $this->pick($article['title'], $locale);
        $article['excerpt'] = $this->pick($article['excerpt'], $locale);
        $article['author'] = $this->pick($article['author'], $locale);

        $article['sections'] = array_map(function ($section) use ($locale) {
            $section['title'] = $this->pick($section['title'], $locale);
            $section['content'] = $this->pick($section['content'], $locale);
            return $section;
        }, $article['sections'] ?? []);

        return $article;
    }

    private function localizePopular(array $item, string $locale): array
    {
        $item['title'] = $this->pick($item['title'], $locale);
        $item['categoryTitle'] = $this->pick($item['categoryTitle'], $locale);
        return $item;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data (FR/EN) — later move to DB/repo
    // ─────────────────────────────────────────────────────────────────────────

    private function getAllCategories(): array
    {
        return [
            [
                'slug' => 'demarrer',
                'iconClass' => 'fa-solid fa-rocket',
                'title' => [
                    'fr' => 'Démarrer avec MYKEYNEST',
                    'en' => 'Getting started with MYKEYNEST',
                ],
                'description' => [
                    'fr' => 'Création de compte, installation, premiers mots de passe et prise en main rapide.',
                    'en' => 'Account creation, setup, first passwords, and a quick start guide.',
                ],
                'tags' => 'démarrer commencer installer compte créer importer',
                'articleCount' => 4,
            ],
            [
                'slug' => 'securite',
                'iconClass' => 'fa-solid fa-lock',
                'title' => [
                    'fr' => 'Sécurité & Chiffrement',
                    'en' => 'Security & Encryption',
                ],
                'description' => [
                    'fr' => 'Chiffrement AES-256-GCM, sécurité du compte, 2FA et bonnes pratiques.',
                    'en' => 'AES-256-GCM encryption, account security, 2FA, and best practices.',
                ],
                'tags' => 'sécurité mot de passe master chiffrement aes authentification 2fa',
                'articleCount' => 5,
            ],
            [
                'slug' => 'generateur',
                'iconClass' => 'fa-solid fa-key',
                'title' => [
                    'fr' => 'Générateur de mots de passe',
                    'en' => 'Password generator',
                ],
                'description' => [
                    'fr' => 'Créez des mots de passe ultra-sécurisés en un clic avec notre générateur gratuit.',
                    'en' => 'Create ultra-secure passwords in one click with our free generator.',
                ],
                'tags' => 'générateur mot de passe fort créer générer symboles longueur',
                'articleCount' => 3,
            ],
            [
                'slug' => 'partage',
                'iconClass' => 'fa-solid fa-share-nodes',
                'title' => [
                    'fr' => 'Partage & Collaboration',
                    'en' => 'Sharing & Collaboration',
                ],
                'description' => [
                    'fr' => 'Partagez des identifiants en toute sécurité avec vos proches ou votre équipe.',
                    'en' => 'Share credentials securely with your family or your team.',
                ],
                'tags' => 'partage identifiants équipe famille collaborer révoquer',
                'articleCount' => 3,
            ],
            [
                'slug' => 'extension',
                'iconClass' => 'fa-solid fa-puzzle-piece',
                'title' => [
                    'fr' => 'Extension Navigateur',
                    'en' => 'Browser extension',
                ],
                'description' => [
                    'fr' => 'Installer, configurer et utiliser l\'extension pour le remplissage automatique.',
                    'en' => 'Install, configure, and use the extension for auto-fill.',
                ],
                'tags' => 'extension navigateur chrome firefox safari auto-remplissage autofill',
                'articleCount' => 3,
            ],
            [
                'slug' => 'abonnement',
                'iconClass' => 'fa-solid fa-credit-card',
                'title' => [
                    'fr' => 'Abonnement & Facturation',
                    'en' => 'Subscription & Billing',
                ],
                'description' => [
                    'fr' => 'Offres, paiements, factures et gestion de votre abonnement Pro.',
                    'en' => 'Plans, payments, invoices, and managing your Pro subscription.',
                ],
                'tags' => 'abonnement pro facturation paiement offre tarif stripe',
                'articleCount' => 3,
            ],
        ];
    }

    private function getAllArticles(): array
    {
        $freePlan = $this->subscriptionPlans->getPlan(SubscriptionPlanService::PLAN_FREE);
        $freeCredentialLimit = $freePlan['limits'][SubscriptionPlanService::LIMIT_CREDENTIALS];
        $freeShareLimit = $freePlan['limits'][SubscriptionPlanService::LIMIT_SHARES];

        $shareExcerptFr = $freeShareLimit === null
            ? 'L’offre gratuite permet actuellement des partages actifs illimités.'
            : sprintf('L’offre gratuite permet jusqu’à %d partages actifs. Le plan Pro donne accès aux partages illimités.', $freeShareLimit);
        $shareExcerptEn = $freeShareLimit === null
            ? 'The Free plan currently includes unlimited active sharing.'
            : sprintf('The Free plan allows up to %d active shares. The Pro plan unlocks unlimited sharing.', $freeShareLimit);
        $shareContentFr = $freeShareLimit === null
            ? '<p>Le plan Free autorise actuellement des <strong>partages actifs illimités</strong>.</p>'
            : sprintf('<p>Le plan Free autorise <strong>%d partages actifs</strong> en simultané. Si vous atteignez cette limite, révoquez un partage existant avant d’en créer un nouveau, ou passez au plan Pro pour des partages illimités.</p>', $freeShareLimit);
        $shareContentEn = $freeShareLimit === null
            ? '<p>The Free plan currently includes <strong>unlimited active sharing</strong>.</p>'
            : sprintf('<p>The Free plan allows <strong>%d active shares</strong> at the same time. If you reach this limit, revoke an existing share before creating a new one, or upgrade to Pro for unlimited sharing.</p>', $freeShareLimit);
        $credentialComparisonFr = $freeCredentialLimit === null ? 'illimités en Free' : sprintf('%d en Free', $freeCredentialLimit);
        $credentialComparisonEn = $freeCredentialLimit === null ? 'unlimited on Free' : sprintf('%d on Free', $freeCredentialLimit);
        $shareComparisonFr = $freeShareLimit === null ? 'illimités en Free' : sprintf('%d en Free', $freeShareLimit);
        $shareComparisonEn = $freeShareLimit === null ? 'unlimited on Free' : sprintf('%d on Free', $freeShareLimit);
        $freeHasSecurityChecker = $freePlan['features'][SubscriptionPlanService::FEATURE_SECURITY_CHECKER];
        $securityAccessFr = $freeHasSecurityChecker ? 'inclus au plan Free' : 'plan Pro requis';
        $securityAccessEn = $freeHasSecurityChecker ? 'included in the Free plan' : 'Pro plan required';

        $proAdvantagesFr = ['Authentification 2FA', 'Support prioritaire'];
        $proAdvantagesEn = ['2FA authentication', 'Priority support'];
        if ($freeCredentialLimit !== null) {
            array_unshift($proAdvantagesFr, sprintf('Mots de passe <strong>illimités</strong> (vs %s)', $credentialComparisonFr));
            array_unshift($proAdvantagesEn, sprintf('<strong>Unlimited</strong> passwords (vs %s)', $credentialComparisonEn));
        }
        if ($freeShareLimit !== null) {
            array_splice($proAdvantagesFr, 1, 0, [sprintf('Partages <strong>illimités</strong> (vs %s)', $shareComparisonFr)]);
            array_splice($proAdvantagesEn, 1, 0, [sprintf('<strong>Unlimited</strong> sharing (vs %s)', $shareComparisonEn)]);
        }

        $featureAdvantages = [
            SubscriptionPlanService::FEATURE_PASSWORD_GENERATOR => ['Générateur de mots de passe', 'Password generator'],
            SubscriptionPlanService::FEATURE_SECURE_NOTES => ['Notes sécurisées', 'Secure notes'],
            SubscriptionPlanService::FEATURE_SECURITY_CHECKER => ['Audit de sécurité', 'Security audit'],
            SubscriptionPlanService::FEATURE_CREDENTIAL_IMPORT => ['Import CSV', 'CSV import'],
        ];
        foreach ($featureAdvantages as $feature => [$labelFr, $labelEn]) {
            if (($freePlan['features'][$feature] ?? false) === false) {
                $proAdvantagesFr[] = $labelFr;
                $proAdvantagesEn[] = $labelEn;
            }
        }

        $planComparisonContentFr = '<p>Tous les plans incluent le chiffrement AES-256 et la synchronisation. Le plan Pro propose :</p><ul><li>'
            . implode('</li><li>', $proAdvantagesFr)
            . '</li></ul>';
        $planComparisonContentEn = '<p>All plans include AES-256 encryption and synchronization. The Pro plan provides:</p><ul><li>'
            . implode('</li><li>', $proAdvantagesEn)
            . '</li></ul>';

        return [
            'demarrer' => [
                'creer-son-compte' => [
                    'slug' => 'creer-son-compte',
                    'title' => [
                        'fr' => 'Comment créer son compte MYKEYNEST ?',
                        'en' => 'How to create your MYKEYNEST account?',
                    ],
                    'excerpt' => [
                        'fr' => 'Guide étape par étape pour créer votre compte, choisir un mot de passe maître solide et commencer à stocker vos identifiants.',
                        'en' => 'A step-by-step guide to create your account, choose a strong master password, and start saving your credentials.',
                    ],
                    'readTime' => 3,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['démarrer', 'compte', 'inscription'],
                    'updatedAt' => new \DateTime('2026-01-20'),
                    'helpfulYes' => 210,
                    'helpfulNo' => 4,
                    'sections' => [
                        [
                            'id' => 'inscription',
                            'title' => [
                                'fr' => 'Créer votre compte',
                                'en' => 'Create your account',
                            ],
                            'content' => [
                                'fr' => '<p>Rendez-vous sur <strong>key-nest.com</strong> et cliquez sur <strong>Essai gratuit</strong>. Renseignez votre adresse email et choisissez un mot de passe principal robuste et unique.</p><div class="art-callout"><span class="art-callout-icon"><i class="fa-solid fa-lightbulb"></i></span><span class="art-callout-text"><strong>Astuce :</strong> utilisez une phrase de passe longue, activez la 2FA si votre offre le permet et ne réutilisez jamais ce mot de passe sur un autre service.</span></div>',
                                'en' => '<p>Go to <strong>key-nest.com</strong> and click <strong>Free trial</strong>. Enter your email address and choose a strong, unique primary password.</p><div class="art-callout"><span class="art-callout-icon"><i class="fa-solid fa-lightbulb"></i></span><span class="art-callout-text"><strong>Tip:</strong> use a long passphrase, enable 2FA when your plan supports it and never reuse this password on another service.</span></div>',
                            ],
                        ],
                        [
                            'id' => 'verification',
                            'title' => [
                                'fr' => 'Vérification de l\'email',
                                'en' => 'Email verification',
                            ],
                            'content' => [
                                'fr' => '<p>Un email de confirmation vous est envoyé immédiatement. Cliquez sur le lien dans les 24h pour activer votre compte. Si vous ne le trouvez pas, vérifiez votre dossier spam.</p>',
                                'en' => '<p>A confirmation email is sent immediately. Click the link within 24 hours to activate your account. If you can’t find it, check your spam folder.</p>',
                            ],
                        ],
                        [
                            'id' => 'premier-identifiant',
                            'title' => [
                                'fr' => 'Ajouter votre premier identifiant',
                                'en' => 'Add your first credential',
                            ],
                            'content' => [
                                'fr' => '<p>Une fois connecté, cliquez sur <strong>+ Nouvel identifiant</strong>. Renseignez le site, l\'email et le mot de passe. Le secret est chiffré avec AES-256-GCM avant sa persistance.</p>',
                                'en' => '<p>Once logged in, click <strong>+ New credential</strong>. Enter the website, email/username and password. The secret is encrypted with AES-256-GCM before it is persisted.</p>',
                            ],
                        ],
                    ],
                ],

                'importer-identifiants' => [
                    'slug' => 'importer-identifiants',
                    'title' => [
                        'fr' => 'Importer mes mots de passe depuis un autre gestionnaire',
                        'en' => 'Import passwords from another password manager',
                    ],
                    'excerpt' => [
                        'fr' => 'Migrez vos mots de passe depuis Chrome, Firefox, Bitwarden, 1Password ou LastPass en quelques clics grâce à l\'import CSV.',
                        'en' => 'Migrate your passwords from Chrome, Firefox, Bitwarden, 1Password, or LastPass in a few clicks using CSV import.',
                    ],
                    'readTime' => 4,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['import', 'migration', 'CSV'],
                    'updatedAt' => new \DateTime('2026-01-18'),
                    'helpfulYes' => 178,
                    'helpfulNo' => 9,
                    'sections' => [
                        [
                            'id' => 'formats-supportes',
                            'title' => [
                                'fr' => 'Formats supportés',
                                'en' => 'Supported formats',
                            ],
                            'content' => [
                                'fr' => '<p>MYKEYNEST accepte les exports CSV de : Google Chrome, Mozilla Firefox, Bitwarden, 1Password, LastPass et Dashlane.</p>',
                                'en' => '<p>MYKEYNEST supports CSV exports from: Google Chrome, Mozilla Firefox, Bitwarden, 1Password, LastPass, and Dashlane.</p>',
                            ],
                        ],
                        [
                            'id' => 'export-chrome',
                            'title' => [
                                'fr' => 'Exporter depuis Chrome',
                                'en' => 'Export from Chrome',
                            ],
                            'content' => [
                                'fr' => '<p>Dans Chrome, allez dans <code>chrome://password-manager/passwords</code>, cliquez sur les paramètres puis <strong>Exporter les mots de passe</strong>.<div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">Le fichier CSV contient vos mots de passe <strong>en clair</strong>. Supprimez-le immédiatement après l\'import.</span></div>',
                                'en' => '<p>In Chrome, go to <code>chrome://password-manager/passwords</code>, open settings, then click <strong>Export passwords</strong>.<div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">The CSV file contains your passwords in <strong>plain text</strong>. Delete it immediately after importing.</span></div>',
                            ],
                        ],
                        [
                            'id' => 'importer',
                            'title' => [
                                'fr' => 'Importer dans MYKEYNEST',
                                'en' => 'Import into MYKEYNEST',
                            ],
                            'content' => [
                                'fr' => '<p>Depuis votre tableau de bord, allez dans <strong>Paramètres › Import</strong>. Sélectionnez votre source, choisissez le fichier CSV et validez. Tout sera chiffré et importé automatiquement.</p>',
                                'en' => '<p>From your dashboard, go to <strong>Settings › Import</strong>. Select your source, choose the CSV file, and confirm. Everything will be encrypted and imported automatically.</p>',
                            ],
                        ],
                    ],
                ],

                'synchronisation-appareils' => [
                    'slug' => 'synchronisation-appareils',
                    'title' => [
                        'fr' => 'Synchronisation sur plusieurs appareils',
                        'en' => 'Sync across multiple devices',
                    ],
                    'excerpt' => [
                        'fr' => 'Accédez à vos mots de passe depuis votre ordinateur, smartphone et tablette. La synchronisation est automatique et chiffrée.',
                        'en' => 'Access your passwords from your computer, smartphone, and tablet. Sync is automatic and encrypted.',
                    ],
                    'readTime' => 3,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['synchronisation', 'appareils', 'mobile'],
                    'updatedAt' => new \DateTime('2025-12-15'),
                    'helpfulYes' => 95,
                    'helpfulNo' => 2,
                    'sections' => [
                        [
                            'id' => 'comment-synchro',
                            'title' => [
                                'fr' => 'Comment fonctionne la synchronisation ?',
                                'en' => 'How does syncing work?',
                            ],
                            'content' => [
                                'fr' => '<p>Dès que vous ajoutez ou modifiez un identifiant sur un appareil, les changements sont chiffrés et propagés à tous vos autres appareils connectés en temps réel.</p>',
                                'en' => '<p>As soon as you add or edit a credential on one device, changes are encrypted and propagated to all your other connected devices in real time.</p>',
                            ],
                        ],
                        [
                            'id' => 'ajouter-appareil',
                            'title' => [
                                'fr' => 'Ajouter un nouvel appareil',
                                'en' => 'Add a new device',
                            ],
                            'content' => [
                                'fr' => '<p>Installez l\'application MYKEYNEST ou ouvrez le site dans un navigateur, connectez-vous avec votre email et votre mot de passe maître. La synchronisation démarre automatiquement.</p>',
                                'en' => '<p>Install the MYKEYNEST app or open the website in a browser, then sign in with your email and master password. Sync starts automatically.</p>',
                            ],
                        ],
                    ],
                ],

                'application-mobile' => [
                    'slug' => 'application-mobile',
                    'title' => [
                        'fr' => 'Utiliser MYKEYNEST sur mobile',
                        'en' => 'Use MYKEYNEST on mobile',
                    ],
                    'excerpt' => [
                        'fr' => 'Installez l’application web MYKEYNEST depuis votre navigateur sur iPhone, iPad ou Android.',
                        'en' => 'Install the MYKEYNEST web app from your browser on iPhone, iPad, or Android.',
                    ],
                    'readTime' => 3,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['mobile', 'iOS', 'Android'],
                    'updatedAt' => new \DateTime('2026-01-05'),
                    'helpfulYes' => 88,
                    'helpfulNo' => 3,
                    'sections' => [
                        [
                            'id' => 'telecharger',
                            'title' => [
                                'fr' => 'Installer depuis le navigateur',
                                'en' => 'Install from your browser',
                            ],
                            'content' => [
                                'fr' => '<p>MYKEYNEST est une application web installable : aucun téléchargement depuis l’App Store ou Google Play n’est nécessaire. Ouvrez le guide d’installation depuis le bouton ci-dessus, puis suivez les étapes adaptées à votre appareil.</p>',
                                'en' => '<p>MYKEYNEST is an installable web app: no App Store or Google Play download is required. Open the installation guide using the button above, then follow the steps for your device.</p>',
                            ],
                        ],
                        [
                            'id' => 'raccourci',
                            'title' => [
                                'fr' => 'Ajouter MYKEYNEST à l’écran d’accueil',
                                'en' => 'Add MYKEYNEST to your home screen',
                            ],
                            'content' => [
                                'fr' => '<p>Sur iPhone ou iPad, utilisez <strong>Partager › Sur l’écran d’accueil</strong>. Sur Android, choisissez <strong>Installer l’application</strong> ou <strong>Ajouter à l’écran d’accueil</strong> dans le menu du navigateur.</p>',
                                'en' => '<p>On iPhone or iPad, use <strong>Share › Add to Home Screen</strong>. On Android, choose <strong>Install app</strong> or <strong>Add to Home screen</strong> from the browser menu.</p>',
                            ],
                        ],
                    ],
                ],
            ],

            // ── SÉCURITÉ ───────────────────────────────────────────────────
            'securite' => [
                'zero-knowledge-explique' => [
                    'slug' => 'zero-knowledge-explique',
                    'title' => [
                        'fr' => 'Comment MYKEYNEST protège les identifiants enregistrés ?',
                        'en' => 'How does MYKEYNEST protect stored credentials?',
                    ],
                    'excerpt' => [
                        'fr' => 'Comprenez le chiffrement des secrets, les contrôles d’accès et les protections appliquées par le service.',
                        'en' => 'Understand secret encryption, access controls and the protections applied by the service.',
                    ],
                    'readTime' => 4,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['sécurité', 'architecture', 'chiffrement'],
                    'updatedAt' => new \DateTime('2026-01-15'),
                    'helpfulYes' => 312,
                    'helpfulNo' => 8,
                    'sections' => [
                        [
                            'id' => 'definition',
                            'title' => [
                                'fr' => 'Protection des secrets enregistrés',
                                'en' => 'Protection for stored secrets',
                            ],
                            'content' => [
                                'fr' => '<p>MYKEYNEST chiffre les mots de passe enregistrés avec <strong>AES-256-GCM</strong> avant leur persistance. Les données chiffrées intègrent un vecteur d’initialisation aléatoire et une balise d’authentification qui permet de détecter une altération.</p><div class="art-callout"><span class="art-callout-icon">🔒</span><span class="art-callout-text"><strong>Important :</strong> le chiffrement au repos complète l’authentification, les autorisations et la sécurité de votre appareil ; il ne les remplace pas.</span></div>',
                                'en' => '<p>MYKEYNEST encrypts stored passwords with <strong>AES-256-GCM</strong> before persistence. Encrypted values include a random initialization vector and an authentication tag used to detect tampering.</p><div class="art-callout"><span class="art-callout-icon">🔒</span><span class="art-callout-text"><strong>Important:</strong> encryption at rest complements authentication, authorization and device security; it does not replace them.</span></div>',
                            ],
                        ],
                        [
                            'id' => 'fonctionnement',
                            'title' => [
                                'fr' => 'Comment ça fonctionne ?',
                                'en' => 'How does it work?',
                            ],
                            'content' => [
                                'fr' => '<ol><li>Votre compte est authentifié et les autorisations sont vérifiées.</li><li>Une clé de chiffrement propre au compte est dérivée avec le secret applicatif.</li><li>Le serveur ne restitue le secret déchiffré qu’au parcours autorisé : affichage, partage permis ou remplissage par l’extension.</li></ol>',
                                'en' => '<ol><li>Your account is authenticated and permissions are checked.</li><li>An account-specific encryption key is derived together with the application secret.</li><li>The server returns a decrypted secret only through an authorized flow: viewing, permitted sharing or extension autofill.</li></ol>',
                            ],
                        ],
                        [
                            'id' => 'implications',
                            'title' => [
                                'fr' => 'Ce que ça implique',
                                'en' => 'What this implies',
                            ],
                            'content' => [
                                'fr' => '<p>Protégez l’accès au compte avec un mot de passe unique, la 2FA lorsque disponible et des sessions régulièrement vérifiées. En cas d’oubli, utilisez le lien <strong>Mot de passe oublié</strong> depuis la page de connexion.</p><div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">Ne transmettez jamais un lien de réinitialisation ou un code de validation à un tiers.</span></div>',
                                'en' => '<p>Protect account access with a unique password, 2FA when available and regular session reviews. If you forget the password, use <strong>Forgot password</strong> on the sign-in page.</p><div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">Never share a reset link or validation code with a third party.</span></div>',
                            ],
                        ],
                    ],
                ],

                'aes-256-explique' => [
                    'slug' => 'aes-256-explique',
                    'title' => [
                        'fr' => 'Comment fonctionne le chiffrement AES-256 ?',
                        'en' => 'How does AES-256 encryption work?',
                    ],
                    'excerpt' => [
                        'fr' => 'Découvrez le standard AES-256-GCM utilisé par MYKEYNEST pour protéger les secrets enregistrés.',
                        'en' => 'Learn about the AES-256-GCM standard MYKEYNEST uses to protect stored secrets.',
                    ],
                    'readTime' => 6,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['aes-256', 'chiffrement', 'technique'],
                    'updatedAt' => new \DateTime('2026-01-10'),
                    'helpfulYes' => 142,
                    'helpfulNo' => 5,
                    'sections' => [
                        [
                            'id' => 'aes-intro',
                            'title' => [
                                'fr' => 'Qu\'est-ce que l\'AES-256 ?',
                                'en' => 'What is AES-256?',
                            ],
                            'content' => [
                                'fr' => '<p>AES-256 est un standard de chiffrement symétrique largement étudié. MYKEYNEST utilise le mode authentifié <strong>AES-256-GCM</strong> pour chiffrer les mots de passe enregistrés et détecter une éventuelle altération.</p>',
                                'en' => '<p>AES-256 is a widely studied symmetric encryption standard. MYKEYNEST uses authenticated <strong>AES-256-GCM</strong> to encrypt stored passwords and detect tampering.</p>',
                            ],
                        ],
                        [
                            'id' => 'pourquoi-256',
                            'title' => [
                                'fr' => 'Pourquoi 256 bits ?',
                                'en' => 'Why 256 bits?',
                            ],
                            'content' => [
                                'fr' => '<p>Une clé de 256 bits offre 2<sup>256</sup> combinaisons. Un supercalculateur prendrait des milliards de fois l\'âge de l\'univers pour la trouver.</p>',
                                'en' => '<p>A 256-bit key has 2<sup>256</sup> possible combinations. Even a supercomputer would take far longer than the age of the universe to brute-force it.</p>',
                            ],
                        ],
                    ],
                ],

                'mot-de-passe-maitre' => [
                    'slug' => 'mot-de-passe-maitre',
                    'title' => [
                        'fr' => 'Tout savoir sur le mot de passe maître',
                        'en' => 'All about the master password',
                    ],
                    'excerpt' => [
                        'fr' => 'Le mot de passe maître est la clé de voûte de votre sécurité. Comment le choisir, le protéger, et que faire si vous l\'oubliez.',
                        'en' => 'The master password is the cornerstone of your security. How to choose it, protect it, and what to do if you forget it.',
                    ],
                    'readTime' => 5,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['mot de passe maître', 'sécurité', 'récupération'],
                    'updatedAt' => new \DateTime('2026-01-20'),
                    'helpfulYes' => 267,
                    'helpfulNo' => 12,
                    'sections' => [
                        [
                            'id' => 'choisir',
                            'title' => [
                                'fr' => 'Choisir un bon mot de passe maître',
                                'en' => 'Choose a strong master password',
                            ],
                            'content' => [
                                'fr' => '<p>Votre mot de passe maître doit être <strong>long</strong> (20+ caractères), <strong>unique</strong> et <strong>mémorisable</strong>. Une phrase comme <code>Cafe!Montagne#2077!Soleil</code> est idéale.</p>',
                                'en' => '<p>Your master password should be <strong>long</strong> (20+ characters), <strong>unique</strong>, and <strong>memorable</strong>. A passphrase like <code>Cafe!Mountain#2077!Sun</code> is a great option.</p>',
                            ],
                        ],
                        [
                            'id' => 'oubli',
                            'title' => [
                                'fr' => 'J\'ai oublié mon mot de passe maître',
                                'en' => 'I forgot my master password',
                            ],
                            'content' => [
                                'fr' => '<p>Depuis la page de connexion, choisissez <strong>Mot de passe oublié</strong>, saisissez l’adresse e-mail du compte puis utilisez le lien reçu. Le lien est temporaire et ne doit jamais être partagé.</p><div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">Si vous ne recevez pas l’e-mail, vérifiez les courriers indésirables puis contactez le support sans communiquer d’identifiant sensible.</span></div>',
                                'en' => '<p>On the sign-in page, choose <strong>Forgot password</strong>, enter the account email address and use the link you receive. The link is temporary and must never be shared.</p><div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">If the email does not arrive, check spam and then contact support without disclosing sensitive credentials.</span></div>',
                            ],
                        ],
                    ],
                ],

                'activer-2fa' => [
                    'slug' => 'activer-2fa',
                    'title' => [
                        'fr' => 'Activer l\'authentification à deux facteurs (2FA)',
                        'en' => 'Enable two-factor authentication (2FA)',
                    ],
                    'excerpt' => [
                        'fr' => 'Renforcez la sécurité de votre compte avec le 2FA via Google Authenticator, Authy ou toute application TOTP.',
                        'en' => 'Strengthen your account security with 2FA using Google Authenticator, Authy, or any TOTP app.',
                    ],
                    'readTime' => 4,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['2FA', 'authentification', 'sécurité', 'pro'],
                    'updatedAt' => new \DateTime('2025-12-20'),
                    'helpfulYes' => 119,
                    'helpfulNo' => 3,
                    'sections' => [
                        [
                            'id' => 'pourquoi-2fa',
                            'title' => [
                                'fr' => 'Pourquoi activer le 2FA ?',
                                'en' => 'Why enable 2FA?',
                            ],
                            'content' => [
                                'fr' => '<p>Le 2FA ajoute une deuxième couche : même si quelqu\'un découvre votre mot de passe maître, il ne peut pas accéder à votre compte sans le code temporaire généré par votre téléphone.</p>',
                                'en' => '<p>2FA adds a second layer: even if someone learns your master password, they can’t access your account without the temporary code generated by your phone.</p>',
                            ],
                        ],
                        [
                            'id' => 'activer',
                            'title' => [
                                'fr' => 'Activer le 2FA pas à pas',
                                'en' => 'Enable 2FA step by step',
                            ],
                            'content' => [
                                'fr' => '<p>Allez dans <strong>Paramètres › Sécurité › 2FA</strong>. Scannez le QR code avec Google Authenticator ou Authy, et saisissez le code à 6 chiffres pour confirmer.</p><div class="art-callout"><span class="art-callout-icon"><i class="fa-solid fa-lightbulb"></i></span><span class="art-callout-text">Sauvegardez les <strong>codes de secours</strong> affichés lors de l\'activation.</span></div>',
                                'en' => '<p>Go to <strong>Settings › Security › 2FA</strong>. Scan the QR code with Google Authenticator or Authy, then enter the 6-digit code to confirm.</p><div class="art-callout"><span class="art-callout-icon"><i class="fa-solid fa-lightbulb"></i></span><span class="art-callout-text">Save the <strong>backup codes</strong> shown during setup.</span></div>',
                            ],
                        ],
                    ],
                ],

                'audit-securite' => [
                    'slug' => 'audit-securite',
                    'title' => [
                        'fr' => 'Comment utiliser l\'audit de sécurité ?',
                        'en' => 'How to use the security audit?',
                    ],
                    'excerpt' => [
                        'fr' => 'L\'audit analyse vos mots de passe pour détecter ceux qui sont faibles, réutilisés ou compromis.',
                        'en' => 'The audit analyzes your passwords to detect weak, reused, or compromised ones.',
                    ],
                    'readTime' => 3,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['audit', 'sécurité', 'pro'],
                    'updatedAt' => new \DateTime('2025-12-28'),
                    'helpfulYes' => 88,
                    'helpfulNo' => 2,
                    'sections' => [
                        [
                            'id' => 'lancer-audit',
                            'title' => [
                                'fr' => 'Lancer un audit',
                                'en' => 'Run an audit',
                            ],
                            'content' => [
                                'fr' => sprintf('<p>Depuis le tableau de bord, cliquez sur <strong>Audit de sécurité</strong> (%s). L\'analyse génère un rapport en quelques secondes.</p>', $securityAccessFr),
                                'en' => sprintf('<p>From the dashboard, click <strong>Security audit</strong> (%s). The analysis generates a report in a few seconds.</p>', $securityAccessEn),
                            ],
                        ],
                        [
                            'id' => 'types-alertes',
                            'title' => [
                                'fr' => 'Types d\'alertes',
                                'en' => 'Alert types',
                            ],
                            'content' => [
                                'fr' => '<ul><li><strong>Mots de passe faibles</strong> — trop courts ou trop simples.</li><li><strong>Mots de passe réutilisés</strong> — identiques sur plusieurs sites.</li><li><strong>Mots de passe compromis</strong> — référencés dans des bases de données de fuite.</li></ul>',
                                'en' => '<ul><li><strong>Weak passwords</strong> — too short or too simple.</li><li><strong>Reused passwords</strong> — identical across multiple sites.</li><li><strong>Compromised passwords</strong> — found in leaked databases.</li></ul>',
                            ],
                        ],
                    ],
                ],
            ],

            // ── GÉNÉRATEUR ─────────────────────────────────────────────────
            'generateur' => [
                'utiliser-le-generateur' => [
                    'slug' => 'utiliser-le-generateur',
                    'title' => [
                        'fr' => 'Comment utiliser le générateur de mots de passe ?',
                        'en' => 'How to use the password generator?',
                    ],
                    'excerpt' => [
                        'fr' => 'Guide complet pour créer des mots de passe ultra-sécurisés : longueur, caractères, options avancées et copie en un clic.',
                        'en' => 'A complete guide to creating ultra-secure passwords: length, character sets, advanced options, and one-click copy.',
                    ],
                    'readTime' => 3,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['générateur', 'guide', 'démarrer'],
                    'updatedAt' => new \DateTime('2026-02-01'),
                    'helpfulYes' => 195,
                    'helpfulNo' => 4,
                    'sections' => [
                        [
                            'id' => 'acces',
                            'title' => [
                                'fr' => 'Accéder au générateur',
                                'en' => 'Access the generator',
                            ],
                            'content' => [
                                'fr' => '<p>Le générateur est accessible gratuitement sans compte sur <strong>/generator</strong>. Il est aussi disponible directement dans l\'app lors de la création d\'un identifiant.</p>',
                                'en' => '<p>The generator is available for free without an account at <strong>/generator</strong>. It’s also available directly in the app when creating a credential.</p>',
                            ],
                        ],
                        [
                            'id' => 'options',
                            'title' => [
                                'fr' => 'Configurer le mot de passe',
                                'en' => 'Configure the password',
                            ],
                            'content' => [
                                'fr' => '<ul><li><strong>Longueur</strong> — de 8 à 64 caractères. Recommandé : 20+.</li><li><strong>Majuscules</strong>, <strong>minuscules</strong>, <strong>chiffres</strong>, <strong>symboles</strong></li></ul><div class="art-callout"><span class="art-callout-icon">🔒</span><span class="art-callout-text">Le générateur utilise <code>crypto.getRandomValues</code> — aucune donnée n\'est transmise.</span></div>',
                                'en' => '<ul><li><strong>Length</strong> — from 8 to 64 characters. Recommended: 20+.</li><li><strong>Uppercase</strong>, <strong>lowercase</strong>, <strong>numbers</strong>, <strong>symbols</strong></li></ul><div class="art-callout"><span class="art-callout-icon">🔒</span><span class="art-callout-text">The generator uses <code>crypto.getRandomValues</code> — no data is transmitted.</span></div>',
                            ],
                        ],
                        [
                            'id' => 'copier',
                            'title' => [
                                'fr' => 'Copier et utiliser',
                                'en' => 'Copy and use',
                            ],
                            'content' => [
                                'fr' => '<p>Cliquez sur l\'icône de copie pour copier le mot de passe dans le presse-papier. Cliquez sur les flèches pour en générer un nouveau instantanément.</p>',
                                'en' => '<p>Click the copy icon to copy the password to your clipboard. Click the arrows to generate a new one instantly.</p>',
                            ],
                        ],
                    ],
                ],

                'longueur-ideale' => [
                    'slug' => 'longueur-ideale',
                    'title' => [
                        'fr' => 'Quelle longueur pour un mot de passe sécurisé ?',
                        'en' => 'What length for a secure password?',
                    ],
                    'excerpt' => [
                        'fr' => '12, 16, 24 caractères ? La longueur est le facteur le plus important pour la résistance aux attaques.',
                        'en' => '12, 16, 24 characters? Length is the most important factor for attack resistance.',
                    ],
                    'readTime' => 4,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['longueur', 'sécurité', 'conseils'],
                    'updatedAt' => new \DateTime('2026-01-05'),
                    'helpfulYes' => 107,
                    'helpfulNo' => 3,
                    'sections' => [
                        [
                            'id' => 'pourquoi-longueur',
                            'title' => [
                                'fr' => 'Pourquoi la longueur prime sur la complexité ?',
                                'en' => 'Why length matters more than complexity',
                            ],
                            'content' => [
                                'fr' => '<p>Chaque caractère supplémentaire multiplie exponentiellement les combinaisons possibles. Un mot de passe de 8 caractères peut être cassé en heures ; un de 20 caractères prendrait des millénaires.</p>',
                                'en' => '<p>Each additional character increases the number of combinations exponentially. An 8-character password can be cracked in hours; a 20-character one would take millennia.</p>',
                            ],
                        ],
                        [
                            'id' => 'recommandations',
                            'title' => [
                                'fr' => 'Nos recommandations',
                                'en' => 'Our recommendations',
                            ],
                            'content' => [
                                'fr' => '<ul><li><strong>Compte standard</strong> — 16 caractères minimum.</li><li><strong>Compte sensible</strong> (email, banque) — 20 caractères ou plus.</li><li><strong>Mot de passe maître</strong> — 24 caractères ou une longue phrase.</li></ul>',
                                'en' => '<ul><li><strong>Standard account</strong> — at least 16 characters.</li><li><strong>Sensitive account</strong> (email, banking) — 20+ characters.</li><li><strong>Master password</strong> — 24 characters or a long passphrase.</li></ul>',
                            ],
                        ],
                    ],
                ],

                'securite-generateur' => [
                    'slug' => 'securite-generateur',
                    'title' => [
                        'fr' => 'Le générateur est-il vraiment sécurisé ?',
                        'en' => 'Is the generator really secure?',
                    ],
                    'excerpt' => [
                        'fr' => 'Le générateur fonctionne entièrement dans votre navigateur via l\'API Web Crypto. Aucune donnée ne quitte votre appareil.',
                        'en' => 'The generator runs entirely in your browser using the Web Crypto API. No data leaves your device.',
                    ],
                    'readTime' => 3,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['sécurité', 'générateur', 'technique'],
                    'updatedAt' => new \DateTime('2025-12-10'),
                    'helpfulYes' => 76,
                    'helpfulNo' => 1,
                    'sections' => [
                        [
                            'id' => 'web-crypto',
                            'title' => [
                                'fr' => 'L\'API Web Crypto',
                                'en' => 'The Web Crypto API',
                            ],
                            'content' => [
                                'fr' => '<p>Nous utilisons <code>window.crypto.getRandomValues()</code>, l\'API native des navigateurs pour générer de l\'aléatoire cryptographiquement sûr — la même base que les générateurs bancaires.</p>',
                                'en' => '<p>We use <code>window.crypto.getRandomValues()</code>, the browser’s native API for cryptographically secure randomness — the same foundation used by banking-grade generators.</p>',
                            ],
                        ],
                        [
                            'id' => 'pas-de-serveur',
                            'title' => [
                                'fr' => 'Rien ne quitte votre navigateur',
                                'en' => 'Nothing leaves your browser',
                            ],
                            'content' => [
                                'fr' => '<p>Le mot de passe est généré et affiché dans votre navigateur uniquement. Aucune requête réseau n\'est effectuée. Vous pouvez même couper votre connexion internet et ça fonctionne toujours.</p>',
                                'en' => '<p>The password is generated and displayed only in your browser. No network request is made. You can even go offline and it will still work.</p>',
                            ],
                        ],
                    ],
                ],
            ],

            // ── PARTAGE ────────────────────────────────────────────────────
            'partage' => [
                'partager-identifiant' => [
                    'slug' => 'partager-identifiant',
                    'title' => [
                        'fr' => 'Comment partager un identifiant avec quelqu\'un ?',
                        'en' => 'How to share a credential with someone?',
                    ],
                    'excerpt' => [
                        'fr' => 'Partagez un mot de passe en toute sécurité avec un proche ou un collègue. Le destinataire reçoit une invitation chiffrée.',
                        'en' => 'Share a password securely with a family member or coworker. The recipient receives an encrypted invitation.',
                    ],
                    'readTime' => 3,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['partage', 'identifiant', 'collaboration'],
                    'updatedAt' => new \DateTime('2026-01-12'),
                    'helpfulYes' => 155,
                    'helpfulNo' => 5,
                    'sections' => [
                        [
                            'id' => 'comment-partager',
                            'title' => [
                                'fr' => 'Partager en 3 étapes',
                                'en' => 'Share in 3 steps',
                            ],
                            'content' => [
                                'fr' => '<p>Sélectionnez un identifiant, cliquez sur <strong>Partager</strong> et entrez l\'email du destinataire. Une invitation lui est envoyée. À l\'acceptation, il accède à l\'identifiant dans son propre coffre.</p>',
                                'en' => '<p>Select a credential, click <strong>Share</strong>, and enter the recipient’s email. An invitation is sent. Once accepted, they can access the credential in their own vault.</p>',
                            ],
                        ],
                        [
                            'id' => 'permissions',
                            'title' => [
                                'fr' => 'Gérer les permissions',
                                'en' => 'Manage permissions',
                            ],
                            'content' => [
                                'fr' => '<p>Vous pouvez accorder un accès <strong>lecture seule</strong> ou <strong>complet</strong>. Vous pouvez révoquer l\'accès à tout moment depuis <em>Identifiant › Partages actifs</em>.</p>',
                                'en' => '<p>You can grant <strong>read-only</strong> or <strong>full</strong> access. You can revoke access anytime from <em>Credential › Active shares</em>.</p>',
                            ],
                        ],
                    ],
                ],

                'limite-partages-gratuits' => [
                    'slug' => 'limite-partages-gratuits',
                    'title' => [
                        'fr' => 'Combien de partages puis-je faire en offre gratuite ?',
                        'en' => 'How many shares are included in the Free plan?',
                    ],
                    'excerpt' => [
                        'fr' => $shareExcerptFr,
                        'en' => $shareExcerptEn,
                    ],
                    'readTime' => 2,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['partage', 'offre gratuite', 'limites'],
                    'updatedAt' => new \DateTime('2025-12-01'),
                    'helpfulYes' => 63,
                    'helpfulNo' => 2,
                    'sections' => [
                        [
                            'id' => 'limites',
                            'title' => [
                                'fr' => 'Limites de l\'offre gratuite',
                                'en' => 'Free plan limits',
                            ],
                            'content' => [
                                'fr' => $shareContentFr,
                                'en' => $shareContentEn,
                            ],
                        ],
                    ],
                ],

                'revoquer-partage' => [
                    'slug' => 'revoquer-partage',
                    'title' => [
                        'fr' => 'Comment révoquer un partage ?',
                        'en' => 'How to revoke a share?',
                    ],
                    'excerpt' => [
                        'fr' => 'Retirez l\'accès à un identifiant partagé à tout moment, instantanément. Le destinataire ne peut plus y accéder.',
                        'en' => 'Remove access to a shared credential anytime — instantly. The recipient can no longer access it.',
                    ],
                    'readTime' => 2,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['partage', 'révocation', 'sécurité'],
                    'updatedAt' => new \DateTime('2025-11-20'),
                    'helpfulYes' => 49,
                    'helpfulNo' => 1,
                    'sections' => [
                        [
                            'id' => 'revoquer',
                            'title' => [
                                'fr' => 'Révoquer un accès',
                                'en' => 'Revoke access',
                            ],
                            'content' => [
                                'fr' => '<p>Ouvrez l\'identifiant concerné, allez dans l\'onglet <strong>Partages</strong> et cliquez sur <strong>Révoquer</strong> en face du contact. L\'accès est supprimé immédiatement.</p>',
                                'en' => '<p>Open the credential, go to the <strong>Shares</strong> tab, and click <strong>Revoke</strong> next to the contact. Access is removed immediately.</p>',
                            ],
                        ],
                    ],
                ],
            ],

            // ── EXTENSION ──────────────────────────────────────────────────
            'extension' => [
                'installer-extension-chrome' => [
                    'slug' => 'installer-extension-chrome',
                    'title' => [
                        'fr' => 'Installer l\'extension MYKEYNEST sur Chrome',
                        'en' => 'Install the MYKEYNEST extension on Chrome',
                    ],
                    'excerpt' => [
                        'fr' => 'L\'extension Chrome permet le remplissage automatique de vos identifiants sur tous les sites. Installation en 2 minutes.',
                        'en' => 'The Chrome extension enables autofill for your credentials on all websites. Install it in 2 minutes.',
                    ],
                    'readTime' => 3,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['extension', 'Chrome', 'installation'],
                    'updatedAt' => new \DateTime('2026-01-25'),
                    'helpfulYes' => 231,
                    'helpfulNo' => 7,
                    'sections' => [
                        [
                            'id' => 'installation',
                            'title' => [
                                'fr' => 'Installer l\'extension',
                                'en' => 'Install the extension',
                            ],
                            'content' => [
                                'fr' => '<p>Rendez-vous sur le <strong>Chrome Web Store</strong> et recherchez « MYKEYNEST ». Cliquez sur <strong>Ajouter à Chrome</strong> et confirmez. L\'extension s\'installe instantanément.</p>',
                                'en' => '<p>Go to the <strong>Chrome Web Store</strong> and search for “MYKEYNEST”. Click <strong>Add to Chrome</strong> and confirm. The extension installs instantly.</p>',
                            ],
                        ],
                        [
                            'id' => 'connexion-extension',
                            'title' => [
                                'fr' => 'Se connecter à l\'extension',
                                'en' => 'Sign in to the extension',
                            ],
                            'content' => [
                                'fr' => '<p>Cliquez sur l\'icône MYKEYNEST dans la barre d\'outils Chrome et connectez-vous avec votre email et votre mot de passe maître.</p>',
                                'en' => '<p>Click the MYKEYNEST icon in the Chrome toolbar and sign in with your email and master password.</p>',
                            ],
                        ],
                        [
                            'id' => 'autofill',
                            'title' => [
                                'fr' => 'Utiliser le remplissage automatique',
                                'en' => 'Use autofill',
                            ],
                            'content' => [
                                'fr' => '<p>Sur un formulaire de connexion, cliquez sur le champ. Un popup MYKEYNEST apparaît avec les identifiants correspondants. Cliquez pour remplir automatiquement.</p>',
                                'en' => '<p>On a login form, click the field. A MYKEYNEST popup appears with matching credentials. Click to fill automatically.</p>',
                            ],
                        ],
                    ],
                ],

                'installer-extension-firefox' => [
                    'slug' => 'installer-extension-firefox',
                    'title' => [
                        'fr' => 'Puis-je utiliser l’extension MYKEYNEST sur Firefox ?',
                        'en' => 'Can I use the MYKEYNEST extension on Firefox?',
                    ],
                    'excerpt' => [
                        'fr' => 'Consultez l’état de compatibilité des navigateurs et utilisez la version officielle disponible.',
                        'en' => 'Check browser compatibility and use the currently available official version.',
                    ],
                    'readTime' => 3,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['extension', 'Firefox', 'installation'],
                    'updatedAt' => new \DateTime('2026-01-22'),
                    'helpfulYes' => 98,
                    'helpfulNo' => 4,
                    'sections' => [
                        [
                            'id' => 'installation-firefox',
                            'title' => [
                                'fr' => 'Compatibilité actuelle',
                                'en' => 'Current compatibility',
                            ],
                            'content' => [
                                'fr' => '<p>L’extension officielle MYKEYNEST est actuellement distribuée sur le <strong>Chrome Web Store</strong>. La version Firefox n’est pas encore publiée : n’installez aucun paquet provenant d’un site non officiel.</p>',
                                'en' => '<p>The official MYKEYNEST extension is currently distributed through the <strong>Chrome Web Store</strong>. The Firefox version is not yet published: do not install packages from unofficial websites.</p>',
                            ],
                        ],
                    ],
                ],

                'autofill-ne-fonctionne-pas' => [
                    'slug' => 'autofill-ne-fonctionne-pas',
                    'title' => [
                        'fr' => 'L\'auto-remplissage ne fonctionne pas, que faire ?',
                        'en' => 'Autofill isn’t working — what can I do?',
                    ],
                    'excerpt' => [
                        'fr' => 'Problèmes de détection de formulaires, sites incompatibles ou extension inactive ? Voici les solutions.',
                        'en' => 'Form detection issues, incompatible sites, or an inactive extension? Here are the fixes.',
                    ],
                    'readTime' => 4,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['auto-remplissage', 'dépannage', 'extension'],
                    'updatedAt' => new \DateTime('2026-01-08'),
                    'helpfulYes' => 187,
                    'helpfulNo' => 14,
                    'sections' => [
                        [
                            'id' => 'verifier-extension',
                            'title' => [
                                'fr' => 'Vérifier que l\'extension est active',
                                'en' => 'Check that the extension is enabled',
                            ],
                            'content' => [
                                'fr' => '<p>Assurez-vous que l\'extension est activée dans <code>chrome://extensions</code> et que vous êtes bien connecté à votre compte MYKEYNEST.</p>',
                                'en' => '<p>Make sure the extension is enabled in <code>chrome://extensions</code> and that you are signed in to your MYKEYNEST account.</p>',
                            ],
                        ],
                        [
                            'id' => 'site-incompatible',
                            'title' => [
                                'fr' => 'Site non reconnu',
                                'en' => 'Website not recognized',
                            ],
                            'content' => [
                                'fr' => '<p>Certains sites utilisent des composants personnalisés difficiles à détecter. Utilisez le <strong>bouton manuel</strong> de l\'extension pour copier-coller vos identifiants.</p>',
                                'en' => '<p>Some websites use custom components that are hard to detect. Use the extension’s <strong>manual button</strong> to copy/paste your credentials.</p>',
                            ],
                        ],
                        [
                            'id' => 'rechargement',
                            'title' => [
                                'fr' => 'Solution de base : recharger',
                                'en' => 'Basic fix: refresh',
                            ],
                            'content' => [
                                'fr' => '<p>Rechargez la page (<code>F5</code>) puis cliquez à nouveau sur l\'extension. Si le problème persiste, redémarrez le navigateur ou réinstallez l\'extension.</p>',
                                'en' => '<p>Refresh the page (<code>F5</code>) and click the extension again. If the issue persists, restart your browser or reinstall the extension.</p>',
                            ],
                        ],
                    ],
                ],
            ],

            // ── ABONNEMENT ─────────────────────────────────────────────────
            'abonnement' => [
                'difference-free-pro' => [
                    'slug' => 'difference-free-pro',
                    'title' => [
                        'fr' => 'Quelle est la différence entre l\'offre Free et Pro ?',
                        'en' => 'What’s the difference between Free and Pro?',
                    ],
                    'excerpt' => [
                        'fr' => 'Comparatif complet des trois plans : mots de passe, partages, 2FA, audit de sécurité, support et prix.',
                        'en' => 'A full comparison of all three plans: passwords, sharing, 2FA, security audit, support, and pricing.',
                    ],
                    'readTime' => 3,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['offre', 'comparatif', 'pro', 'gratuit'],
                    'updatedAt' => new \DateTime('2026-02-01'),
                    'helpfulYes' => 245,
                    'helpfulNo' => 6,
                    'sections' => [
                        [
                            'id' => 'comparatif',
                            'title' => [
                                'fr' => 'Tableau comparatif',
                                'en' => 'Comparison table',
                            ],
                            'content' => [
                                'fr' => $planComparisonContentFr,
                                'en' => $planComparisonContentEn,
                            ],
                        ],
                        [
                            'id' => 'prix',
                            'title' => [
                                'fr' => 'Prix des plans Pro et Team',
                                'en' => 'Pro and Team plan pricing',
                            ],
                            'content' => [
                                'fr' => '<p>Pro est disponible à <strong>6,99 € / utilisateur / mois</strong>. Team est disponible à <strong>5,49 € / utilisateur / mois</strong> à partir de 6 comptes. Les deux offres sont mensuelles et sans engagement.</p>',
                                'en' => '<p>Pro is available for <strong>€6.99 / user / month</strong>. Team is available for <strong>€5.49 / user / month</strong> from 6 accounts. Both plans are monthly and have no commitment.</p>',
                            ],
                        ],
                    ],
                ],

                'passer-au-pro' => [
                    'slug' => 'passer-au-pro',
                    'title' => [
                        'fr' => 'Comment passer au plan Pro ?',
                        'en' => 'How to upgrade to Pro?',
                    ],
                    'excerpt' => [
                        'fr' => 'Upgrade votre compte en quelques clics depuis vos paramètres. Paiement sécurisé via Stripe, activation instantanée.',
                        'en' => 'Upgrade your account in a few clicks from your settings. Secure Stripe payment, instant activation.',
                    ],
                    'readTime' => 2,
                    'popular' => true,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['pro', 'upgrade', 'paiement'],
                    'updatedAt' => new \DateTime('2026-01-15'),
                    'helpfulYes' => 163,
                    'helpfulNo' => 3,
                    'sections' => [
                        [
                            'id' => 'upgrade',
                            'title' => [
                                'fr' => 'Passer au Pro en 3 clics',
                                'en' => 'Upgrade in 3 clicks',
                            ],
                            'content' => [
                                'fr' => '<p>Allez dans <strong>Paramètres › Abonnement</strong> et cliquez sur <strong>Passer au Pro</strong>. Choisissez votre moyen de paiement (carte ou SEPA via Stripe). Votre compte est mis à niveau immédiatement.</p><div class="art-callout"><span class="art-callout-icon"><i class="fa-solid fa-credit-card"></i></span><span class="art-callout-text">Le paiement est sécurisé via <strong>Stripe</strong>. Vos données bancaires ne sont jamais stockées sur nos serveurs.</span></div>',
                                'en' => '<p>Go to <strong>Settings › Subscription</strong> and click <strong>Upgrade to Pro</strong>. Choose your payment method (card or SEPA via Stripe). Your account is upgraded instantly.</p><div class="art-callout"><span class="art-callout-icon"><i class="fa-solid fa-credit-card"></i></span><span class="art-callout-text">Payment is secured by <strong>Stripe</strong>. Your banking details are never stored on our servers.</span></div>',
                            ],
                        ],
                    ],
                ],

                'annuler-abonnement' => [
                    'slug' => 'annuler-abonnement',
                    'title' => [
                        'fr' => 'Comment annuler mon abonnement Pro ?',
                        'en' => 'How to cancel my Pro subscription?',
                    ],
                    'excerpt' => [
                        'fr' => 'Gérez l’annulation depuis le portail de facturation Stripe et conservez vos droits jusqu’à l’échéance.',
                        'en' => 'Manage cancellation through the Stripe billing portal and keep your access until the renewal date.',
                    ],
                    'readTime' => 2,
                    'popular' => false,
                    'author' => [
                        'fr' => 'Équipe MYKEYNEST',
                        'en' => 'MYKEYNEST Team',
                    ],
                    'tags' => ['annulation', 'abonnement', 'pro'],
                    'updatedAt' => new \DateTime('2025-12-10'),
                    'helpfulYes' => 72,
                    'helpfulNo' => 2,
                    'sections' => [
                        [
                            'id' => 'annuler',
                            'title' => [
                                'fr' => 'Annuler depuis le portail Stripe',
                                'en' => 'Cancel from the Stripe portal',
                            ],
                            'content' => [
                                'fr' => '<p>Ouvrez la page <strong>Abonnement</strong> avec le bouton ci-dessus, puis choisissez <strong>Gérer la facturation</strong>. Dans le portail sécurisé Stripe, programmez l’annulation à l’échéance. Vos droits restent actifs jusqu’à la fin de la période déjà payée.</p>',
                                'en' => '<p>Open the <strong>Subscription</strong> page using the button above, then choose <strong>Manage billing</strong>. In the secure Stripe portal, schedule cancellation for the renewal date. Your access remains active until the end of the paid period.</p>',
                            ],
                        ],
                        [
                            'id' => 'donnees',
                            'title' => [
                                'fr' => 'Que deviennent mes données ?',
                                'en' => 'What happens to my data?',
                            ],
                            'content' => [
                                'fr' => '<p>Vos identifiants sont conservés. À la fin de l’abonnement, les limites et fonctionnalités du plan gratuit configuré s’appliquent à nouveau. MYKEYNEST vous indique les éventuelles actions nécessaires depuis votre espace.</p>',
                                'en' => '<p>Your credentials are kept. When the subscription ends, the configured Free plan limits and features apply again. MYKEYNEST shows any required actions in your account.</p>',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getPopularArticles(): array
    {
        return [
            [
                'categorySlug' => 'demarrer',
                'articleSlug' => 'creer-son-compte',
                'title' => [
                    'fr' => 'Comment créer son compte MYKEYNEST ?',
                    'en' => 'How to create your MYKEYNEST account?',
                ],
                'categoryTitle' => [
                    'fr' => 'Démarrer avec MYKEYNEST',
                    'en' => 'Getting started with MYKEYNEST',
                ],
            ],
            [
                'categorySlug' => 'securite',
                'articleSlug' => 'zero-knowledge-explique',
                'title' => [
                    'fr' => 'Comment MYKEYNEST protège les identifiants enregistrés ?',
                    'en' => 'How does MYKEYNEST protect stored credentials?',
                ],
                'categoryTitle' => [
                    'fr' => 'Sécurité & Chiffrement',
                    'en' => 'Security & Encryption',
                ],
            ],
            [
                'categorySlug' => 'securite',
                'articleSlug' => 'mot-de-passe-maitre',
                'title' => [
                    'fr' => 'J\'ai oublié mon mot de passe maître, que faire ?',
                    'en' => 'I forgot my master password — what should I do?',
                ],
                'categoryTitle' => [
                    'fr' => 'Sécurité & Chiffrement',
                    'en' => 'Security & Encryption',
                ],
            ],
            [
                'categorySlug' => 'extension',
                'articleSlug' => 'installer-extension-chrome',
                'title' => [
                    'fr' => 'Comment installer l\'extension navigateur ?',
                    'en' => 'How to install the browser extension?',
                ],
                'categoryTitle' => [
                    'fr' => 'Extension Navigateur',
                    'en' => 'Browser extension',
                ],
            ],
            [
                'categorySlug' => 'partage',
                'articleSlug' => 'partager-identifiant',
                'title' => [
                    'fr' => 'Comment partager un identifiant avec mon équipe ?',
                    'en' => 'How to share a credential with my team?',
                ],
                'categoryTitle' => [
                    'fr' => 'Partage & Collaboration',
                    'en' => 'Sharing & Collaboration',
                ],
            ],
            [
                'categorySlug' => 'abonnement',
                'articleSlug' => 'difference-free-pro',
                'title' => [
                    'fr' => 'Quelle est la différence entre Free et Pro ?',
                    'en' => 'What’s the difference between Free and Pro?',
                ],
                'categoryTitle' => [
                    'fr' => 'Abonnement & Facturation',
                    'en' => 'Subscription & Billing',
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers (existing)
    // ─────────────────────────────────────────────────────────────────────────

    private function getCategoryBySlug(string $slug): ?array
    {
        foreach ($this->getAllCategories() as $cat) {
            if ($cat['slug'] === $slug) return $cat;
        }
        return null;
    }

    private function getArticlesByCategory(string $slug): array
    {
        return array_values($this->getAllArticles()[$slug] ?? []);
    }

    private function getArticleBySlug(string $categorySlug, string $articleSlug): ?array
    {
        return $this->getAllArticles()[$categorySlug][$articleSlug] ?? null;
    }

    private function getRelatedArticles(string $categorySlug, string $currentSlug): array
    {
        $all = $this->getArticlesByCategory($categorySlug);
        return array_slice(
            array_values(array_filter($all, fn($a) => $a['slug'] !== $currentSlug)),
            0,
            4
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Routes
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/help/center', name: 'app_help_center')]
    public function helpCenter(Request $request): Response
    {
        $locale = $request->getLocale();

        $categories = array_map(fn($c) => $this->localizeCategory($c, $locale), $this->getAllCategories());
        $totalArticles = array_sum(array_column($categories, 'articleCount'));

        $popularArticles = array_map(fn($p) => $this->localizePopular($p, $locale), $this->getPopularArticles());

        return $this->render('help_center/index.html.twig', [
            'categories'      => $categories,
            'popularArticles' => $popularArticles,
            'totalArticles'   => $totalArticles,
        ]);
    }

    #[Route('/help/center/{slug}', name: 'app_help_category')]
    public function helpCategory(Request $request, string $slug): Response
    {
        $locale = $request->getLocale();

        $category = $this->getCategoryBySlug($slug);
        if (!$category) throw $this->createNotFoundException("Catégorie « $slug » introuvable.");

        $category = $this->localizeCategory($category, $locale);

        $otherCategories = array_values(
            array_filter($this->getAllCategories(), fn($c) => $c['slug'] !== $slug)
        );
        $otherCategories = array_map(fn($c) => $this->localizeCategory($c, $locale), $otherCategories);

        $articles = array_map(fn($a) => $this->localizeArticle($a, $locale), $this->getArticlesByCategory($slug));

        return $this->render('help_center/category.html.twig', [
            'category'        => $category,
            'articles'        => $articles,
            'otherCategories' => $otherCategories,
        ]);
    }

    #[Route('/help/center/{categorySlug}/{articleSlug}', name: 'app_help_article')]
    public function helpArticle(Request $request, string $categorySlug, string $articleSlug): Response
    {
        $locale = $request->getLocale();

        $category = $this->getCategoryBySlug($categorySlug);
        if (!$category) throw $this->createNotFoundException("Catégorie « $categorySlug » introuvable.");
        $category = $this->localizeCategory($category, $locale);

        $article = $this->getArticleBySlug($categorySlug, $articleSlug);
        if (!$article) throw $this->createNotFoundException("Article « $articleSlug » introuvable.");
        $article = $this->localizeArticle($article, $locale);

        $related = array_map(
            fn($a) => $this->localizeArticle($a, $locale),
            $this->getRelatedArticles($categorySlug, $articleSlug)
        );

        return $this->render('help_center/article.html.twig', [
            'category'        => $category,
            'article'         => $article,
            'relatedArticles' => $related,
        ]);
    }

    #[Route('/generator', name: 'app_public_generator')]
    public function publicGenerator(): Response
    {
        return $this->render('help_center/public_generator.html.twig');
    }
}
