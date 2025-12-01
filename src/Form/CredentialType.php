<?php

namespace App\Form;

use App\Entity\Credential;
use App\Entity\Team;
use App\Repository\TeamRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CredentialType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var \App\Entity\User|null $user */
        $user = $options['user'] ?? null;

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du credential',
                'attr' => ['placeholder' => 'Ex : Gmail, Facebook, etc.'],
            ])
            ->add('domain', TextType::class, [
                'label' => 'Domaine',
                'attr' => ['placeholder' => 'Ex : facebook.com'],
            ])
            ->add('username', TextType::class, [
                'label' => "Nom d'utilisateur",
                'attr' => ['placeholder' => 'Votre identifiant'],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'attr' => ['placeholder' => '********'],
            ]);

        if ($user) {
            $builder->add('teams', EntityType::class, [
                'class'        => Team::class,
                'choice_label' => 'name',
                'label'        => 'Équipes (optionnel)',
                'multiple'     => true,
                'expanded'     => true, // ou false si tu préfères un multiselect
                'required'     => false,
                'by_reference' => false, // 🔥 pour déclencher addTeam()
                'query_builder' => function (TeamRepository $repo) use ($user) {
                    return $repo->createQueryBuilder('t')
                        ->join('t.members', 'm')
                        ->andWhere('m.user = :user')
                        ->setParameter('user', $user)
                        ->orderBy('t.name', 'ASC');
                },
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Credential::class,
            'user'       => null,
        ]);
    }
}
