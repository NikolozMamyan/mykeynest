<?php

namespace App\Form;

use App\Enum\TeamRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TeamAddMemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email de l’utilisateur',
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Rôle dans l’équipe',
                'choices' => [
                    'Membre' => TeamRole::MEMBER,
                    'Admin'  => TeamRole::ADMIN,
                ],
                'expanded' => false,
                'multiple' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // on utilise un simple array (pas d’entity liée directement)
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}
