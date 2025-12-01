<?php

namespace App\Form;

use App\Entity\Team;
use App\Entity\Credential;
use App\Repository\CredentialRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TeamType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var \App\Entity\User|null $user */
        $user = $options['user'] ?? null;

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de l’équipe',
            ]);

        if ($user) {
            $builder->add('credentials', EntityType::class, [
                'class'        => Credential::class,
                'choice_label' => 'name',
                'label'        => 'Partager des credentials existants (optionnel)',
                'multiple'     => true,
                'expanded'     => true, // cases à cocher
                'required'     => false,
                'by_reference' => false, // 🔥 pour que Symfony appelle addCredential()
                'query_builder' => function (CredentialRepository $repo) use ($user) {
                    // ⚠️ adapte `c.user` si chez toi la propriété s’appelle autrement
                    return $repo->createQueryBuilder('c')
                        ->andWhere('c.user = :user')
                        ->setParameter('user', $user)
                        ->orderBy('c.name', 'ASC');
                },
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Team::class,
            'user'       => null,
        ]);
    }
}
