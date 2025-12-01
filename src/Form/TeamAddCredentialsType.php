<?php

namespace App\Form;

use App\Entity\Credential;
use App\Entity\Team;
use App\Repository\CredentialRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TeamAddCredentialsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var \App\Entity\User|null $user */
        $user = $options['user'] ?? null;
        /** @var Team|null $team */
        $team = $options['team'] ?? null;

        $builder->add('credentials', EntityType::class, [
            'class'         => Credential::class,
            'choice_label'  => 'name',
            'label'         => 'Sélectionner des credentials',
            'multiple'      => true,
            'expanded'      => true,
            'required'      => false,
            'query_builder' => function (CredentialRepository $repo) use ($user, $team) {
                $qb = $repo->createQueryBuilder('c')
                    ->andWhere('c.user = :user')
                    ->setParameter('user', $user)
                    ->orderBy('c.name', 'ASC');

                // Exclure les credentials déjà partagés avec l'équipe
                if ($team) {
                    $existingCredentialIds = [];
                    foreach ($team->getCredentials() as $credential) {
                        $existingCredentialIds[] = $credential->getId();
                    }
                    
                    if (!empty($existingCredentialIds)) {
                        $qb->andWhere('c.id NOT IN (:existing)')
                           ->setParameter('existing', $existingCredentialIds);
                    }
                }

                return $qb;
            },
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'user'            => null,
            'team'            => null,
        ]);
    }
}