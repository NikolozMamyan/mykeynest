<?php

namespace App\Form;

use App\Entity\Note;
use App\Enum\NoteStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class NoteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'attr' => ['placeholder' => 'Titre (ex: Préparer le sprint)'],
            ])
            ->add('content', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => 'Détails / checklist / lien…'],
            ])
            ->add('status', EnumType::class, [
                'class' => NoteStatus::class,
                'choice_label' => fn (NoteStatus $s) => $s->label(),
            ])
            ->add('dueAt', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ]);
    }
}
