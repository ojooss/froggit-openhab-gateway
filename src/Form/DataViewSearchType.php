<?php

declare(strict_types=1);

namespace App\Form;

use DateTimeImmutable;
use App\Form\Model\DataViewSearch;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The search form on /data/view: a time range, one of the currently
 * configured sinks (see DataViewController), and a collection name.
 */
class DataViewSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sink', ChoiceType::class, [
                'label' => 'Sink',
                'choices' => array_flip($options['available_sinks']),
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('from', DateTimeType::class, [
                'label' => 'From',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('to', DateTimeType::class, [
                'label' => 'To',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('collection', TextType::class, [
                'label' => 'Collection',
                'empty_data' => 'froggit',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Show',
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->validateRange(...));
    }

    private function validateRange(FormEvent $event): void
    {
        $data = $event->getData();
        if ($data instanceof DataViewSearch && $data->from instanceof DateTimeImmutable && $data->to instanceof DateTimeImmutable && $data->from > $data->to) {
            $event->getForm()->get('to')->addError(new FormError('"From" must not be after "To".'));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DataViewSearch::class,
            'method' => 'GET',
            'csrf_protection' => false,
            'available_sinks' => [],
        ]);
        $resolver->setAllowedTypes('available_sinks', 'array');
    }
}
