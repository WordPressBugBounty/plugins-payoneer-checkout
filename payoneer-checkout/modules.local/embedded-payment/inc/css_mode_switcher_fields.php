<?php

declare(strict_types=1);

//Setting field for switching between custom and non-custom CSS modes.
return [

    'checkout_css_advanced_mode' => [
        'title' => __(
            'Enable / Disable custom CSS',
            'payoneer-checkout'
        ),
        'label' => __(
            'Enable custom payment widget CSS (for advanced users)',
            'payoneer-checkout'
        ),
        'desc_tip' => __(
            'If enabled, values from the Custom CSS field will be used. Otherwise, settings from other fields will be applied to the default CSS.',
            'payoneer-checkout'
        ),
        'type' => 'checkbox',
        'default' => 'no',
        'class' => 'payoneer-checkout-switch',
    ],
];
