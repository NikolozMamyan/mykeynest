<?php

namespace App\Form;

use App\Entity\Credential;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CredentialType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Credential::class,
        ]);
    }
}
