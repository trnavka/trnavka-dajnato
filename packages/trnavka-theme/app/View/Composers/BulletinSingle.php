<?php

namespace App\View\Composers;

use App\Repositories\BulletinRepository;
use Roots\Acorn\View\Composer;

class BulletinSingle extends Composer
{
    /** @var array */
    protected static $views = [
        'partials.content-single-bulletin',
    ];

    public function __construct(private BulletinRepository $bulletins)
    {
    }

    public function override(): array
    {
        return [
            'bulletin' => $this->bulletins->find(get_the_ID()),
        ];
    }
}
