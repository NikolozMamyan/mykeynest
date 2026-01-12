<?php

namespace App\DataFixtures;

use App\Entity\Article;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class ArticleFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $article = new Article();

        // Slugs (URL SEO-friendly)
        $article->setSlugFr('comment-securiser-mots-de-passe');
        $article->setSlugEn('how-to-secure-passwords');

        // SEO Title (<= ~60-65 chars idéal)
        $article->setSeoTitleFr('Comment sécuriser ses mots de passe en 2026 | Guide MyKeyNest');
        $article->setSeoTitleEn('How to Secure Your Passwords in 2026 | MyKeyNest Guide');
        $article->setPublishedAt(new \DateTimeImmutable('2026-01-10 10:00:00'));
        $article->setUpdatedAt(new \DateTimeImmutable('2026-01-10 10:00:00'));
        $article->setCoverImage('secure-passwords.webp');
        $article->setCoverAltFr('Sécuriser ses mots de passe');
        $article->setCoverAltEn('How to secure passwords');


        // Meta description (≈ 140–160 chars)
        $article->setMetaDescFr('Guide complet : mots de passe forts, 2FA, gestionnaire de mots de passe, alertes de fuite et bonnes pratiques pour protéger vos comptes en 2026.');
        $article->setMetaDescEn('Complete guide: strong passwords, 2FA, password managers, breach alerts and best practices to protect your accounts in 2026.');

        // H1 (doit coller à l’intention de recherche)
        $article->setH1Fr('Comment sécuriser ses mots de passe : la méthode simple et pro (2026)');
        $article->setH1En('How to Secure Your Passwords: A Simple, Pro Method (2026)');

        // Content FR (HTML stocké en base, rendu via |raw)
        $article->setContentFr($this->contentFr());

        // Content EN
        $article->setContentEn($this->contentEn());

        $manager->persist($article);
        $manager->flush();
    }

    private function contentFr(): string
    {
        return <<<HTML
<p>Entre les fuites de données et le phishing, un mot de passe faible suffit pour compromettre un compte. Dans ce guide, vous allez apprendre une méthode claire pour sécuriser vos mots de passe (et vos accès) en 2026, sans complexité inutile.</p>

<h2>1) Utilisez un gestionnaire de mots de passe (plutôt que votre mémoire)</h2>
<p>La meilleure décision pour la sécurité, c’est de <strong>ne plus réutiliser vos mots de passe</strong>. Un <strong>gestionnaire de mots de passe</strong> vous permet de générer, stocker et synchroniser des identifiants uniques sur tous vos appareils.</p>
<ul>
  <li><strong>Un mot de passe différent</strong> pour chaque service</li>
  <li><strong>Génération automatique</strong> de mots de passe robustes</li>
  <li><strong>Synchronisation</strong> et accès rapides sans copier/coller</li>
</ul>

<h3>À faire maintenant</h3>
<p>Commencez par sécuriser vos comptes critiques : email principal, banque, réseaux sociaux, Apple/Google, accès pro.</p>

<h2>2) Créez des mots de passe vraiment forts</h2>
<p>Un mot de passe fort est <strong>long</strong> et <strong>unique</strong>. Pour la plupart des services, visez <strong>16 à 24 caractères</strong> minimum, avec un mélange de lettres, chiffres et symboles (ou une passphrase très longue).</p>
<ul>
  <li>✅ Longueur &gt; complexité : la longueur compte énormément</li>
  <li>✅ 1 service = 1 mot de passe</li>
  <li>❌ Évitez les patterns : Nom+123, Azerty, dates de naissance</li>
</ul>

<h2>3) Activez la double authentification (2FA) partout</h2>
<p>La 2FA ajoute une barrière. Même si un mot de passe fuit, votre compte reste protégé.</p>
<ul>
  <li>✅ Privilégiez une application d’authentification (TOTP)</li>
  <li>✅ Conservez vos <strong>codes de récupération</strong> dans votre coffre</li>
  <li>⚠️ Le SMS est mieux que rien, mais moins robuste</li>
</ul>

<h2>4) Vérifiez vos mots de passe existants (audit)</h2>
<p>Un bon gestionnaire de mots de passe doit aider à détecter :</p>
<ul>
  <li>mots de passe <strong>réutilisés</strong></li>
  <li>mots de passe <strong>faibles</strong></li>
  <li>mots de passe trop <strong>anciens</strong></li>
</ul>
<p>Fixez un objectif simple : corriger 10 mots de passe par jour. En une semaine, votre niveau de sécurité change complètement.</p>

<h2>5) Protégez-vous contre le phishing (la vraie menace)</h2>
<p>Beaucoup de piratages ne cassent pas les mots de passe : ils les <strong>volent</strong>. Pour réduire le risque :</p>
<ul>
  <li>Vérifiez l’URL avant de vous connecter</li>
  <li>Ne cliquez pas sur des liens “urgence” dans les emails</li>
  <li>Utilisez l’auto-remplissage : il refuse souvent les faux domaines</li>
