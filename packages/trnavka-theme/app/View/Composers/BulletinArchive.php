<?php

namespace App\View\Composers;

use App\Repositories\BulletinRepository;
use Roots\Acorn\View\Composer;

class BulletinArchive extends Composer
{
    /** @var array */
    protected static $views = [
        'archive-bulletin',
    ];

    public function __construct(private BulletinRepository $bulletins)
    {
    }

    public function override(): array
    {
        return [
            'bulletins' => $this->bulletins->recent(),
        ];
    }
}
