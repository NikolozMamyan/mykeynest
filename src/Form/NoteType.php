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
use Symfony\Component\Validator\Constraints as Assert;

class NoteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'note.form.title.label',
                'attr' => ['placeholder' => 'note.form.title.placeholder', 'maxlength' => 255],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 255),
                ],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'note.form.content.label',
                'required' => false,
                'attr' => ['rows' => 6, 'placeholder' => 'note.form.content.placeholder', 'maxlength' => 50000],
                'constraints' => [new Assert\Length(max: 50000)],
            ])
            ->add('status', EnumType::class, [
                'label' => 'note.form.status.label',
                'class' => NoteStatus::class,
                'choice_label' => fn (NoteStatus $status) => 'note.index.board.statuses.' . $status->value,
            ])
            ->add('dueAt', DateTimeType::class, [
                'label' => 'note.form.due_at.label',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ]);
    }
}
