<?php

namespace App\View\Composers;

use App\Form\Type\DarujmeDonationType;
use App\Form\Type\DonationType;
use App\Repositories\CampaignRepository;
use App\Services\Dajnato;
use App\Services\WordPress;
use Roots\Acorn\View\Composer;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

class DajnatoForm extends Composer
{
    /**
     * @var array
     */
    protected static $views = [
        'dajnato-form',
        'partials.content-single-campaign',
        'partials.content-single-bulletin',
    ];

    public function __construct(
        private FormFactory $formFactory,
        private Environment $twig,
        private Dajnato     $dajnato,
        private Request     $request
    )
    {
    }

    public function with(): array
    {
        $campaign = $this->dajnato->campaign();
        $defaultFormData = $this->dajnato->defaultFormData($campaign);

        $formName = $this->request->query->get('modal') ? 'donation_modal' : 'donation';

        $form = $this->formFactory
            ->createNamedBuilder($formName, DonationType::class, $defaultFormData, [
                'campaign' => $campaign,
                'action' => $this->dajnato->formUrl($campaign->id, 'T' === $this->request->query->get('modal')),
            ])
            ->getForm();

        if ($this->request->isMethod('POST')) {
            $form->submit($this->request->request->all($formName));

            if ($form->isValid()) {
                $data = $form->getData();

                $form = $this->formFactory
                    ->createNamedBuilder('donation', DarujmeDonationType::class, $this->dajnato->darujmeData($campaign, $data), [
                        'action' => 'https://api.darujme.sk/v1/donations/post/'
                    ])
                    ->getForm();

                // returns hidden form that autosubmits itself to POST data to darujme.sk system
                return [
                    'form' => preg_replace('/donation\\[([a-zA-Z_]*)]/ms', '$1', $this->twig->render('darujmeForm.html.twig', array(
                        'form' => $form->createView(),
                    ))),
                ];
            }
        }

        return [
            'form' => $this->twig->render('campaign.html.twig', array(
                'donation_form' => $form->createView(),
                'isEmbedded' => 'T' === $this->request->query->get('modal'),
                'formTitle' => [
                    0 => [0 => 'Chyba', 1 => 'Chcem prispievať každý mesiac'],
                    1 => [0 => 'Chcem darovať jednorazovo', 1 => 'Chcem prispieť']
                ][isset($defaultFormData['onetimeAmount']) ? 1 : 0][isset($defaultFormData['recurringAmount']) ? 1 : 0],
            )),
        ];
    }
}
