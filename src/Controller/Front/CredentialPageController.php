<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Entity\User;
use App\Form\CredentialType;
use App\Repository\CredentialRepository;
use App\Repository\SharedAccessRepository;
use App\Repository\TeamRepository;
use App\Service\CredentialCsvImporter;
use App\Service\CredentialManager;
use App\Service\CredentialAccessPolicy;
use App\Service\CredentialUrlPolicy;
use App\Service\SecurityCheckerService;
use App\Service\SubscriptionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CredentialPageController extends AbstractController
{
    public function __construct(
        private CredentialManager $credentialManager,
        private SecurityCheckerService $checker,
        private EntityManagerInterface $entityManager,
        private CredentialRepository $credentialRepository,
        private SubscriptionPlanService $subscriptionPlans,
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
        $sharedAccesses = $sharedAccessRepository->findSharedWith($user);
        $teams = $teamRepository->findTeamWithCredentialsByUser($user);
        $credentialLimit = $this->subscriptionPlans->getLimit($user, SubscriptionPlanService::LIMIT_CREDENTIALS);

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

        $teamSharedCredentials = array_values(array_map(
            static function (array $entry): array {
                $entry['teams'] = array_values($entry['teams']);

                return $entry;
            },
            $teamSharedCredentials
        ));

        usort(
            $teamSharedCredentials,
            static function (array $left, array $right): int {
                $dateComparison = ($right['credential']->getCreatedAt()?->getTimestamp() ?? 0)
                    <=> ($left['credential']->getCreatedAt()?->getTimestamp() ?? 0);

                return $dateComparison !== 0
                    ? $dateComparison
                    : ($right['credential']->getId() ?? 0) <=> ($left['credential']->getId() ?? 0);
            }
        );

        return $this->render('credential/index.html.twig', [
            'credentials' => $credentials,
            'sharedAccesses' => $sharedAccesses,
            'teamSharedCredentials' => $teamSharedCredentials,
            'heading' => 'Mes acces',
            'credentialLimit' => $credentialLimit,
            'canCreateCredential' => $credentialLimit === null || count($credentials) < $credentialLimit,
            'canImportCredentials' => $this->subscriptionPlans->hasFeature($user, SubscriptionPlanService::FEATURE_CREDENTIAL_IMPORT),
        ]);
    }

    #[Route('/app/credential/new', name: 'credential_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();
        $limit = $this->subscriptionPlans->getLimit($user, SubscriptionPlanService::LIMIT_CREDENTIALS);
        $count = $this->credentialRepository->count(['user' => $user]);

        if ($limit !== null && $count >= $limit) {
            $this->addFlash('warning', sprintf('Limite atteinte : %d identifiants maximum avec votre plan.', $limit));

            return $this->redirectToRoute('app_credential');
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
    public function show(Credential $credential, CredentialAccessPolicy $accessPolicy): Response
    {
        $this->denyAccessUnlessGranted('CREDENTIAL_VIEW', $credential);
        $user = $this->getAuthenticatedUser();
        $canRevealPassword = $accessPolicy->canRevealPassword($user, $credential);
        $decryptedPassword = $canRevealPassword
            ? $this->credentialManager->decryptPassword($credential)
            : '';

        return $this->render('credential/show.html.twig', [
            'credential' => $credential,
            'decryptedPassword' => $decryptedPassword,
            'canRevealPassword' => $canRevealPassword,
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

    #[Route('/app/credential/{id}/login-url', name: 'credential_login_url', methods: ['POST'])]
    public function updateLoginUrl(Request $request, Credential $credential, CredentialUrlPolicy $urls): JsonResponse
    {
        $this->denyAccessUnlessGranted('CREDENTIAL_EDIT', $credential);
        if (!$this->isCsrfTokenValid('credential_login_url' . $credential->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $value = trim((string) $request->request->get('loginUrl', ''));
        $url = $urls->normalize($value);
        if ($value !== '' && $url === null) {
            return $this->json(['success' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->credentialManager->updateLoginUrl($credential, $url);

        return $this->json(['success' => true, 'loginUrl' => $credential->getLoginUrl()]);
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
    public function importCredentials(
        Request $request,
        CredentialCsvImporter $csvImporter,
        TranslatorInterface $translator,
    ): Response
    {
        $user = $this->getAuthenticatedUser();

        if (!$this->subscriptionPlans->hasFeature($user, SubscriptionPlanService::FEATURE_CREDENTIAL_IMPORT)) {
            $this->addFlash('warning', $translator->trans('credential.import.errors.unavailable'));

            return $this->redirectToRoute('app_credential');
        }

        $credentialLimit = $this->subscriptionPlans->getLimit($user, SubscriptionPlanService::LIMIT_CREDENTIALS);
        $existingCredentialCount = $this->credentialRepository->count(['user' => $user]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('credential_csv_import', $request->request->getString('_token'))) {
                $this->addFlash('error', $translator->trans('credential.import.errors.invalid_csrf'));

                return $this->redirectToRoute('credential_import');
            }

            if ($credentialLimit !== null && $existingCredentialCount >= $credentialLimit) {
                $this->addFlash('warning', $translator->trans('credential.import.errors.limit_reached', [
                    '%limit%' => $credentialLimit,
                ]));

                return $this->redirectToRoute('credential_import');
            }

            $file = $request->files->get('csv_file');
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $this->addFlash('error', $translator->trans('credential.import.errors.no_file'));

                return $this->redirectToRoute('credential_import');
            }

            if (strtolower((string) $file->getClientOriginalExtension()) !== 'csv') {
                $this->addFlash('error', $translator->trans('credential.import.errors.invalid_type'));

                return $this->redirectToRoute('credential_import');
            }

            if (($file->getSize() ?? 0) > CredentialCsvImporter::MAX_FILE_SIZE) {
                $this->addFlash('error', $translator->trans('credential.import.errors.too_large', ['%size%' => 5]));

                return $this->redirectToRoute('credential_import');
            }

            $result = $csvImporter->import(
                $file->getPathname(),
                $user,
                $credentialLimit,
                $existingCredentialCount,
            );

            if ($result['fatal'] !== null) {
                $this->addFlash('error', $translator->trans(
                    $result['fatal']['key'],
                    $result['fatal']['parameters'],
                ));

                return $this->redirectToRoute('credential_import');
            }

            if ($result['imported'] > 0) {
                $this->checker->buildReportAndNotify($user, SecurityCheckerService::ROTATION_DAYS_DEFAULT);
            }

            foreach ($result['items'] as &$item) {
                $item['message'] = $translator->trans($item['key'], $item['parameters']);
            }
            unset($item);

            return $this->render('credential/import_results.html.twig', [
                'result' => $result,
            ]);
        }

        return $this->render('credential/import.html.twig', [
            'credentialLimit' => $credentialLimit,
            'existingCredentialCount' => $existingCredentialCount,
            'remainingCredentialCount' => $credentialLimit === null
                ? null
                : max(0, $credentialLimit - $existingCredentialCount),
            'maxFileSize' => CredentialCsvImporter::MAX_FILE_SIZE,
        ]);
    }

    #[Route('/app/import/pass/example.csv', name: 'credential_import_example', methods: ['GET'])]
    public function downloadImportExample(Request $request): Response
    {
        $this->getAuthenticatedUser();
        $isFrench = str_starts_with(strtolower($request->getLocale()), 'fr');
        $example = $isFrench
            ? "Compte de démonstration,https://example.com/connexion,utilisateur@example.com,Exemple-ChangezMoi-2026!"
            : "Demo account,https://example.com/login,user@example.com,Example-ChangeMe-2026!";
        $content = "\xEF\xBB\xBFname,domain,username,password\r\n{$example}\r\n";
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'mykeynest-import-example.csv',
        );

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
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
