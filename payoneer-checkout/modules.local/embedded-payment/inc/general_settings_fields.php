<?php

declare(strict_types=1);

return [
    'checkout_css_fieldset_title' => [
        'title' => __('Payment widget appearance', 'payoneer-checkout'),
        'type' => 'title',
    ],
    'iframe_scale_factor' => [
        'title' => __('Iframe scale factor', 'payoneer-checkout'),
        'type' => 'number',
        'default' => 2.3,
        'description' => __(
            'Payment widget height multiplier',
            'payoneer-checkout'
        ),
        'desc_tip' => __(
            'Higher values result in a taller, lower values in a shorter payment widget. Use this to counteract height differences introduced by the theme',
            'payoneer-checkout'
        ),
        'custom_attributes' => [
            'step' => 0.1,
            'min' => 1.0,
            'max' => 3.0,
        ],
    ],
];
