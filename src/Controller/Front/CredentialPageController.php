<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Entity\User;
use App\Form\CredentialType;
use App\Repository\CredentialRepository;
use App\Repository\SharedAccessRepository;
use App\Repository\TeamRepository;
use App\Service\CredentialManager;
use App\Service\SecurityCheckerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CredentialPageController extends AbstractController
{
    public function __construct(
        private CredentialManager $credentialManager,
        private SecurityCheckerService $checker,
        private EntityManagerInterface $entityManager,
        private CredentialRepository $credentialRepository
    ) {}

    private function getAuthenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function redirectToCredentialIndex(Request $request): Response
    {
        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_credential'));
    }

    private function isAjaxRequest(Request $request): bool
    {
        return $request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json';
    }

    private function jsonPinResponse(Credential $credential): JsonResponse
    {
        return $this->json([
            'success' => true,
            'credentialId' => $credential->getId(),
            'pinned' => $credential->getPinPosition() !== null,
            'pinPosition' => $credential->getPinPosition(),
        ]);
    }

    private function ensureCredentialOwnership(Credential $credential): User
    {
        $user = $this->getAuthenticatedUser();
        if ($credential->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    #[Route('/app/credential', name: 'app_credential')]
    public function index(
        SharedAccessRepository $sharedAccessRepository,
        CredentialRepository $credentialRepository,
        TeamRepository $teamRepository,
    ): Response {
        $user = $this->getAuthenticatedUser();
        $credentials = $credentialRepository->findByUser($user);
        $sharedAccesses = $sharedAccessRepository->findBy(['guest' => $user]);
        $teams = $teamRepository->findTeamWithCredentialsByUser($user);

        $excludedCredentialIds = [];
        foreach ($credentials as $credential) {
            if ($credential->getId() !== null) {
                $excludedCredentialIds[$credential->getId()] = true;
            }
        }

        foreach ($sharedAccesses as $sharedAccess) {
            $sharedCredential = $sharedAccess->getCredential();
            if ($sharedCredential instanceof Credential && $sharedCredential->getId() !== null) {
                $excludedCredentialIds[$sharedCredential->getId()] = true;
            }
        }

        $teamSharedCredentials = [];
        foreach ($teams as $team) {
            foreach ($team->getCredentials() as $credential) {
                $credentialId = $credential->getId();
                if ($credentialId === null || isset($excludedCredentialIds[$credentialId])) {
                    continue;
                }

                $owner = $credential->getUser();
                if (!$owner || $owner->getId() === $user->getId()) {
                    continue;
                }

                if (!isset($teamSharedCredentials[$credentialId])) {
                    $teamSharedCredentials[$credentialId] = [
                        'credential' => $credential,
                        'owner' => $owner,
                        'teams' => [],
                    ];
                }

                $teamSharedCredentials[$credentialId]['teams'][$team->getId()] = $team->getName();
            }
        }

        return $this->render('credential/index.html.twig', [
            'credentials' => $credentials,
            'sharedAccesses' => $sharedAccesses,
            'teamSharedCredentials' => array_values(array_map(
                static function (array $entry): array {
                    $entry['teams'] = array_values($entry['teams']);

                    return $entry;
                },
                $teamSharedCredentials
            )),
            'heading' => 'Mes acces',
        ]);
    }

    #[Route('/app/credential/new', name: 'credential_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();
        $hasSubscription = $user->hasActiveSubscription();

        if (!$hasSubscription) {
            $count = $this->credentialRepository->count(['user' => $user]);

            if ($count >= 5) {
                $this->addFlash('warning', 'Limite atteinte : 5 identifiants maximum sans abonnement.');

                return $this->redirectToRoute('app_credential');
            }
        }

        $credential = new Credential();
        $form = $this->createForm(CredentialType::class, $credential, [
            'user' => $user,
            'is_edit' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->credentialManager->create($credential, $user);
            $this->addFlash('success', 'Nouvel identifiant ajoute avec succes.');
            $this->checker->buildReportAndNotify($user, SecurityCheckerService::ROTATION_DAYS_DEFAULT);

            return $this->redirectToRoute('app_credential');
        }

        return $this->render('credential/new.html.twig', [
            'form' => $form,
            'heading' => 'Mes acces',
        ]);
    }

    #[Route('/app/credential/{id}', name: 'credential_show', methods: ['GET'])]
    public function show(Credential $credential): Response
    {
        $this->denyAccessUnlessGranted('CREDENTIAL_VIEW', $credential);
        $decryptedPassword = $this->credentialManager->decryptPassword($credential);

        return $this->render('credential/show.html.twig', [
            'credential' => $credential,
            'decryptedPassword' => $decryptedPassword,
            'heading' => 'Mes acces',
        ]);
    }

    #[Route('/app/credential/{id}/edit', name: 'credential_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Credential $credential): Response
    {
        $this->denyAccessUnlessGranted('CREDENTIAL_EDIT', $credential);

        $originalEncryptedPassword = $credential->getPassword();
        $decryptedPassword = $this->credentialManager->decryptPassword($credential);

        $form = $this->createForm(CredentialType::class, $credential, [
            'user' => $this->getAuthenticatedUser(),
            'is_edit' => true,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('password')->getData();
            $passwordToUse = !empty($newPassword) ? $newPassword : $decryptedPassword;

            $credential->setPassword($passwordToUse);
            $this->credentialManager->update($credential, $decryptedPassword, $originalEncryptedPassword);

            $this->addFlash('success', 'Identifiant mis a jour avec succes.');

            return $this->redirectToRoute('app_credential');
        }

        return $this->render('credential/edit.html.twig', [
            'form' => $form,
            'heading' => 'Mes acces',
            'credential' => $credential,
        ]);
    }

    #[Route('/app/credential/{id}', name: 'credential_delete', methods: ['POST'])]
    public function delete(Request $request, Credential $credential): Response
    {
        $this->denyAccessUnlessGranted('CREDENTIAL_DELETE', $credential);

        if ($this->isCsrfTokenValid('delete'.$credential->getId(), $request->request->get('_token'))) {
            $user = $this->getAuthenticatedUser();
            $wasPinned = $credential->getPinPosition() !== null && $credential->getUser()?->getId() === $user->getId();

            $this->credentialManager->delete($credential);

            if ($wasPinned) {
                $this->credentialRepository->compactPinPositionsForUser($user);
                $this->entityManager->flush();
            }

            $this->addFlash('success', 'Identifiant supprime avec succes.');
        }

        return $this->redirectToRoute('app_credential');
    }

    #[Route('/app/credential/{id}/pin', name: 'credential_pin', methods: ['POST'])]
    public function pin(Request $request, Credential $credential): Response
    {
        $this->denyAccessUnlessGranted('CREDENTIAL_EDIT', $credential);

        if (!$this->isCsrfTokenValid('pin'.$credential->getId(), $request->request->get('_token'))) {
            if ($this->isAjaxRequest($request)) {
                return $this->json(['success' => false, 'message' => 'CSRF invalide'], 400);
            }

            return $this->redirectToCredentialIndex($request);
        }

        $user = $this->ensureCredentialOwnership($credential);

        if ($credential->getPinPosition() === null) {
            $credential->setPinPosition($this->credentialRepository->findNextPinPositionForUser($user));
            $credential->setUpdatedAtValue();
            $this->entityManager->flush();
            if (!$this->isAjaxRequest($request)) {
                $this->addFlash('success', 'Identifiant epingle.');
            }
        }

        if ($this->isAjaxRequest($request)) {
            return $this->jsonPinResponse($credential);
        }

        return $this->redirectToCredentialIndex($request);
    }

    #[Route('/app/credential/{id}/unpin', name: 'credential_unpin', methods: ['POST'])]
    public function unpin(Request $request, Credential $credential): Response
    {
        $this->denyAccessUnlessGranted('CREDENTIAL_EDIT', $credential);

        if (!$this->isCsrfTokenValid('unpin'.$credential->getId(), $request->request->get('_token'))) {
            if ($this->isAjaxRequest($request)) {
                return $this->json(['success' => false, 'message' => 'CSRF invalide'], 400);
            }

            return $this->redirectToCredentialIndex($request);
        }

        $user = $this->ensureCredentialOwnership($credential);

        if ($credential->getPinPosition() !== null) {
            $credential->setPinPosition(null);
            $credential->setUpdatedAtValue();
            $this->entityManager->flush();
            $this->credentialRepository->compactPinPositionsForUser($user);
            $this->entityManager->flush();
            if (!$this->isAjaxRequest($request)) {
                $this->addFlash('success', 'Identifiant retire des epingles.');
            }
        }

        if ($this->isAjaxRequest($request)) {
            return $this->jsonPinResponse($credential);
        }

        return $this->redirectToCredentialIndex($request);
    }

    #[Route('/app/credential/{id}/pin/toggle', name: 'credential_pin_toggle', methods: ['POST'])]
    public function togglePin(Request $request, Credential $credential): Response
    {
        $this->denyAccessUnlessGranted('CREDENTIAL_EDIT', $credential);

        if (!$this->isCsrfTokenValid('pin_toggle'.$credential->getId(), $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'CSRF invalide'], 400);
        }

        $user = $this->ensureCredentialOwnership($credential);

        if ($credential->getPinPosition() === null) {
            $credential->setPinPosition($this->credentialRepository->findNextPinPositionForUser($user));
            $credential->setUpdatedAtValue();
            $this->entityManager->flush();
        } else {
            $credential->setPinPosition(null);
            $credential->setUpdatedAtValue();
            $this->entityManager->flush();
            $this->credentialRepository->compactPinPositionsForUser($user);
            $credential = $this->credentialRepository->find($credential->getId());
            if (!$credential instanceof Credential) {
                return $this->json(['success' => false, 'message' => 'Credential introuvable'], 404);
            }
        }

        $this->entityManager->flush();

        return $this->jsonPinResponse($credential);
    }

    #[Route('/app/import/pass', name: 'credential_import', methods: ['GET', 'POST'])]
    public function importCredentials(Request $request): Response
    {
        $results = [];
        $user = $this->getAuthenticatedUser();

        if (!$user->hasActiveSubscription()) {
            $this->addFlash('warning', 'Fonction reservee aux abonnes.');

            return $this->redirectToRoute('app_credential');
        }

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csv_file');

            if (!$file) {
                $this->addFlash('error', 'Aucun fichier envoye.');

                return $this->redirectToRoute('credential_import');
            }

            if (strtolower((string) $file->getClientOriginalExtension()) !== 'csv') {
                $this->addFlash('error', 'Le fichier doit etre un CSV.');

                return $this->redirectToRoute('credential_import');
            }

            $handle = fopen($file->getRealPath(), 'r');
            if ($handle === false) {
                $this->addFlash('error', 'Impossible d ouvrir le fichier.');

                return $this->redirectToRoute('credential_import');
            }

            $firstLine = fgets($handle);
            if ($firstLine === false) {
                fclose($handle);
                $this->addFlash('error', 'CSV vide.');

                return $this->redirectToRoute('credential_import');
            }
            rewind($handle);

            $separator = str_contains($firstLine, ';') ? ';' : ',';

            $header = fgetcsv($handle, 0, $separator);
            if ($header === false) {
                fclose($handle);
                $this->addFlash('error', 'Impossible de lire l entete du CSV.');

                return $this->redirectToRoute('credential_import');
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
            $map = array_flip($header);

            $required = ['name', 'domain', 'username', 'password'];
            foreach ($required as $column) {
                if (!isset($map[$column])) {
                    fclose($handle);
                    $this->addFlash('error', "Colonne manquante dans le CSV : $column");

                    return $this->redirectToRoute('credential_import');
                }
            }

            $imported = 0;
            $lineNumber = 1;

            while (($data = fgetcsv($handle, 0, $separator)) !== false) {
                $lineNumber++;

                if (count($data) === 1 && trim((string) $data[0]) === '') {
                    continue;
                }

                $name = trim((string) ($data[$map['name']] ?? ''));
                $domain = trim((string) ($data[$map['domain']] ?? ''));
                $username = trim((string) ($data[$map['username']] ?? ''));
                $password = (string) ($data[$map['password']] ?? '');

                if ($name === '' || $domain === '' || $username === '' || $password === '') {
                    $results[] = "Ligne $lineNumber : champs manquants -> ignoree";
                    continue;
                }

                $exists = $this->credentialRepository->findOneBy([
                    'user' => $user,
                    'domain' => $domain,
                    'username' => $username,
                ]);

                if ($exists) {
                    $results[] = "Ligne $lineNumber : deja existant ($domain / $username) -> ignore";
                    continue;
                }

                try {
                    $credential = new Credential();
                    $credential
                        ->setName($name)
                        ->setDomain($domain)
                        ->setUsername($username)
                        ->setPassword($password);

                    $this->credentialManager->create($credential, $user);

                    $imported++;
                    $results[] = "Ligne $lineNumber : cree ($domain / $username)";
                } catch (\Throwable $exception) {
                    $results[] = "Ligne $lineNumber : erreur ({$exception->getMessage()})";
                }
            }

            fclose($handle);

            if ($imported > 0) {
                $this->checker->buildReportAndNotify($user, SecurityCheckerService::ROTATION_DAYS_DEFAULT);
            }

            return $this->render('credential/import_results.html.twig', [
                'results' => $results,
                'imported' => $imported,
            ]);
        }

        return $this->render('credential/import.html.twig', [
            'heading' => 'Importer des acces',
        ]);
    }

    #[Route('/app/credential/{id}/details/modal', name: 'credential_details_modal', methods: ['GET'])]
    public function detailsModal(Credential $credential): Response
    {
        $this->denyAccessUnlessGranted('CREDENTIAL_EDIT', $credential);

        return $this->render('credential/_details_modal.html.twig', [
            'credential' => $credential,
        ]);
    }

    #[Route('/app/credential/{id}/details', name: 'credential_details_update', methods: ['POST'])]
    public function detailsUpdate(Request $request, Credential $credential, EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $this->denyAccessUnlessGranted('CREDENTIAL_EDIT', $credential);
        } catch (AccessDeniedException) {
            return $this->json(['success' => false, 'message' => 'Acces refuse'], 403);
        }

        $details = trim((string) $request->request->get('details', ''));

        $credential->setDetails($details === '' ? null : $details);
        $credential->setUpdatedAtValue();
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'details' => $credential->getDetails(),
        ]);
    }
}
