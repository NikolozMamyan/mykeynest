<?php

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SubscriptionPlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $limitOptions = static fn (string $label): array => [
            'label' => $label,
            'required' => false,
            'attr' => ['min' => 0, 'max' => 100000, 'inputmode' => 'numeric'],
            'constraints' => [
                new Assert\PositiveOrZero(message: 'La limite doit être positive ou égale à zéro.'),
                new Assert\LessThanOrEqual(value: 100000, message: 'La limite ne peut pas dépasser 100 000.'),
            ],
        ];

        $builder
            ->add('credentialLimit', IntegerType::class, $limitOptions('Nombre d’identifiants'))
            ->add('credentialsUnlimited', CheckboxType::class, ['label' => 'Identifiants illimités', 'required' => false])
            ->add('shareLimit', IntegerType::class, $limitOptions('Nombre de partages'))
            ->add('sharesUnlimited', CheckboxType::class, ['label' => 'Partages illimités', 'required' => false])
            ->add('teamLimit', IntegerType::class, $limitOptions('Nombre d’équipes créées'))
            ->add('teamsUnlimited', CheckboxType::class, ['label' => 'Équipes illimitées', 'required' => false])
            ->add('extensionInstallationLimit', IntegerType::class, $limitOptions('Installations extension'))
            ->add('extensionInstallationsUnlimited', CheckboxType::class, ['label' => 'Installations illimitées', 'required' => false])
            ->add('passwordGenerator', CheckboxType::class, ['label' => 'Générateur de mots de passe', 'required' => false])
            ->add('secureNotes', CheckboxType::class, ['label' => 'Notes sécurisées', 'required' => false])
            ->add('securityChecker', CheckboxType::class, ['label' => 'Audit de sécurité', 'required' => false])
            ->add('credentialImport', CheckboxType::class, ['label' => 'Import CSV', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'constraints' => [
                new Assert\Callback(static function (mixed $data, ExecutionContextInterface $context): void {
                    if (!is_array($data)) {
                        return;
                    }

                    $pairs = [
                        ['credentialLimit', 'credentialsUnlimited'],
                        ['shareLimit', 'sharesUnlimited'],
                        ['teamLimit', 'teamsUnlimited'],
                        ['extensionInstallationLimit', 'extensionInstallationsUnlimited'],
                    ];

                    foreach ($pairs as [$limitKey, $unlimitedKey]) {
                        if (($data[$unlimitedKey] ?? false) !== true && ($data[$limitKey] ?? null) === null) {
                            $context->buildViolation('Indiquez une limite ou cochez « Illimité ».')
                                ->atPath('children[' . $limitKey . '].data')
                                ->addViolation();
                        }
                    }
                }),
            ],
        ]);
    }
}
