<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Publish three bilingual SEO articles about controlled access sharing and offboarding';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->articles() as $article) {
            $this->addSql(
                'INSERT INTO article (slug_fr, slug_en, seo_title_fr, seo_title_en, meta_desc_fr, meta_desc_en, h1_fr, h1_en, content_fr, content_en, cover_image, cover_alt_fr, cover_alt_en, published_at, updated_at)
                 SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                 WHERE NOT EXISTS (SELECT 1 FROM article WHERE slug_fr = ? OR slug_en = ?)',
                [
                    $article['slug_fr'],
                    $article['slug_en'],
                    $article['seo_title_fr'],
                    $article['seo_title_en'],
                    $article['meta_desc_fr'],
                    $article['meta_desc_en'],
                    $article['h1_fr'],
                    $article['h1_en'],
                    $article['content_fr'],
                    $article['content_en'],
                    $article['cover_image'],
                    $article['cover_alt_fr'],
                    $article['cover_alt_en'],
                    $article['published_at'],
                    $article['published_at'],
                    $article['slug_fr'],
                    $article['slug_en'],
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM article WHERE slug_fr IN (?, ?, ?)',
            [
                'partager-acces-sans-reveler-mot-de-passe',
                'gestion-acces-clients-agence-web',
                'depart-collaborateur-revoquer-acces',
            ]
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function articles(): array
    {
        return [
            [
                'slug_fr' => 'partager-acces-sans-reveler-mot-de-passe',
                'slug_en' => 'share-access-without-revealing-password',
                'seo_title_fr' => 'Partager un mot de passe sans le révéler | Guide PME',
                'seo_title_en' => 'Share Access Without Revealing the Password | SMB Guide',
                'meta_desc_fr' => 'Donnez à un collaborateur ou prestataire accès à un compte sans lui communiquer le mot de passe. Méthode, limites et checklist pour PME.',
                'meta_desc_en' => 'Give an employee or contractor access to an account without sending them the password. A practical method, limitations and SMB checklist.',
                'h1_fr' => 'Comment partager un accès sans révéler le mot de passe ?',
                'h1_en' => 'How to Share Account Access Without Revealing the Password',
                'cover_image' => 'partager-acces-sans-mot-de-passe.webp',
                'cover_alt_fr' => 'Accès sécurisé transmis à un prestataire sans révéler le mot de passe',
                'cover_alt_en' => 'Secure account access shared with a contractor without revealing the password',
                'published_at' => '2026-09-10 09:00:00',
                'content_fr' => <<<'HTML'
<p>Une agence, un freelance ou un collaborateur externe doit se connecter à votre CMS, votre outil publicitaire ou votre extranet. Le réflexe habituel consiste encore à envoyer le mot de passe par e-mail ou messagerie. C’est rapide, mais vous perdez immédiatement le contrôle : le secret peut être copié, conservé ou transmis sans que vous le sachiez.</p>

<p>Une approche plus propre consiste à <strong>partager la capacité de se connecter plutôt que le mot de passe lui-même</strong>. L’utilisateur autorisé retrouve le compte dans son extension, lance le remplissage automatique et accède au service sans afficher ni copier le secret.</p>

<h2>Pourquoi éviter l’envoi direct du mot de passe ?</h2>

<p>Un mot de passe reçu dans une conversation reste souvent dans l’historique pendant des mois. Même si la mission est terminée, il peut encore être présent sur un téléphone, une boîte e-mail ou une sauvegarde. Il devient également difficile de savoir qui le possède réellement.</p>

<p>La CNIL conseille de limiter le nombre de personnes connaissant un mot de passe, de revoir régulièrement les droits et de favoriser l’usage d’un gestionnaire de mots de passe. Elle recommande aussi de supprimer les permissions lorsqu’un utilisateur n’est plus habilité. Consultez ses recommandations sur la <a href="https://www.cnil.fr/fr/gerer-les-utilisateurs" target="_blank" rel="noopener noreferrer">gestion des utilisateurs</a> et les <a href="https://www.cnil.fr/fr/securite-gerer-les-habilitations" target="_blank" rel="noopener noreferrer">habilitations</a>.</p>

<h2>Le principe de l’accès « connexion uniquement »</h2>

<p>Le propriétaire conserve l’identifiant dans son coffre et choisit le niveau d’accès du destinataire. En mode connexion uniquement :</p>

<ul>
  <li>le destinataire ne peut pas afficher le mot de passe dans l’application ;</li>
  <li>la copie du secret est désactivée ;</li>
  <li>la connexion se fait depuis l’extension sur le domaine prévu ;</li>
  <li>le propriétaire peut retirer l’accès quand il le souhaite ;</li>
  <li>le destinataire ne peut pas modifier ou supprimer l’identifiant du propriétaire.</li>
</ul>

<p>Cette séparation est utile lorsqu’une personne doit utiliser un compte sans avoir besoin d’en devenir propriétaire. Elle ne remplace pas les comptes nominatifs proposés par certains logiciels, mais elle améliore fortement les situations où un compte partagé reste techniquement nécessaire.</p>

<h2>Une méthode simple en cinq étapes</h2>

<h3>1. Vérifier si le service propose des comptes individuels</h3>

<p>Avant de partager un accès, cherchez une fonction « utilisateurs », « membres » ou « rôles ». Un compte nominatif avec les droits strictement nécessaires reste préférable. Le partage contrôlé répond surtout aux services qui ne proposent qu’un compte commun ou dont la gestion des rôles est trop limitée.</p>

<h3>2. Enregistrer la bonne adresse de connexion</h3>

<p>Associez l’identifiant au domaine réellement utilisé. L’extension peut alors proposer le bon compte sur le bon site et éviter les erreurs entre plusieurs interfaces proches, par exemple production, préproduction et administration.</p>

<h3>3. Choisir le destinataire et la durée</h3>

<p>Partagez uniquement avec la personne concernée. Pour une mission courte, notez dès le départ la date de fin prévue. Une autorisation temporaire qui n’est jamais revue finit souvent par devenir permanente.</p>

<h3>4. Autoriser la connexion sans affichage</h3>

<p>Dans MYKEYNEST, choisissez le partage qui permet l’utilisation via l’extension sans donner la possibilité de voir ou copier le mot de passe. Le destinataire se connecte avec son propre compte MYKEYNEST, puis sélectionne l’identifiant autorisé.</p>

<h3>5. Révoquer et contrôler à la fin de la mission</h3>

<p>Retirez le partage dès que la collaboration se termine. Si le mot de passe a déjà été communiqué auparavant ou si vous soupçonnez une copie, changez-le également sur le service cible.</p>

<h2>Quelles sont les limites ?</h2>

<p>Aucun mécanisme dans un navigateur ne transforme un compte partagé en compte nominatif. Une personne connectée peut parfois modifier des informations depuis le site cible si ce service lui en donne le droit. Pour les outils sensibles, combinez donc plusieurs mesures :</p>

<ul>
  <li>activer l’authentification multifacteur quand elle est disponible ;</li>
  <li>éviter de partager un compte administrateur pour une tâche ordinaire ;</li>
  <li>limiter l’accès à la durée et au périmètre nécessaires ;</li>
  <li>contrôler l’historique fourni par le service cible ;</li>
  <li>préférer les comptes individuels dès que le service les permet.</li>
</ul>

<p>Le NIST rappelle par ailleurs qu’un mot de passe n’est pas, à lui seul, résistant au phishing. Pour les comptes critiques, privilégiez quand c’est possible une authentification forte ou résistante au phishing. Voir les <a href="https://pages.nist.gov/800-63-4/sp800-63b.html" target="_blank" rel="noopener noreferrer">recommandations d’authentification NIST SP 800-63B-4</a>.</p>

<h2>Checklist avant de donner un accès à un prestataire</h2>

<ul>
  <li>Le besoin et le responsable de l’accès sont identifiés.</li>
  <li>Un compte individuel n’est pas disponible ou n’est pas adapté.</li>
  <li>Le niveau de permission est limité à la mission.</li>
  <li>Le mot de passe n’est pas envoyé dans une conversation.</li>
  <li>Une date de revue ou de révocation est prévue.</li>
  <li>La 2FA est activée sur les comptes importants.</li>
</ul>

<h2>Mettre en place ce fonctionnement avec MYKEYNEST</h2>

<p>MYKEYNEST centralise les identifiants, le partage avec des collaborateurs et le remplissage depuis l’extension navigateur. Le propriétaire reste maître de ses accès et peut retirer une autorisation sans rechercher le mot de passe dans les anciennes conversations.</p>

<p><a href="/gestionnaire-mot-de-passe-entreprise">Découvrir la gestion des accès MYKEYNEST</a> ou <a href="/register">créer gratuitement votre coffre</a>.</p>
HTML,
                'content_en' => <<<'HTML'
<p>An agency, freelancer, or external contractor needs to sign in to your CMS, advertising platform, or client portal. The usual shortcut is to send the password by email or chat. It is fast, but control is lost immediately: the secret can be copied, retained, or forwarded without your knowledge.</p>

<p>A cleaner approach is to <strong>share the ability to sign in rather than the password itself</strong>. The authorized user finds the account in a browser extension, starts autofill, and accesses the service without displaying or copying the secret.</p>

<h2>Why should you avoid sending passwords directly?</h2>

<p>A password sent in a conversation often remains in history for months. Even after the assignment ends, it may still exist on a phone, in a mailbox, or in a backup. It also becomes difficult to know who actually has it.</p>

<p>France’s data protection authority recommends limiting the number of people who know a password, reviewing rights regularly, and using a password manager. It also recommends removing permissions when a user is no longer authorized. See the CNIL guidance on <a href="https://www.cnil.fr/fr/gerer-les-utilisateurs" target="_blank" rel="noopener noreferrer">user management</a> and <a href="https://www.cnil.fr/fr/securite-gerer-les-habilitations" target="_blank" rel="noopener noreferrer">access rights</a>.</p>

<h2>How connection-only access works</h2>

<p>The owner keeps the credential in their vault and selects the recipient’s access level. With connection-only access:</p>

<ul>
  <li>the recipient cannot reveal the password in the application;</li>
  <li>copying the secret is disabled;</li>
  <li>sign-in is performed by the extension on the intended domain;</li>
  <li>the owner can revoke access at any time;</li>
  <li>the recipient cannot edit or delete the owner’s credential.</li>
</ul>

<p>This separation is useful when someone needs to use an account without becoming its owner. It does not replace individual accounts offered by some services, but it improves situations where a shared account is still technically required.</p>

<h2>A simple five-step process</h2>

<h3>1. Check whether the service supports individual accounts</h3>

<p>Before sharing access, look for a users, members, or roles feature. A named account with only the necessary rights remains preferable. Controlled sharing is mainly for services that only offer a common account or have insufficient role management.</p>

<h3>2. Save the correct sign-in address</h3>

<p>Associate the credential with the domain that is actually used. The extension can then suggest the right account on the right site and avoid confusion between similar production, staging, and administration interfaces.</p>

<h3>3. Choose the recipient and duration</h3>

<p>Share only with the person concerned. For a short assignment, record the expected end date from the start. Temporary permission that is never reviewed often becomes permanent.</p>

<h3>4. Allow sign-in without password visibility</h3>

<p>In MYKEYNEST, choose the sharing option that allows use through the extension without permission to view or copy the password. The recipient signs in with their own MYKEYNEST account and selects the authorized credential.</p>

<h3>5. Revoke and review access when the assignment ends</h3>

<p>Remove the share as soon as the collaboration ends. If the password was previously disclosed or you suspect it was copied, change it on the target service as well.</p>

<h2>What are the limitations?</h2>

<p>No browser mechanism turns a shared account into an individual account. Once signed in, a person may still be able to change information if the target service grants that permission. For sensitive tools, combine several measures:</p>

<ul>
  <li>enable multi-factor authentication when available;</li>
  <li>do not share an administrator account for an ordinary task;</li>
  <li>limit access to the required time and scope;</li>
  <li>review the activity history provided by the target service;</li>
  <li>prefer individual accounts whenever the service supports them.</li>
</ul>

<p>NIST also notes that passwords alone are not phishing-resistant. For critical accounts, prefer strong or phishing-resistant authentication whenever possible. Read the <a href="https://pages.nist.gov/800-63-4/sp800-63b.html" target="_blank" rel="noopener noreferrer">NIST SP 800-63B-4 authentication guidance</a>.</p>

<h2>Contractor access checklist</h2>

<ul>
  <li>The need and the access owner are identified.</li>
  <li>An individual account is unavailable or unsuitable.</li>
  <li>Permissions are limited to the assignment.</li>
  <li>The password is not sent in a conversation.</li>
  <li>A review or revocation date is scheduled.</li>
  <li>MFA is enabled on important accounts.</li>
</ul>

<h2>Use this workflow with MYKEYNEST</h2>

<p>MYKEYNEST centralizes credentials, sharing with collaborators, and browser-extension autofill. Owners stay in control and can remove permission without searching old conversations for a password.</p>

<p><a href="/gestionnaire-mot-de-passe-entreprise">Explore MYKEYNEST access management</a> or <a href="/register">create your free vault</a>.</p>
HTML,
            ],
            [
                'slug_fr' => 'gestion-acces-clients-agence-web',
                'slug_en' => 'client-access-management-web-agency',
                'seo_title_fr' => 'Agence web : sécuriser les accès clients | Checklist',
                'seo_title_en' => 'Client Access Management for Web Agencies | Checklist',
                'meta_desc_fr' => 'Agence web : organisez les accès aux comptes clients, réduisez les mots de passe envoyés par message et simplifiez les arrivées et départs.',
                'meta_desc_en' => 'Organize client account access in your web agency, reduce passwords sent in chat, and simplify onboarding and offboarding with this checklist.',
                'h1_fr' => 'Gestion des accès clients en agence web : la méthode simple',
                'h1_en' => 'Client Access Management for Web Agencies: A Practical Method',
                'cover_image' => 'gestion-acces-agence-web.webp',
                'cover_alt_fr' => 'Agence web organisant les accès à plusieurs comptes et boutiques clients',
                'cover_alt_en' => 'Web agency organizing access to multiple client accounts and stores',
                'published_at' => '2026-09-08 09:00:00',
                'content_fr' => <<<'HTML'
<p>Une agence web manipule rapidement des dizaines d’accès : WordPress, Shopify, PrestaShop, hébergement, nom de domaine, analytics, campagnes publicitaires, CRM, e-mailing et outils de support. Le problème n’est pas seulement de stocker ces identifiants. Il faut savoir <strong>à quel client ils appartiennent, qui peut les utiliser et comment retirer un accès sans désorganiser le projet</strong>.</p>

<p>Une organisation légère suffit souvent à supprimer les mots de passe dispersés dans Slack, Teams, les e-mails et les documents partagés.</p>

<h2>Les trois erreurs les plus fréquentes</h2>

<h3>Un même compte utilisé par toute l’agence</h3>

<p>Un compte générique partagé entre tous les collaborateurs empêche d’attribuer clairement les actions et complique les départs. Lorsque le service le permet, créez des comptes nominatifs et attribuez un rôle adapté : administrateur, éditeur, analyste ou support.</p>

<h3>Des mots de passe stockés dans le mauvais outil</h3>

<p>Un gestionnaire de tâches ou une messagerie n’est pas conçu pour gérer le cycle de vie d’un secret. Un mot de passe copié dans un ticket peut être indexé, exporté ou visible par des personnes qui n’interviennent pas sur le projet.</p>

<h3>Les accès sont créés, mais jamais retirés</h3>

<p>La fin d’un projet, le remplacement d’un freelance ou le départ d’un salarié doit déclencher une revue. La CNIL recommande de documenter les procédures d’arrivée et de départ, de respecter le principe de moindre privilège et de supprimer les permissions dès qu’elles ne sont plus nécessaires.</p>

<h2>Construire un inventaire utile, pas un tableur oublié</h2>

<p>Pour chaque client, listez les services réellement utilisés. Chaque accès doit avoir au minimum :</p>

<ul>
  <li>un nom compréhensible par toute l’équipe ;</li>
  <li>l’adresse exacte de connexion ;</li>
  <li>le client ou projet concerné ;</li>
  <li>un propriétaire responsable ;</li>
  <li>les personnes ou groupes autorisés ;</li>
  <li>une date de dernière vérification pour les accès sensibles.</li>
</ul>

<p>Évitez les noms comme « Admin client » ou « Compte principal ». Préférez « Boutique Shopify — production — Client X » ou « Google Ads — agence — Client Y ». Une convention stable améliore la recherche et limite les erreurs.</p>

<h2>Organiser les accès par client et par rôle</h2>

<p>Une bonne structure sépare le coffre interne de l’agence des accès appartenant aux clients. Dans chaque espace client, les groupes peuvent ensuite représenter les équipes réellement impliquées : projet, développement, acquisition ou support.</p>

<p>Ne donnez pas automatiquement tous les accès à tous les membres. Un développeur chargé d’une correction WordPress n’a pas nécessairement besoin du compte bancaire, du CRM ou de la console publicitaire du client.</p>

<h2>Quand utiliser un compte nominatif ou un partage contrôlé ?</h2>

<table>
  <thead>
    <tr><th>Situation</th><th>Solution recommandée</th></tr>
  </thead>
  <tbody>
    <tr><td>Le service propose des rôles complets</td><td>Créer un compte nominatif</td></tr>
    <tr><td>Un compte commun est imposé</td><td>Partager via un coffre avec révocation</td></tr>
    <tr><td>Le prestataire doit seulement se connecter</td><td>Utiliser un accès connexion uniquement</td></tr>
    <tr><td>Accès administrateur critique</td><td>Limiter fortement, activer la 2FA et tracer les changements</td></tr>
  </tbody>
</table>

<p>Le partage connexion uniquement réduit la diffusion du mot de passe, mais ne doit pas donner davantage de droits que nécessaire sur le site cible. Les rôles natifs du service restent la première ligne de contrôle lorsqu’ils existent.</p>

<h2>Le processus à appliquer sur chaque nouveau projet</h2>

<ol>
  <li><strong>Désigner le propriétaire :</strong> le client doit savoir qui contrôle le compte principal.</li>
  <li><strong>Collecter proprement :</strong> éviter l’envoi dans une conversation et importer directement dans le coffre prévu.</li>
  <li><strong>Classer :</strong> rattacher chaque identifiant au client et à la bonne adresse de connexion.</li>
  <li><strong>Attribuer :</strong> donner l’accès aux personnes ou groupes concernés uniquement.</li>
  <li><strong>Tester :</strong> vérifier le remplissage et la méthode de récupération avant une urgence.</li>
  <li><strong>Prévoir la sortie :</strong> documenter ce qui sera transféré ou révoqué à la fin du contrat.</li>
</ol>

<h2>Checklist mensuelle pour le responsable d’agence</h2>

<ul>
  <li>Les projets terminés n’ont plus de partages actifs inutiles.</li>
  <li>Les anciens collaborateurs ont été retirés des groupes.</li>
  <li>Les comptes critiques disposent d’une 2FA et de moyens de récupération maîtrisés.</li>
  <li>Les identifiants en doublon ou sans propriétaire sont corrigés.</li>
  <li>Les mots de passe révélés lors d’un incident ou d’un dépannage ont été remplacés.</li>
  <li>Le client peut récupérer ses accès sans dépendre d’une seule personne de l’agence.</li>
</ul>

<h2>Ce que MYKEYNEST apporte à une agence</h2>

<p>MYKEYNEST réunit le coffre, les groupes, le partage sécurisé et le remplissage navigateur. L’agence peut organiser les accès par équipe, épingler les comptes utilisés chaque jour et retirer un partage lorsque le périmètre d’un prestataire change.</p>

<p>Le bénéfice n’est pas d’ajouter une procédure lourde. Il est de remplacer les échanges improvisés par un parcours suffisamment simple pour être réellement adopté.</p>

<p><a href="/gestionnaire-mot-de-passe-entreprise">Voir les fonctionnalités pour les équipes</a> ou <a href="/register">tester MYKEYNEST gratuitement</a>.</p>
HTML,
                'content_en' => <<<'HTML'
<p>A web agency quickly handles dozens of logins: WordPress, Shopify, PrestaShop, hosting, domains, analytics, advertising platforms, CRM, email marketing, and support tools. The challenge is not merely storing them. You need to know <strong>which client owns them, who can use them, and how to remove access without disrupting the project</strong>.</p>

<p>A lightweight process is often enough to remove passwords scattered across Slack, Teams, email, and shared documents.</p>

<h2>The three most common mistakes</h2>

<h3>One account is used by the entire agency</h3>

<p>A generic account shared by every team member makes actions harder to attribute and departures harder to manage. When supported, create named accounts and assign suitable roles such as administrator, editor, analyst, or support.</p>

<h3>Passwords are stored in the wrong tool</h3>

<p>A task manager or messaging platform is not designed to manage a secret’s lifecycle. A password pasted into a ticket may be indexed, exported, or visible to people who are not working on that project.</p>

<h3>Access is created but never removed</h3>

<p>The end of a project, replacement of a freelancer, or departure of an employee should trigger a review. The CNIL recommends documenting onboarding and offboarding procedures, applying least privilege, and removing permissions as soon as they are no longer required.</p>

<h2>Build a useful inventory, not a forgotten spreadsheet</h2>

<p>For every client, list the services that are actually used. Each access record should include at least:</p>

<ul>
  <li>a name everyone on the team understands;</li>
  <li>the exact sign-in address;</li>
  <li>the relevant client or project;</li>
  <li>an accountable owner;</li>
  <li>the authorized people or groups;</li>
  <li>a last-review date for sensitive access.</li>
</ul>

<p>Avoid labels such as “Client admin” or “Main account.” Prefer “Shopify store — production — Client X” or “Google Ads — agency — Client Y.” A stable convention improves search and reduces mistakes.</p>

<h2>Organize access by client and role</h2>

<p>A good structure separates the agency’s internal vault from credentials owned by clients. Within each client space, groups can represent the teams that are actually involved: project, development, acquisition, or support.</p>

<p>Do not automatically grant every credential to every member. A developer working on a WordPress fix does not necessarily need the client’s bank, CRM, or advertising-console account.</p>

<h2>When should you use an individual account or controlled sharing?</h2>

<table>
  <thead>
    <tr><th>Situation</th><th>Recommended solution</th></tr>
  </thead>
  <tbody>
    <tr><td>The service provides complete role management</td><td>Create an individual account</td></tr>
    <tr><td>A common account is required</td><td>Share it through a revocable vault</td></tr>
    <tr><td>The contractor only needs to sign in</td><td>Use connection-only access</td></tr>
    <tr><td>Critical administrator access</td><td>Restrict it, enable MFA, and track changes</td></tr>
  </tbody>
</table>

<p>Connection-only sharing reduces password disclosure, but it must not grant more permission than necessary on the target website. Native roles remain the first control when they are available.</p>

<h2>A process for every new project</h2>

<ol>
  <li><strong>Name the owner:</strong> the client should know who controls the main account.</li>
  <li><strong>Collect securely:</strong> avoid sending passwords in chat and add them directly to the intended vault.</li>
  <li><strong>Classify:</strong> associate each credential with the client and correct sign-in address.</li>
  <li><strong>Assign:</strong> grant access only to the relevant people or groups.</li>
  <li><strong>Test:</strong> verify autofill and recovery before an emergency occurs.</li>
  <li><strong>Plan the exit:</strong> document what will be transferred or revoked at the end of the contract.</li>
</ol>

<h2>Monthly checklist for agency managers</h2>

<ul>
  <li>Completed projects no longer have unnecessary active shares.</li>
  <li>Former collaborators have been removed from groups.</li>
  <li>Critical accounts have MFA and controlled recovery methods.</li>
  <li>Duplicate credentials and credentials without an owner are corrected.</li>
  <li>Passwords disclosed during an incident or support session have been changed.</li>
  <li>The client can recover access without depending on one person at the agency.</li>
</ul>

<h2>How MYKEYNEST helps an agency</h2>

<p>MYKEYNEST combines a vault, groups, secure sharing, and browser autofill. Agencies can organize credentials by team, pin frequently used accounts, and revoke a share when a contractor’s scope changes.</p>

<p>The goal is not to add a heavy process. It is to replace improvised exchanges with a workflow simple enough that people actually use it.</p>

<p><a href="/gestionnaire-mot-de-passe-entreprise">See the team features</a> or <a href="/register">try MYKEYNEST for free</a>.</p>
HTML,
            ],
            [
                'slug_fr' => 'depart-collaborateur-revoquer-acces',
                'slug_en' => 'employee-offboarding-revoke-access-checklist',
                'seo_title_fr' => 'Départ d’un collaborateur : révoquer tous ses accès',
                'seo_title_en' => 'Employee Offboarding: Access Revocation Checklist',
                'meta_desc_fr' => 'Checklist de départ d’un collaborateur : comptes à fermer, accès à révoquer, mots de passe à changer et contrôles pour éviter les oublis.',
                'meta_desc_en' => 'Employee offboarding checklist: close accounts, revoke access, rotate passwords and verify ownership without disrupting the remaining team.',
                'h1_fr' => 'Départ d’un collaborateur : la checklist pour révoquer ses accès',
                'h1_en' => 'Employee Offboarding: A Checklist to Revoke Access Safely',
                'cover_image' => 'depart-collaborateur-acces.webp',
                'cover_alt_fr' => 'Révocation des accès lors du départ d’un collaborateur',
                'cover_alt_en' => 'Revoking account access during employee offboarding',
                'published_at' => '2026-09-05 09:00:00',
                'content_fr' => <<<'HTML'
<p>Lorsqu’un collaborateur ou un prestataire quitte l’entreprise, rendre son ordinateur ne suffit pas. Il peut encore disposer d’un accès à la messagerie, au CRM, à l’hébergement, aux réseaux sociaux, aux boutiques en ligne ou à des comptes partagés dont personne n’a dressé la liste.</p>

<p>Une procédure de départ efficace doit être courte, attribuée à des responsables précis et préparée avant le dernier jour. L’objectif est double : <strong>retirer les droits de la personne sortante sans bloquer l’activité de l’équipe restante</strong>.</p>

<h2>Pourquoi les départs créent-ils des accès oubliés ?</h2>

<p>Au fil des missions, les autorisations s’accumulent. Certaines sont accordées directement dans un logiciel, d’autres passent par un groupe, un mot de passe commun, une extension ou une invitation temporaire. Sans inventaire central, la personne chargée du départ doit reconstruire tout l’historique dans l’urgence.</p>

<p>L’ANSSI recommande d’intégrer les arrivées, départs et changements de fonction aux procédures de l’entreprise, et de révoquer l’ensemble des droits lorsqu’une personne part. La CNIL demande également de supprimer les permissions dès la fin de l’habilitation et de revoir régulièrement les droits accordés.</p>

<h2>Préparer la révocation avant le dernier jour</h2>

<p>Le manager, les ressources humaines et la personne responsable de l’informatique doivent partager la même information : date et heure de fin, niveau de risque, matériel à restituer et continuité nécessaire. Pour un administrateur ou une personne ayant accès à des données sensibles, la chronologie doit être particulièrement précise.</p>

<p>Créez une liste des catégories à contrôler :</p>

<ul>
  <li>messagerie, agenda et stockage de fichiers ;</li>
  <li>CRM, facturation, banque et outils métiers ;</li>
  <li>CMS, hébergement, noms de domaine et dépôts de code ;</li>
  <li>réseaux sociaux, publicité, analytics et e-mailing ;</li>
  <li>VPN, Wi-Fi, accès distant et consoles d’administration ;</li>
  <li>gestionnaire de mots de passe, groupes et partages directs ;</li>
  <li>appareils, clés physiques, badges et moyens de récupération.</li>
</ul>

<h2>La checklist du jour du départ</h2>

<h3>1. Désactiver les comptes nominatifs</h3>

<p>Suspendez ou fermez les comptes individuels selon les règles de conservation de l’entreprise. Ne supprimez pas aveuglément une boîte ou des fichiers contenant des informations nécessaires : transférez d’abord la propriété vers un responsable autorisé.</p>

<h3>2. Retirer la personne des groupes et équipes</h3>

<p>Un compte désactivé dans un outil ne retire pas automatiquement les invitations présentes ailleurs. Vérifiez les groupes de travail, les équipes, les espaces clients et les partages directs.</p>

<h3>3. Révoquer les sessions et appareils</h3>

<p>Fermez les sessions actives et retirez les appareils associés lorsque le service le permet. Cette étape est importante si la personne a utilisé un appareil personnel ou une extension navigateur.</p>

<h3>4. Faire tourner les secrets réellement partagés</h3>

<p>Changez les mots de passe, clés API ou codes de récupération que la personne a pu voir ou copier. Une rotation systématique de tous les secrets peut être inutilement perturbante ; concentrez-vous sur ceux dont la confidentialité n’est plus garantie.</p>

<h3>5. Vérifier la continuité</h3>

<p>Confirmez qu’un autre membre autorisé peut accéder aux comptes essentiels, recevoir les codes de récupération et administrer les domaines ou services payants. Aucun compte critique ne doit dépendre d’une seule personne.</p>

<h2>Que faire des comptes partagés ?</h2>

<p>Un compte partagé impose de distinguer deux situations :</p>

<ul>
  <li><strong>le mot de passe était visible :</strong> changez-le et mettez à jour le coffre ;</li>
  <li><strong>la personne disposait uniquement d’un partage contrôlé :</strong> révoquez son accès et vérifiez les sessions actives sur le service cible.</li>
</ul>

<p>Pour les services importants, vérifiez également la 2FA. Un ancien téléphone, une clé de sécurité ou un code de récupération peut continuer à fonctionner même après la modification du mot de passe.</p>

<h2>Après le départ : contrôler plutôt que supposer</h2>

<p>Dans les jours suivants, consultez les journaux disponibles sur les services critiques et confirmez que les invitations ont disparu. Notez qui a exécuté chaque action et à quelle date. Cette trace évite de refaire le même travail et facilite les contrôles futurs.</p>

<p>La <a href="https://www.cnil.fr/fr/securite-gerer-les-habilitations" target="_blank" rel="noopener noreferrer">fiche de la CNIL sur les habilitations</a> recommande une revue régulière, au minimum annuelle, afin de supprimer les comptes inutilisés et de réaligner les droits sur les fonctions. Le <a href="https://messervices.cyber.gouv.fr/documents-guides/guide_hygiene_informatique_anssi.pdf" target="_blank" rel="noopener noreferrer">guide d’hygiène informatique de l’ANSSI</a> fournit également une base utile pour formaliser les arrivées et départs.</p>

<h2>Une procédure réutilisable</h2>

<ol>
  <li>Déclenchement par le manager ou les RH.</li>
  <li>Inventaire automatique ou manuel des accès.</li>
  <li>Validation de la liste par le responsable métier.</li>
  <li>Révocation à l’heure prévue.</li>
  <li>Transfert de propriété et rotation des secrets concernés.</li>
  <li>Contrôle final et archivage de la checklist.</li>
</ol>

<h2>Centraliser les partages avec MYKEYNEST</h2>

<p>Dans MYKEYNEST, le propriétaire d’un identifiant peut retirer un partage sans supprimer le mot de passe pour le reste de l’équipe. Les groupes permettent également d’éviter de rechercher chaque accès un par un lorsqu’un membre change de périmètre.</p>

<p><a href="/gestionnaire-mot-de-passe-entreprise">Découvrir MYKEYNEST pour les équipes</a> ou <a href="/register">ouvrir un coffre gratuitement</a>.</p>
HTML,
                'content_en' => <<<'HTML'
<p>When an employee or contractor leaves the company, returning a laptop is not enough. They may still have access to email, CRM, hosting, social media, online stores, or shared accounts that were never inventoried.</p>

<p>An effective offboarding procedure should be short, assigned to specific owners, and prepared before the final day. The goal is twofold: <strong>remove the departing person’s rights without blocking the remaining team</strong>.</p>

<h2>Why does offboarding leave forgotten access behind?</h2>

<p>Permissions accumulate throughout a project. Some are granted directly in an application; others come through a group, shared password, extension, or temporary invitation. Without a central inventory, the person managing the departure has to reconstruct the entire history under time pressure.</p>

<p>ANSSI recommends integrating onboarding, offboarding, and role changes into company procedures and revoking all rights when a person leaves. The CNIL likewise recommends removing permissions as soon as authorization ends and regularly reviewing granted rights.</p>

<h2>Prepare revocation before the final day</h2>

<p>The manager, HR, and the person responsible for IT should share the same information: end date and time, risk level, equipment to return, and required business continuity. For an administrator or someone with access to sensitive data, the timeline should be especially precise.</p>

<p>Create a list of categories to review:</p>

<ul>
  <li>email, calendar, and file storage;</li>
  <li>CRM, billing, banking, and business applications;</li>
  <li>CMS, hosting, domains, and code repositories;</li>
  <li>social media, advertising, analytics, and email marketing;</li>
  <li>VPN, Wi-Fi, remote access, and administration consoles;</li>
  <li>password manager, groups, and direct shares;</li>
  <li>devices, hardware keys, badges, and recovery methods.</li>
</ul>

<h2>Checklist for the departure day</h2>

<h3>1. Disable individual accounts</h3>

<p>Suspend or close individual accounts according to the company’s retention rules. Do not blindly delete a mailbox or files containing required information: transfer ownership to an authorized manager first.</p>

<h3>2. Remove the person from groups and teams</h3>

<p>Disabling an account in one tool does not automatically cancel invitations elsewhere. Review work groups, teams, client spaces, and direct shares.</p>

<h3>3. Revoke sessions and devices</h3>

<p>Close active sessions and remove associated devices when the service supports it. This matters when the person used a personal device or browser extension.</p>

<h3>4. Rotate secrets that were genuinely shared</h3>

<p>Change passwords, API keys, or recovery codes that the person may have viewed or copied. Rotating every secret can cause unnecessary disruption; focus on those whose confidentiality is no longer assured.</p>

<h3>5. Verify continuity</h3>

<p>Confirm that another authorized team member can access essential accounts, receive recovery codes, and administer domains or paid services. No critical account should depend on one person.</p>

<h2>What should you do with shared accounts?</h2>

<p>A shared account creates two different situations:</p>

<ul>
  <li><strong>the password was visible:</strong> change it and update the vault;</li>
  <li><strong>the person only had controlled access:</strong> revoke the share and review active sessions on the target service.</li>
</ul>

<p>For important services, review MFA as well. An old phone, security key, or recovery code may remain valid even after the password changes.</p>

<h2>After departure: verify instead of assuming</h2>

<p>During the following days, inspect the logs available on critical services and confirm that invitations are gone. Record who performed each action and when. This evidence prevents duplicated work and makes future reviews easier.</p>

<p>The <a href="https://www.cnil.fr/fr/securite-gerer-les-habilitations" target="_blank" rel="noopener noreferrer">CNIL access-rights guidance</a> recommends regular reviews, at least annually, to remove unused accounts and align rights with each role. The <a href="https://messervices.cyber.gouv.fr/documents-guides/guide_hygiene_informatique_anssi.pdf" target="_blank" rel="noopener noreferrer">ANSSI cyber hygiene guide</a> also provides a useful foundation for formal onboarding and offboarding.</p>

<h2>A reusable procedure</h2>

<ol>
  <li>Manager or HR initiates the process.</li>
  <li>Access is inventoried automatically or manually.</li>
  <li>The business owner validates the list.</li>
  <li>Revocation occurs at the scheduled time.</li>
  <li>Ownership is transferred and relevant secrets are rotated.</li>
  <li>A final review is completed and the checklist archived.</li>
</ol>

<h2>Centralize sharing with MYKEYNEST</h2>

<p>In MYKEYNEST, a credential owner can remove a share without deleting the password for the rest of the team. Groups also prevent administrators from searching for every credential individually when a member’s scope changes.</p>

<p><a href="/gestionnaire-mot-de-passe-entreprise">Discover MYKEYNEST for teams</a> or <a href="/register">open a free vault</a>.</p>
HTML,
            ],
        ];
    }
}
