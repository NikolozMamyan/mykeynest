<?php

namespace App\Controller\Front\Resources;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

final class HelpCenterController extends AbstractController
{
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
                    'fr' => 'Architecture zero-knowledge, AES-256, mot de passe maître et bonnes pratiques.',
                    'en' => 'Zero-knowledge architecture, AES-256, master password, and best practices.',
                ],
                'tags' => 'sécurité mot de passe master chiffrement aes zero knowledge 2fa',
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
                                'fr' => '<p>Rendez-vous sur <strong>key-nest.com</strong> et cliquez sur <strong>Essai gratuit</strong>. Renseignez votre adresse email et choisissez un mot de passe maître robuste. Ce mot de passe est la <em>seule clé</em> qui déchiffre votre coffre — il ne sera jamais transmis à nos serveurs.</p><div class="art-callout"><span class="art-callout-icon"><i class="fa-solid fa-lightbulb"></i></span><span class="art-callout-text"><strong>Astuce :</strong> Utilisez notre générateur intégré pour créer un mot de passe maître de 20+ caractères, puis notez-le dans un endroit physique sûr.</span></div>',
                                'en' => '<p>Go to <strong>key-nest.com</strong> and click <strong>Free trial</strong>. Enter your email address and choose a strong master password. This password is the <em>only key</em> that decrypts your vault — it is never sent to our servers.</p><div class="art-callout"><span class="art-callout-icon"><i class="fa-solid fa-lightbulb"></i></span><span class="art-callout-text"><strong>Tip:</strong> Use our built-in generator to create a 20+ character master password, then write it down in a safe physical place.</span></div>',
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
                                'fr' => '<p>Une fois connecté, cliquez sur <strong>+ Nouvel identifiant</strong>. Renseignez le site, l\'email et le mot de passe. MYKEYNEST chiffre tout localement avant la sauvegarde.</p>',
                                'en' => '<p>Once logged in, click <strong>+ New credential</strong>. Enter the website, email/username, and password. MYKEYNEST encrypts everything locally before saving.</p>',
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
                        'fr' => 'L\'application mobile MYKEYNEST est disponible sur iOS et Android. Découvrez comment l\'installer et activer la biométrie.',
                        'en' => 'The MYKEYNEST mobile app is available on iOS and Android. Learn how to install it and enable biometrics.',
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
                                'fr' => 'Télécharger l\'application',
                                'en' => 'Download the app',
                            ],
                            'content' => [
                                'fr' => '<p>L\'application est disponible sur l\'<strong>App Store</strong> (iOS 15+) et le <strong>Google Play Store</strong> (Android 9+). Recherchez « MYKEYNEST ».</p>',
                                'en' => '<p>The app is available on the <strong>App Store</strong> (iOS 15+) and the <strong>Google Play Store</strong> (Android 9+). Search for “MYKEYNEST”.</p>',
                            ],
                        ],
                        [
                            'id' => 'biometrie',
                            'title' => [
                                'fr' => 'Activer la biométrie',
                                'en' => 'Enable biometrics',
                            ],
                            'content' => [
                                'fr' => '<p>Activez <strong>Face ID</strong> ou <strong>Touch ID</strong> dans <em>Paramètres › Sécurité › Biométrie</em> pour accéder à votre coffre sans saisir votre mot de passe maître à chaque fois.</p>',
                                'en' => '<p>Enable <strong>Face ID</strong> or <strong>Touch ID</strong> in <em>Settings › Security › Biometrics</em> to access your vault without typing your master password every time.</p>',
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
                        'fr' => 'Qu\'est-ce que l\'architecture zero-knowledge ?',
                        'en' => 'What is zero-knowledge architecture?',
                    ],
                    'excerpt' => [
                        'fr' => 'Découvrez comment MYKEYNEST garantit que personne, même nos équipes, ne peut accéder à vos mots de passe en clair.',
                        'en' => 'Learn how MYKEYNEST ensures no one — not even our team — can access your passwords in plain text.',
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
                                'fr' => 'C\'est quoi "zero-knowledge" ?',
                                'en' => 'What does “zero-knowledge” mean?',
                            ],
                            'content' => [
                                'fr' => '<p>L\'architecture <strong>zero-knowledge</strong> signifie que MYKEYNEST n\'a <em>jamais accès</em> à vos données en clair. Vos mots de passe sont chiffrés localement avant d\'être envoyés sur nos serveurs.</p><div class="art-callout"><span class="art-callout-icon">🔒</span><span class="art-callout-text"><strong>En résumé :</strong> Même si nos serveurs étaient piratés, les attaquants ne verraient que du chiffré totalement inutilisable.</span></div>',
                                'en' => '<p><strong>Zero-knowledge</strong> means MYKEYNEST <em>never has access</em> to your data in plain text. Your passwords are encrypted locally before being sent to our servers.</p><div class="art-callout"><span class="art-callout-icon">🔒</span><span class="art-callout-text"><strong>In short:</strong> Even if our servers were hacked, attackers would only see unusable encrypted data.</span></div>',
                            ],
                        ],
                        [
                            'id' => 'fonctionnement',
                            'title' => [
                                'fr' => 'Comment ça fonctionne ?',
                                'en' => 'How does it work?',
                            ],
                            'content' => [
                                'fr' => '<ol><li>Vous saisissez votre <strong>mot de passe maître</strong>.</li><li>Il est transformé en clé via <code>PBKDF2</code> — jamais transmis au serveur.</li><li>La clé déchiffre localement vos données récupérées depuis nos serveurs.</li></ol>',
                                'en' => '<ol><li>You enter your <strong>master password</strong>.</li><li>It is derived into a key using <code>PBKDF2</code> — never sent to the server.</li><li>The key decrypts your data locally after it’s fetched from our servers.</li></ol>',
                            ],
                        ],
                        [
                            'id' => 'implications',
                            'title' => [
                                'fr' => 'Ce que ça implique',
                                'en' => 'What this implies',
                            ],
                            'content' => [
                                'fr' => '<p>Nous <strong>ne pouvons pas récupérer</strong> votre mot de passe maître si vous le perdez. Configurez une récupération d\'urgence depuis vos Paramètres.</p><div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">Conservez votre mot de passe maître dans un lieu physique sûr.</span></div>',
                                'en' => '<p>We <strong>cannot recover</strong> your master password if you lose it. Set up emergency recovery in your Settings.</p><div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">Keep your master password in a safe physical place.</span></div>',
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
                        'fr' => 'Plongez dans les détails du standard de chiffrement militaire utilisé par MYKEYNEST pour protéger vos données.',
                        'en' => 'Dive into the details of the military-grade encryption standard used by MYKEYNEST to protect your data.',
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
                                'fr' => '<p>AES-256 est le standard de chiffrement symétrique adopté par les gouvernements, militaires et banques. MYKEYNEST l\'utilise pour chiffrer toutes vos données.</p>',
                                'en' => '<p>AES-256 is a symmetric encryption standard used by governments, militaries, and banks. MYKEYNEST uses it to encrypt all your data.</p>',
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
                                'fr' => '<p>En raison du zero-knowledge, nous ne pouvons pas le récupérer. Si vous avez configuré une <strong>récupération d\'urgence</strong>, allez sur <em>Connexion › Mot de passe oublié</em>.</p><div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">Sans récupération d\'urgence configurée au préalable, vos données seront inaccessibles.</span></div>',
                                'en' => '<p>Because of zero-knowledge, we can’t recover it. If you enabled <strong>emergency recovery</strong>, go to <em>Login › Forgot password</em>.</p><div class="art-callout art-callout-warn"><span class="art-callout-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="art-callout-text">Without emergency recovery set up beforehand, your data will be inaccessible.</span></div>',
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
                                'fr' => '<p>Depuis le tableau de bord, cliquez sur <strong>Audit de sécurité</strong> (plan Pro requis). L\'analyse génère un rapport en quelques secondes.</p>',
                                'en' => '<p>From the dashboard, click <strong>Security audit</strong> (Pro plan required). The analysis generates a report in a few seconds.</p>',
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
                        'fr' => 'L\'offre gratuite permet jusqu\'à 5 partages actifs. Le plan Pro donne accès aux partages illimités.',
                        'en' => 'The Free plan allows up to 5 active shares. The Pro plan unlocks unlimited sharing.',
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
                                'fr' => '<p>Le plan Free autorise <strong>5 partages actifs</strong> en simultané. Si vous atteignez cette limite, révoquez un partage existant avant d\'en créer un nouveau, ou passez au plan Pro pour des partages illimités.</p>',
                                'en' => '<p>The Free plan allows <strong>5 active shares</strong> at the same time. If you reach the limit, revoke an existing share before creating a new one, or upgrade to Pro for unlimited sharing.</p>',
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
                        'fr' => 'Installer l\'extension MYKEYNEST sur Firefox',
                        'en' => 'Install the MYKEYNEST extension on Firefox',
                    ],
                    'excerpt' => [
                        'fr' => 'Guide d\'installation de l\'extension MYKEYNEST pour Firefox avec remplissage automatique des mots de passe.',
                        'en' => 'Installation guide for the MYKEYNEST Firefox extension with password autofill.',
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
                                'fr' => 'Installer depuis Firefox Add-ons',
                                'en' => 'Install from Firefox Add-ons',
                            ],
                            'content' => [
                                'fr' => '<p>Allez sur <strong>addons.mozilla.org</strong>, recherchez « MYKEYNEST » et cliquez sur <strong>Ajouter à Firefox</strong>. Acceptez les permissions et connectez-vous avec vos identifiants MYKEYNEST.</p>',
                                'en' => '<p>Go to <strong>addons.mozilla.org</strong>, search for “MYKEYNEST”, and click <strong>Add to Firefox</strong>. Accept permissions and sign in with your MYKEYNEST credentials.</p>',
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
                        'fr' => 'Comparatif complet des deux plans : mots de passe, partages, 2FA, audit de sécurité, support prioritaire et prix.',
                        'en' => 'A full comparison of both plans: passwords, sharing, 2FA, security audit, priority support, and pricing.',
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
                                'fr' => '<p>Les deux plans incluent le chiffrement AES-256, la synchro multi-appareils et le générateur. Le plan Pro débloque :</p><ul><li>Mots de passe <strong>illimités</strong> (vs 5 en Free)</li><li>Partages <strong>illimités</strong> (vs 5 en Free)</li><li>Authentification 2FA</li><li>Audit de sécurité</li><li>Notes sécurisées</li><li>Support prioritaire &lt;2h</li></ul>',
                                'en' => '<p>Both plans include AES-256 encryption, multi-device sync, and the generator. The Pro plan unlocks:</p><ul><li><strong>Unlimited</strong> passwords (vs 5 on Free)</li><li><strong>Unlimited</strong> sharing (vs 5 on Free)</li><li>2FA authentication</li><li>Security audit</li><li>Secure notes</li><li>Priority support (&lt;2h)</li></ul>',
                            ],
                        ],
                        [
                            'id' => 'prix',
                            'title' => [
                                'fr' => 'Prix du plan Pro',
                                'en' => 'Pro plan pricing',
                            ],
                            'content' => [
                                'fr' => '<p>Le plan Pro est disponible à <strong>6,99 € / mois</strong>, sans engagement. Aucune carte bancaire requise pour l\'offre gratuite.</p>',
                                'en' => '<p>The Pro plan is available for <strong>€6.99 / month</strong>, no commitment. No credit card is required for the Free plan.</p>',
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
                        'fr' => 'Annulez à tout moment depuis vos paramètres. Vous conservez l\'accès Pro jusqu\'à la fin de la période en cours.',
                        'en' => 'Cancel anytime from your settings. You keep Pro access until the end of the current billing period.',
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
                                'fr' => 'Annuler depuis les paramètres',
                                'en' => 'Cancel from settings',
                            ],
                            'content' => [
                                'fr' => '<p>Allez dans <strong>Paramètres › Abonnement</strong> et cliquez sur <strong>Annuler l\'abonnement</strong>. Votre accès Pro reste actif jusqu\'à la fin de la période mensuelle payée.</p>',
                                'en' => '<p>Go to <strong>Settings › Subscription</strong> and click <strong>Cancel subscription</strong>. Your Pro access stays active until the end of the paid billing period.</p>',
                            ],
                        ],
                        [
                            'id' => 'donnees',
                            'title' => [
                                'fr' => 'Que deviennent mes données ?',
                                'en' => 'What happens to my data?',
                            ],
                            'content' => [
                                'fr' => '<p>Vos identifiants sont conservés. Les mots de passe au-delà du 5ème deviennent en lecture seule jusqu\'à ce que vous repassiez au Pro ou en supprimiez.</p>',
                                'en' => '<p>Your credentials are kept. Passwords beyond the 5th become read-only until you upgrade again or delete some.</p>',
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
                    'fr' => 'Qu\'est-ce que l\'architecture zero-knowledge ?',
                    'en' => 'What is zero-knowledge architecture?',
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
