<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DarujmeDonationType extends AbstractType
{
    const EXPENSES_FIELD_ID = 'da1b2e52-d790-4f45-99ca-ae8839938174';
    const TRNAVKA_INFO_FIELD_ID = '6aba1bb8-df31-425a-9553-3db177e1b89c';

    public function buildForm(
        FormBuilderInterface $builder,
        array                $options
    ): void
    {
        $builder
            ->add('campaign_id', HiddenType::class)
            ->add('payment_method_id', HiddenType::class)
            ->add('value', TextType::class, ['label' => 'amount'])
            ->add('first_name', TextType::class, ['label' => 'first_name'])
            ->add('last_name', TextType::class, ['label' => 'last_name'])
            ->add('email', TextType::class, ['label' => 'email'])
            ->add(
                $builder->create('additional_data', FormType::class, )
                ->add(self::EXPENSES_FIELD_ID, TextType::class, [
                    'label' => 'dar naviac'
                ])
                ->add(self::TRNAVKA_INFO_FIELD_ID, TextType::class, [
                    'label' => 'suhlas s informovanim'
                ])
            );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'csrf_protection' => false
        ]);
    }
}
