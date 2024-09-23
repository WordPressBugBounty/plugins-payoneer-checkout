<?php

declare(strict_types=1);

use Dhii\Services\Factory;

return new Factory([
], static function (): array {
    return [
        'checkout_css_fieldset_title' => [
            'title' => __('Payment widget appearance', 'payoneer-checkout'),
            'type' => 'title',
        ],
    ];
});