</ul>

<h2>6) Partagez des accès sans envoyer le mot de passe</h2>
<p>En équipe ou en famille, évitez les messages, email, notes. Utilisez un <strong>partage sécurisé</strong> (droits, révocation, historique), surtout pour les accès pro.</p>

<h2>Checklist rapide (à copier)</h2>
<ul>
  <li>✅ Gestionnaire de mots de passe activé</li>
  <li>✅ Un mot de passe unique par service</li>
  <li>✅ 2FA sur les comptes critiques</li>
  <li>✅ Audit : réutilisés/faibles/anciens</li>
  <li>✅ Vigilance phishing + URLs</li>
</ul>

<h2>Aller plus loin avec MyKeyNest</h2>
<p>Si vous cherchez un <strong>gestionnaire de mots de passe sécurisé</strong> avec synchronisation et partage, vous pouvez démarrer en quelques minutes :</p>
<p><a href="/register">Créer un coffre MyKeyNest</a> · <a href="https://key-nest.com/#pricing">Voir les offres</a></p>

<!-- <p><em>Astuce SEO/Produit :</em> ajoutez un lien interne vers votre page pilier : <a href="/fr/gestionnaire-mots-de-passe">Gestionnaire de mots de passe</a> (si vous la créez).</p> -->
HTML;
    }

    private function contentEn(): string
    {
        return <<<HTML
<p>Between data breaches and phishing, a weak password is enough to lose an account. This guide gives you a clear, professional method to secure your passwords in 2026—without overcomplicating things.</p>

<h2>1) Use a password manager (instead of memory)</h2>
<p>The biggest security upgrade is <strong>stopping password reuse</strong>. A <strong>password manager</strong> lets you generate, store, and sync unique credentials across devices.</p>
<ul>
  <li><strong>One unique password</strong> per service</li>
  <li><strong>Automatic generation</strong> of strong passwords</li>
  <li><strong>Sync</strong> across devices for fast, safe access</li>
</ul>

<h3>Do this now</h3>
<p>Secure your most important accounts first: primary email, banking, social networks, Apple/Google, and any work accounts.</p>

<h2>2) Create truly strong passwords</h2>
<p>Strong passwords are <strong>long</strong> and <strong>unique</strong>. For most services, aim for <strong>16–24 characters</strong>, using a mix of letters, numbers, and symbols (or a long passphrase).</p>
<ul>
  <li>✅ Length beats complexity: longer is significantly stronger</li>
  <li>✅ 1 service = 1 password</li>
  <li>❌ Avoid patterns: Name+123, keyboard walks, birthdays</li>
</ul>

<h2>3) Enable two-factor authentication (2FA) everywhere</h2>
<p>2FA adds a second barrier. Even if a password leaks, your account can remain protected.</p>
<ul>
  <li>✅ Prefer an authenticator app (TOTP)</li>
  <li>✅ Store your <strong>recovery codes</strong> inside your vault</li>
  <li>⚠️ SMS is better than nothing, but less robust</li>
</ul>

<h2>4) Audit your existing passwords</h2>
<p>A good password manager should help you identify:</p>
<ul>
  <li><strong>reused</strong> passwords</li>
  <li><strong>weak</strong> passwords</li>
  <li><strong>old</strong> passwords</li>
</ul>
<p>Set a simple goal: fix 10 passwords per day. In one week, your security posture improves dramatically.</p>

<h2>5) Defend against phishing (the real threat)</h2>
<p>Many attacks don’t crack passwords—they <strong>steal</strong> them. Reduce risk by:</p>
<ul>
  <li>Checking the URL before logging in</li>
  <li>Ignoring “urgent action required” links in emails</li>
  <li>Using autofill (it often refuses fake domains)</li>
</ul>

<h2>6) Share access without sending the password</h2>
<p>For teams or families, avoid chat/email/notes. Use <strong>secure sharing</strong> with permissions, revocation, and activity history—especially for work credentials.</p>

<h2>Quick checklist (copy/paste)</h2>
<ul>
  <li>✅ Password manager enabled</li>
  <li>✅ Unique password per service</li>
  <li>✅ 2FA on critical accounts</li>
  <li>✅ Audit: reused/weak/old</li>
  <li>✅ Phishing awareness + URL checks</li>
</ul>

<h2>Go further with MyKeyNest</h2>
<p>If you want a <strong>secure password manager</strong> with sync and sharing, you can get started in minutes:</p>
<p><a href="/register">Create your MyKeyNest vault</a> · <a href="https://key-nest.com/#pricing">See pricing</a></p>

<!-- <p><em>SEO/Product tip:</em> add an internal link to your pillar page: <a href="/en/password-manager">Password manager</a> (once you create it).</p> -->
HTML;
    }
}
