<?php

declare(strict_types=1);

//Setting fields used to render widget CSS in non-custom mode
return [
    'checkout_css_background_color' => [
        'title' => __('Background color', 'payoneer-checkout'),
        'type' => 'text',
        'default' => 'initial',
        'description' => __(
            'Payment widget background color',
            'payoneer-checkout'
        ),
        'class' => 'colorpick',
    ],

    'checkout_css_text_color' => [
        'title' => __('Text color', 'payoneer-checkout'),
        'type' => 'text',
        'default' => 'initial',
        'description' => __(
            'Payment widget text color',
            'payoneer-checkout'
        ),
        'class' => 'colorpick',
    ],

    'checkout_css_placeholders_color' => [
        'title' => __('Placeholder color', 'payoneer-checkout'),
        'type' => 'text',
        'default' => 'initial',
        'description' => __(
            'Payment widget placeholder color',
            'payoneer-checkout'
        ),
        'class' => 'colorpick',
    ],

    'checkout_css_font_size' => [
        'title' => __('Font size', 'payoneer-checkout'),
        'type' => 'text',
        'default' => 'initial',
        'description' => __(
            'Payment widget font size',
            'payoneer-checkout'
        ),
    ],

    'checkout_css_font_weight' => [
        'title' => __('Font weight', 'payoneer-checkout'),
        'type' => 'select',
        'description' => __(
            'Payment widget font weight',
            'payoneer-checkout'
        ),
        'options' => [
            'lighter' => __('Lighter', 'payoneer-checkout'),
            'regular' => __('Regular', 'payoneer-checkout'),
            'bold' => __('Bold', 'payoneer-checkout'),
        ],
        'default' => 'initial',
    ],

    'checkout_css_letter_spacing' => [
        'title' => __('Letter spacing', 'payoneer-checkout'),
        'type' => 'number',
        'default' => 'initial',
        'description' => __(
            'Payment widget letter spacing',
            'payoneer-checkout'
        ),
        'custom_attributes' => [
            'step' => 0.01,
            'min' => -99,
            'max' => 99,
        ],
        'css' => 'width:75px;',
        'measurement_unit' => 'em',
    ],

    'checkout_css_line_height' => [
        'title' => __('Line height', 'payoneer-checkout'),
        'type' => 'number',
        'default' => 'initial',
        'description' => __(
            'Payment widget line height',
            'payoneer-checkout'
        ),
        'custom_attributes' => [
            'step' => 1,
            'min' => 0,
            'max' => 999,
        ],
        'measurement_unit' => 'em',
    ],

    'checkout_css_padding' => [
        'title' => __('Padding', 'payoneer-checkout'),
        'type' => 'text',
        'default' => 'initial',
        'description' => __(
            'Payment widget padding',
            'payoneer-checkout'
        ),
    ],

    'checkout_css_align_text' => [
        'title' => __('Text alignment', 'payoneer-checkout'),
        'type' => 'select',
        'description' => __(
            'Payment widget text alignment',
            'payoneer-checkout'
        ),
        'options' => [
            'left' => __('Left', 'payoneer-checkout'),
            'right' => __('Right', 'payoneer-checkout'),
            'center' => __('Center', 'payoneer-checkout'),
            'justify' => __('Justify', 'payoneer-checkout'),
        ],
        'default' => 'initial',
    ],

    'checkout_css_transform_text' => [
        'title' => __('Text transformation', 'payoneer-checkout'),
        'type' => 'select',
        'description' => __('Payment widget text transformation', 'payoneer-checkout'),
        'options' => [
            'none' => __('None', 'payoneer-checkout'),
            'capitalize' => __('Capitalize', 'payoneer-checkout'),
            'uppercase' => __('Uppercase', 'payoneer-checkout'),
            'lowercase' => __('Lowercase', 'payoneer-checkout'),
            'full_width' => __('Full width', 'payoneer-checkout'),
            'full_size_kana' => __('Full size kana', 'payoneer-checkout'),
        ],
        'default' => 'none',
    ],
];
