<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\Campaign;
use App\Services\Dajnato;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotBlank;

class DonationType extends AbstractType
{
    public function __construct(private Dajnato $dajnato)
    {
    }

    public function buildForm(
        FormBuilderInterface $builder,
        array                $options
    ): void
    {
        /** @var Campaign $campaign */
        $campaign = $options['campaign'];
        $oneTimePaymentsEnabled = $this->dajnato->oneTimePaymentsEnabled($campaign);
        $recurringPaymentsEnabled = $this->dajnato->recurringPaymentsEnabled($campaign);

        if ($oneTimePaymentsEnabled) {
            $builder
                ->add('onetimeAmount', ChoiceType::class, [
                    'label' => false,
                    'expanded' => true,
                    'choices' => $this->amountChoices($campaign->options),
                    'attr' => [
                        'class' => 'js-onetimeAmount'
                    ],
                    'choice_attr' => function (
                        $choice,
                        $key
                    ) {
                        return 'Iná suma' === $key ? ['data-is-other' => 'T'] : [];
                    },
                ])
                ->add('onetimePaymentType', ChoiceType::class, [
                    'label' => false,
                    'expanded' => true,
                    'choices' => [
                        'Platba kartou' => '1342d2af-a343-4e73-9f5a-7593b9978697',
                        'Platba prevodom na účet' => '3dcf55d1-6383-45b4-b098-dc588187b854',
                        'Apple Pay alebo Google Pay' => '1342d2af-a343-4e73-9f5a-7593b9978697',
                        'Platba cez internet banking (VÚB)' => 'f2e7956e-a3f6-4bff-9e18-2ab3096a5bed',
                        'Platba cez internet banking (Slovenská Sporiteľňa)' => 'c07e714c-74ed-4ac6-ab63-3898a73f1fa0',
                        'Platba cez internet banking (Tatra banka)' => '38409407-c4ec-4060-b4a1-4792f29335ad',
                    ],
                    'constraints' => [
                        new NotBlank(
                            message: 'Vyberte spôsob platby',
                            groups: ['onetime'],
                        )
                    ]
                ]);
        }

        if ($recurringPaymentsEnabled) {
            $builder
                ->add('recurringAmount', ChoiceType::class, [
                    'label' => false,
                    'expanded' => true,
                    'choices' => $this->amountChoices($campaign->recurringOptions),
                    'attr' => [
                        'class' => 'js-recurringAmount'
                    ],
                    'choice_attr' => function (
                        $choice,
                        $key
                    ) {
                        return 'Iná suma' === $key ? ['data-is-other' => 'T'] : [];
                    },
                    'label_html' => true
                ])
                ->add('recurringPaymentType', ChoiceType::class, [
                    'label' => false,
                    'expanded' => true,
                    'choices' => [
                        'Platba kartou' => 'b71ff7cf-39f7-40db-8a34-e1f30292c215',
                        'Platba trvalým príkazom' => 'f425f4af-74ce-4a9b-82d6-783c93b80f17',
                    ],
                    'constraints' => [
                        new NotBlank(
                            message: 'Vyberte spôsob platby',
                            groups: ['recurring'],
                        )
                    ]
                ]);
        }

        $builder
            ->add('onetimeOrRecurring', ChoiceType::class, [
                'label' => false,
                'expanded' => true,
                'choices' => [
                    'Každý mesiac' => 'recurring',
                    'Jednorazovo' => 'onetime',
                ],
                'attr' => [
                    'class' => 'js-onetimeOrRecurring'
                ]
            ])
            ->add('otherAmount', NumberType::class, [
                'label' => 'Iná suma',
                'html5' => true,
                'required' => false,
                'attr' => [
                    'placeholder' => 'Iná suma',
                    'class' => 'js-otherAmount',
                ],
                'constraints' => [
                    new NotBlank(
                        message: 'Vyberte z predvolených súm alebo zadajte vlastnú sumu',
                        groups: ['other_amount'],
                    )
                ]
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Meno',
                'attr' => [
                    'placeholder' => 'Meno'
                ],
                'constraints' => [
                    new NotBlank(message: 'Zadajte meno')
                ]
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Priezvisko',
                'attr' => [
                    'placeholder' => 'Priezvisko'
                ],
                'constraints' => [
                    new NotBlank(message: 'Zadajte priezvisko')
                ]
            ])
            ->add('email', EmailType::class, [
                'label' => 'Emailová adresa',
                'attr' => [
                    'placeholder' => 'Emailová adresa'
                ],
                'constraints' => [
                    new NotBlank(message: 'Zadajte emailovú adresu'),
                    new Email(message: 'Zadajte platnú emailovú adresu')
                ]
            ])
            ->add('terms', CheckboxType::class, [
                'label' => 'Potvrdzujem, že mám informácie o <a href="http://saleziani.sk/msaleziani/gdpr" target="_blank">spracovaní osobných údajov</a> organizáciou Saleziáni don Bosca, ktorej poskytujem dar',
                'label_html' => true,
                'constraints' => [
                    new IsTrue()
                ]
            ])
            ->add('gdpr', CheckboxType::class, [
                'label' => 'Potvrdzujem, že mám informácie o spracovaní osobných údajov v systéme <a href="https://darujme.sk/pravidla-ochrany-osobnych-udajov/" target="_blank">DARUJME.sk</a>',
                'label_html' => true,
                'constraints' => [
                    new IsTrue()
                ]
            ])
            ->add('info', CheckboxType::class, [
                'label' => 'Súhlasím so spracúvaním osobných údajov <a href="/suhlas-so-spracuvanim-osobnych-udajov/" target="_blank">na účely informovania o aktivitách</a> Saleziánskeho diela na Trnávke. Emaily posielame len občas a hocikedy sa môžeš odhlásiť.',
                'label_html' => true,
            ])
            ->add('expenses', CheckboxType::class, [
                'label' => 'Pošlem navyše 3,9&nbsp;%<div class="dajnato-expenses-info small">Z každého daru platíme poplatky vo výške 3,9&nbsp;% za platobný systém. Prosím, pomôž nám ich zaplatiť.</div>',
                'label_html' => true,
                'attr' => [
                    'class' => 'js-expenses'
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
//            ->setAllowedTypes('campaign', Campaign::class)
            ->setDefaults([
                'csrf_protection' => false,
                'values' => [],
                'campaign' => $this->dajnato->campaign(),
                'validation_groups' => function (
                    FormInterface $form
                ) {
                    $data = $form->getData();

                    $validationGroup = 'recurring' === $data['onetimeOrRecurring'] ? 'recurring' : 'onetime';

                    if ('' === $form->getData()['recurring' === $data['onetimeOrRecurring'] ? 'recurringAmount' : 'onetimeAmount']) {
                        return ['Default', 'other_amount', $validationGroup];
                    }

                    return ['Default', $validationGroup];
                },
                'donation_type' => 'campaign'
            ]);
    }

    private function amountChoices($options): array
    {
        return collect($options)->reject('empty')->mapWithKeys(function (
                $option
            ) {
                return [sprintf('%d&nbsp;€', $option) => $option];
            })->toArray() + ['Iná suma' => ''];
    }
}
