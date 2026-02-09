<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Form\CredentialType;
use App\Service\CredentialManager;
use App\Service\SecurityCheckerService;
use App\Repository\CredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\SharedAccessRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class CredentialPageController extends AbstractController
{
    public function __construct(
        private CredentialManager $credentialManager,
        private SecurityCheckerService $checker,
        private EntityManagerInterface $entityManager,
        private CredentialRepository $credentialRepository
    ) {}

    #[Route('/app/credential', name: 'app_credential')]
    public function index(
        SharedAccessRepository $sharedAccessRepository,
        CredentialRepository $credentialRepository
    ): Response {
        $user = $this->getUser();

        return $this->render('credential/index.html.twig', [
            'credentials' => $credentialRepository->findByUser($user),
            'sharedAccesses' => $sharedAccessRepository->findSharedWith($user),
            'heading' => 'Mes accès',
        ]);
    }

#[Route('/app/credential/new', name: 'credential_new', methods: ['GET', 'POST'])]
public function new(Request $request): Response
{
    $user = $this->getUser();
    if (!$user) {
        throw $this->createAccessDeniedException();
    }

    // 1) Vérifier la limite si pas d'abonnement
    // Adapte ces 2 lignes à ton projet (repo / relation / méthode)
    $hasSubscription = (bool) $user->isSubscribed(); // ou $user->isSubscribed(), etc.

    if (!$hasSubscription) {
        // Méthode A: via repository
        $count = $this->credentialRepository->count(['user' => $user]);

        // Méthode B: si relation Doctrine (ex: $user->getCredentials())
        // $count = $user->getCredentials()->count();

        if ($count >= 5) {
            $this->addFlash('warning', 'Limite atteinte : 5 identifiants maximum sans abonnement.');

            return $this->redirectToRoute('app_credential');
        }
    }

    // 2) Form / création
    $credential = new Credential();
    $form = $this->createForm(CredentialType::class, $credential, [
        'user' => $user,
        'is_edit' => false,
    ]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $this->credentialManager->create($credential, $user);
        $this->addFlash('success', 'Nouvel identifiant ajouté avec succès.');
        $this->checker->buildReportAndNotify($user, SecurityCheckerService::ROTATION_DAYS_DEFAULT);

        return $this->redirectToRoute('app_credential');
    }

    return $this->render('credential/new.html.twig', [
        'form' => $form,
        'heading' => 'Mes accès',
    ]);
}

    #[Route('/app/credential/{id}', name: 'credential_show', methods: ['GET'])]
    public function show(Credential $credential): Response
    {
        $decryptedPassword = $this->credentialManager->decryptPassword($credential);

        return $this->render('credential/show.html.twig', [
            'credential' => $credential,
            'decryptedPassword' => $decryptedPassword,
            'heading' => 'Mes accès',
        ]);
    }

#[Route('/app/credential/{id}/edit', name: 'credential_edit', methods: ['GET', 'POST'])]
public function edit(Request $request, Credential $credential): Response
{
    // Sauvegarder le mot de passe chiffré original
    $originalEncryptedPassword = $credential->getPassword();
    
    // Déchiffrer le mot de passe pour la comparaison
    $decryptedPassword = $this->credentialManager->decryptPassword($credential);
    
    $form = $this->createForm(CredentialType::class, $credential, [
        'user' => $this->getUser(),
        'is_edit' => true,
    ]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        // Récupérer le nouveau mot de passe du formulaire (champ non mappé)
        $newPassword = $form->get('password')->getData();
        
        // Si un nouveau mot de passe est fourni, on l'utilise
        // Sinon on utilise le mot de passe déchiffré actuel
        $passwordToUse = !empty($newPassword) ? $newPassword : $decryptedPassword;
        
        // Mettre temporairement le mot de passe dans l'entité pour que update() puisse le comparer
        $credential->setPassword($passwordToUse);
        
        // Utiliser la méthode update du CredentialManager
        $this->credentialManager->update($credential, $decryptedPassword, $originalEncryptedPassword);
        
        $this->addFlash('success', 'Identifiant mis à jour avec succès.');

        return $this->redirectToRoute('app_credential');
    }

    return $this->render('credential/edit.html.twig', [
        'form' => $form,
        'heading' => 'Mes accès',
        'credential' => $credential
    ]);
}

    #[Route('/app/credential/{id}', name: 'credential_delete', methods: ['POST'])]
    public function delete(Request $request, Credential $credential): Response
    {
        if ($this->isCsrfTokenValid('delete'.$credential->getId(), $request->request->get('_token'))) {
            $this->credentialManager->delete($credential);
            $this->addFlash('success', 'Identifiant supprimé avec succès.');
        }

        return $this->redirectToRoute('app_credential');
    }
    #[Route('/app/import/pass', name: 'credential_import', methods: ['GET', 'POST'])]
public function importCredentials(Request $request): Response
{
    $results = [];

    $user = $this->getUser();
    if (!$user) {
        throw $this->createAccessDeniedException();
    }

    // ✅ Tu veux interdire l’import si pas abonné
    if (!$user->isSubscribed()) {
        $this->addFlash('warning', 'Fonction réservée aux abonnés.');
        return $this->redirectToRoute('app_credential');
    }

    if ($request->isMethod('POST')) {

        $file = $request->files->get('csv_file');

        if (!$file) {
            $this->addFlash('error', 'Aucun fichier envoyé.');
            return $this->redirectToRoute('credential_import');
        }

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'csv') {
            $this->addFlash('error', 'Le fichier doit être un CSV.');
            return $this->redirectToRoute('credential_import');
        }

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            $this->addFlash('error', 'Impossible d’ouvrir le fichier.');
            return $this->redirectToRoute('credential_import');
        }

        // --- AUTO-DÉTECTION DU SÉPARATEUR ---
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            $this->addFlash('error', 'CSV vide.');
            return $this->redirectToRoute('credential_import');
        }
        rewind($handle);

        $separator = str_contains($firstLine, ';') ? ';' : ',';

        // --- LECTURE DYNAMIQUE DE L'ENTÊTE ---
        $header = fgetcsv($handle, 0, $separator);
        if ($header === false) {
            fclose($handle);
            $this->addFlash('error', 'Impossible de lire l’entête du CSV.');
            return $this->redirectToRoute('credential_import');
        }

        // Nettoyage BOM éventuel sur la première colonne
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);
        $map = array_flip($header);

        // Colonnes obligatoires pour Credential
        $required = ['name', 'domain', 'username', 'password'];
        foreach ($required as $col) {
            if (!isset($map[$col])) {
                fclose($handle);
                $this->addFlash('error', "Colonne manquante dans le CSV : $col");
                return $this->redirectToRoute('credential_import');
            }
        }

        $imported = 0;
        $lineNumber = 1; // entête = ligne 1

        // --- LECTURE LIGNE PAR LIGNE ---
        while (($data = fgetcsv($handle, 0, $separator)) !== false) {
            $lineNumber++;

            // skip lignes vides
            if (count($data) === 1 && trim((string) $data[0]) === '') {
                continue;
            }

            $name     = trim((string) ($data[$map['name']] ?? ''));
            $domain   = trim((string) ($data[$map['domain']] ?? ''));
            $username = trim((string) ($data[$map['username']] ?? ''));
            $password = (string) ($data[$map['password']] ?? '');

            if ($name === '' || $domain === '' || $username === '' || $password === '') {
                $results[] = "❌ Ligne $lineNumber : champs manquants → ignorée";
                continue;
            }

            // (Optionnel) anti-doublon : domain + username + user
            $exists = $this->credentialRepository->findOneBy([
                'user' => $user,
                'domain' => $domain,
                'username' => $username,
            ]);

            if ($exists) {
                $results[] = "⚠️ Ligne $lineNumber : déjà existant ($domain / $username) → ignoré";
                continue;
            }

            try {
                $credential = new Credential;
                $credential
                    ->setName($name)
                    ->setDomain($domain)
                    ->setUsername($username)
                    ->setPassword($password);

                // ✅ Important: passe par ton manager (chiffrement + assign user)
                $this->credentialManager->create($credential, $user);

                $imported++;
                $results[] = "✅ Ligne $lineNumber : créé ($domain / $username)";
            } catch (\Throwable $e) {
                $results[] = "❌ Ligne $lineNumber : erreur ({$e->getMessage()})";
            }
        }

        fclose($handle);

        if ($imported > 0) {
            // rebuild report une seule fois à la fin
            $this->checker->buildReportAndNotify($user, SecurityCheckerService::ROTATION_DAYS_DEFAULT);
        }

        return $this->render('credential/import_results.html.twig', [
            'results' => $results,
            'imported' => $imported,
        ]);
    }

    return $this->render('credential/import.html.twig', [
        'heading' => 'Importer des accès',
    ]);
}

}
