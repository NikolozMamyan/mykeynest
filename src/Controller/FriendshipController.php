<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Friendship;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


final class FriendshipController extends AbstractController
{
    #[Route('app/friends', name: 'app_friends', methods: ['GET'])]
public function showFriendsPage(): Response
{
    return $this->render('friendship/index.html.twig');
}
    #[Route('/api/friends', name: 'api_friends_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function friends(EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
    
        // Toutes les relations acceptées
        $repo = $em->getRepository(Friendship::class);
        $friendships = $repo->createQueryBuilder('f')
            ->where('f.requester = :user OR f.receiver = :user')
            ->andWhere('f.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'accepted')
            ->getQuery()
            ->getResult();
    
        $friends = [];
        foreach ($friendships as $friendship) {
            $friend = $friendship->getRequester() === $user
                ? $friendship->getReceiver()
                : $friendship->getRequester();
            $friends[] = [
                'id' => $friend->getId(),
                'email' => $friend->getEmail(),
            ];
        }
        $response = new JsonResponse($friends);
        return $response;
    }

    #[Route('/api/friend-request/send/{userId}', name: 'send_friend_request', methods: ['POST'])]
public function sendRequest(int $userId, EntityManagerInterface $em): JsonResponse
{
    $user = $this->getUser();
    $receiver = $em->getRepository(User::class)->find($userId);

    if (!$receiver) {
        return $this->json(['error' => 'User not found'], 404);
    }

    if ($user === $receiver) {
        return $this->json(['error' => 'You cannot add yourself'], 400);
    }

    $existing = $em->getRepository(Friendship::class)->findOneBy([
        'requester' => $user,
        'receiver' => $receiver,
    ]);

    if ($existing) {
        return $this->json(['error' => 'Request already sent'], 400);
    }

    $friendship = (new Friendship())
        ->setRequester($user)
        ->setReceiver($receiver)
        ->setStatus('pending');

    $em->persist($friendship);
    $em->flush();


    return $this->json(['message' => 'Friend request sent']);
}

#[Route('/api/friend-request/accept/{friendshipId}', name: 'accept_friend_request', methods: ['POST'])]
public function acceptRequest(int $friendshipId, EntityManagerInterface $em): JsonResponse
{
    /** @var User $user */
    $user = $this->getUser();
    $friendship = $em->getRepository(Friendship::class)->find($friendshipId);

    if (!$friendship || $friendship->getReceiver() !== $user) {
        return $this->json(['error' => 'Friend request not found'], 404);
    }

    $friendship->setStatus('accepted');
    $em->flush();

    return $this->json(['message' => 'Friend request accepted']);
}
#[Route('/api/friend-request/reject/{friendshipId}', name: 'reject_friend_request', methods: ['POST'])]
public function rejectRequest(int $friendshipId, EntityManagerInterface $em): JsonResponse
{
    /** @var User $user */
    $user = $this->getUser();
    $friendship = $em->getRepository(Friendship::class)->find($friendshipId);

    if (!$friendship || $friendship->getReceiver() !== $user) {
        return $this->json(['error' => 'Friend request not found'], 404);
    }

    $em->remove($friendship);
    $em->flush();

    return $this->json(['message' => 'Friend request rejected']);
}


#[Route('/api/friend-request/pending', name: 'api_pending_requests', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
public function pendingRequests(EntityManagerInterface $em): JsonResponse
{
    /** @var User $user */
    $user = $this->getUser();

    $repo = $em->getRepository(Friendship::class);
    $requests = $repo->findBy([
        'receiver' => $user,
        'status' => 'pending'
    ]);

    $data = array_map(function (Friendship $friendship) {
        return [
            'id' => $friendship->getId(),
            'email' => $friendship->getRequester()->getEmail()
        ];
    }, $requests);

    return $this->json($data);
}

    
}
