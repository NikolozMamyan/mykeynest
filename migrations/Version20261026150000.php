<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correct the legacy Excel comparison article in French and English without changing its publication date';
    }

    public function up(Schema $schema): void
    {
        $article = $this->connection->fetchAssociative(
            'SELECT id, content_fr, content_en FROM article WHERE slug_fr = ?',
            ['excel-vs-gestionnaire-mots-de-passe-pme'],
        );
        if (!$article || !str_contains($article['content_fr'], 'H2 — Pourquoi Excel')
            || !str_contains($article['content_fr'], 'tu es aveugle')
            || !str_contains($article['content_en'], 'End-to-End Encryption')) {
            return;
        }

        $this->addSql(
            'UPDATE article SET content_fr = ?, content_en = ?, updated_at = ? WHERE id = ? AND content_fr = ? AND content_en = ?',
            [$this->contentFr(), $this->contentEn(), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $article['id'], $article['content_fr'], $article['content_en']],
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore the previous editorial revision from a database backup if required.');
    }

    private function contentFr(): string
    {
        return <<<'HTML'
<p>Un tableur partagé est souvent le premier inventaire des accès d’une PME. Il est familier et facile à transmettre. Mais un inventaire de services et un coffre de mots de passe répondent à deux besoins différents : le premier décrit l’organisation, le second doit encadrer l’accès aux secrets et leur utilisation au quotidien.</p>
<p>Pour choisir une méthode, examinez les droits, les copies du fichier, la récupération du compte et le départ des collaborateurs. La question ne se résume pas au nom du logiciel : un fichier correctement protégé et un gestionnaire mal configuré ne présentent pas les mêmes risques qu’un fichier public et un coffre administré avec soin.</p>
<h2>1. Comprendre les limites du fichier partagé</h2>
<p>Excel peut protéger un fichier par mot de passe et les plateformes de stockage peuvent limiter les personnes autorisées à l’ouvrir. Il serait donc inexact d’affirmer qu’un tableur ne dispose d’aucun chiffrement ou contrôle d’accès. La difficulté apparaît surtout lorsque les identifiants circulent dans plusieurs copies, téléchargements, pièces jointes et historiques de versions.</p>
<p>Retirer une personne du dossier partagé ne supprime pas les copies qu’elle a déjà obtenues. Une permission accordée sur le fichier peut aussi exposer davantage de comptes que nécessaire. Enfin, l’historique de modification d’un document ne prouve pas qu’un mot de passe n’a jamais été consulté ou copié. Vérifiez ces limites dans votre environnement réel plutôt que de supposer que la dernière version du fichier est la seule existante.</p>
<h2>2. Évaluer ce qu’apporte le gestionnaire</h2>
<p>Référence : <a href="https://support.microsoft.com/en-us/excel/get-started/protect-an-excel-file" rel="noopener">documentation Microsoft sur le chiffrement d’un fichier Excel</a>. La protection d’une feuille contre les modifications et le chiffrement du fichier sont des fonctions distinctes.</p>
<p>Un gestionnaire spécialisé permet de centraliser les identifiants, de générer des mots de passe distincts et de partager un périmètre d’accès avec des personnes identifiées. Selon le produit et l’offre, des permissions ou fonctions de suivi supplémentaires peuvent être disponibles. Testez les fonctions nécessaires avant de migrer : tous les gestionnaires ne proposent pas la même architecture ou le même niveau de journalisation.</p>
<p>MYKEYNEST chiffre les mots de passe enregistrés côté serveur avec AES-256-GCM. Le serveur peut les déchiffrer pendant les opérations autorisées : il ne s’agit pas d’un coffre chiffré de bout en bout à connaissance nulle. Si cette propriété est indispensable à votre organisation, comparez ce modèle avec les architectures documentées par les autres fournisseurs.</p>
<h2>3. Comparer sur des critères vérifiables</h2>
<figure class="table"><table><caption>Tableur partagé et gestionnaire : les points à vérifier</caption><thead><tr><th scope="col">Critère</th><th scope="col">Tableur partagé</th><th scope="col">Gestionnaire de mots de passe</th></tr></thead><tbody>
<tr><th scope="row">Chiffrement</th><td>Dépend de la protection du fichier et de son stockage.</td><td>Vérifier l’architecture, la gestion des clés et la récupération.</td></tr>
<tr><th scope="row">Permissions</th><td>Souvent définies au niveau du fichier ou du dossier.</td><td>Permissions sur les éléments ou groupes selon le produit et l’offre.</td></tr>
<tr><th scope="row">Copies et export</th><td>Les copies téléchargées peuvent continuer à circuler.</td><td>Les secrets révélés ou exportés peuvent également être conservés.</td></tr>
<tr><th scope="row">Départ d’un collaborateur</th><td>Retirer les droits et renouveler les secrets concernés.</td><td>Révoquer les partages, fermer les sessions tierces et renouveler les secrets si nécessaire.</td></tr>
<tr><th scope="row">Conformité</th><td>À évaluer dans l’organisation complète.</td><td>Aucune conformité automatique liée au seul usage du logiciel.</td></tr>
</tbody></table></figure>
<h2>4. Tester le partage et la révocation</h2>
<p>Utilisez un compte de démonstration pour inviter une seconde personne. Vérifiez ce qu’elle peut utiliser, afficher et modifier. Dans MYKEYNEST, désactiver l’affichage retire l’action de révélation dans l’interface ; l’extension peut néanmoins remplir un formulaire autorisé. Le navigateur traite alors le mot de passe. Cette permission ne garantit pas qu’une personne qui contrôle l’appareil ne puisse pas l’obtenir.</p>
<p>Révoquez ensuite le partage. L’accès par le coffre doit être retiré, mais une session déjà ouverte sur le service cible peut rester active. Le responsable doit donc également fermer ces sessions et changer les mots de passe communs lorsque la personne a pu les connaître. C’est une étape de procédure, quel que soit l’outil retenu.</p>
<h2>5. Migrer progressivement et protéger les exports</h2>
<ol><li>Désignez le responsable des comptes critiques et de leur récupération.</li><li>Testez un petit ensemble d’identifiants avant une importation complète.</li><li>Vérifiez les droits avec les utilisateurs concernés et activez la double authentification disponible.</li><li>Contrôlez les fichiers d’export, qui peuvent contenir des secrets en clair, puis supprimez les copies devenues inutiles.</li><li>Documentez le départ d’un collaborateur et prévoyez une revue régulière des accès.</li></ol>
<h2>6. Choisir selon les exigences de votre PME</h2>
<p>Un gestionnaire est adapté lorsque vous devez organiser l’usage et le partage de nombreux identifiants. Le tableur peut rester utile pour l’inventaire des services, des responsables et des procédures, sans y inscrire les mots de passe. La décision doit tenir compte de l’architecture, de la simplicité d’utilisation, du coût de l’offre choisie et des exigences contractuelles. Aucun de ces outils ne suffit à garantir la conformité au RGPD.</p>
<p>Pour poursuivre : <a href="/gestionnaire-mot-de-passe-entreprise">déployer MYKEYNEST dans votre équipe</a>, <a href="/comparatif-password-manager-entreprise">comparer les solutions</a> et <a href="/securite">consulter l’architecture de sécurité et ses limites</a>.</p>
HTML;
    }

    private function contentEn(): string
    {
        return <<<'HTML'
<p>A shared spreadsheet is often a small business's first access inventory. It is familiar and easy to distribute. But an inventory and a password vault serve different purposes: one describes your organization, while the other should control access to secrets and their use in daily work.</p>
<p>To choose a method, examine permissions, file copies, account recovery and employee offboarding. The software name alone does not determine the risk: a protected file and a poorly configured manager differ from a public spreadsheet and a carefully administered vault.</p>
<h2>1. Understand the limits of a shared file</h2>
<p>Excel can protect a file with a password, and storage platforms can restrict who is allowed to open it. Claiming that spreadsheets have no encryption or access controls would therefore be inaccurate. The practical difficulty is often the number of copies, downloads, attachments and version histories in which credentials continue to exist.</p>
<p>Removing a person from a shared folder does not delete copies they already obtained. A permission granted on a whole file can also expose more accounts than necessary. A document's editing history does not prove that a password was never viewed or copied. Check these limits in your own environment rather than assuming that the most recent file is the only one that exists.</p>
<h2>2. Assess what a password manager adds</h2>
<p>Reference: <a href="https://support.microsoft.com/en-us/excel/get-started/protect-an-excel-file" rel="noopener">Microsoft documentation on Excel file encryption</a>. Protecting a worksheet from edits and encrypting a file are different features.</p>
<p>A dedicated manager centralizes credentials, generates distinct passwords and shares a defined set of accesses with identified people. Additional permissions or monitoring features depend on the product and plan. Test the features you require before migrating: password managers differ in their encryption architecture, administration tools and logging capabilities.</p>
<p>MYKEYNEST encrypts stored passwords on the server using AES-256-GCM. The server can decrypt them during authorized operations; it is not an end-to-end encrypted, zero-knowledge vault. If that property is mandatory for your organization, compare this model with the architectures documented by other vendors.</p>
<h2>3. Compare verifiable criteria</h2>
<figure class="table"><table><caption>Shared spreadsheet and password manager: what to check</caption><thead><tr><th scope="col">Criterion</th><th scope="col">Shared spreadsheet</th><th scope="col">Password manager</th></tr></thead><tbody>
<tr><th scope="row">Encryption</th><td>Depends on file protection and storage.</td><td>Check architecture, key management and recovery.</td></tr>
<tr><th scope="row">Permissions</th><td>Often assigned at file or folder level.</td><td>Item or group permissions depend on the product and plan.</td></tr>
<tr><th scope="row">Copies and exports</th><td>Downloaded copies can continue to circulate.</td><td>Revealed or exported secrets can also be retained.</td></tr>
<tr><th scope="row">Employee departure</th><td>Remove permissions and rotate affected secrets.</td><td>Revoke shares, close third-party sessions and rotate secrets where necessary.</td></tr>
<tr><th scope="row">Compliance</th><td>Assess within the organization as a whole.</td><td>Using the software does not automatically establish compliance.</td></tr>
</tbody></table></figure>
<h2>4. Test sharing and revocation</h2>
<p>Use a demonstration account to invite a second person. Check what they can use, reveal and edit. In MYKEYNEST, disabling password reveal removes the reveal action in the interface, while the extension can still fill an authorized form. The browser processes the password during that operation. This permission does not guarantee that a person controlling the device cannot obtain it.</p>
<p>Revoke the share afterwards. Vault access should be removed, but an existing session on the target service can remain active. The responsible person must therefore also close those sessions and rotate shared passwords when the departing user may have obtained them. This procedural step matters regardless of the tool you choose.</p>
<h2>5. Migrate gradually and protect exports</h2>
<ol><li>Assign responsibility for critical accounts and their recovery.</li><li>Test a small set of credentials before a complete import.</li><li>Verify permissions with the affected users and enable available two-factor authentication.</li><li>Control export files, which may contain plaintext secrets, and remove unnecessary copies after verification.</li><li>Document employee offboarding and schedule regular access reviews.</li></ol>
<h2>6. Decide according to your business requirements</h2>
<p>A manager is useful when you need to organize the use and sharing of many credentials. A spreadsheet can remain useful as an inventory of services, owners and procedures without containing the passwords themselves. Consider architecture, usability, the cost of the chosen plan and contractual requirements. Neither tool alone guarantees GDPR compliance.</p>
<p>Continue with <a href="/gestionnaire-mot-de-passe-entreprise?lang=en">rolling out MYKEYNEST for your team</a>, <a href="/comparatif-password-manager-entreprise?lang=en">comparing available solutions</a> and <a href="/securite?lang=en">the security architecture and its limits</a>.</p>
HTML;
    }
}
