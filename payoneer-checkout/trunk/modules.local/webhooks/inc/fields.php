<?php

declare(strict_types=1);

use Dhii\Services\Factory;
use Psr\Http\Message\UriInterface;

return new Factory(
    ['webhooks.notification_url'],
    static function (UriInterface $notificationUrl): array {
        return [
            'webhook_endpoints_fieldset_title' => [
                'title' => __('Endpoints', 'payoneer-checkout'),
                'type' => 'title',
            ],

            'webhook_endpoints_endpoint_url' => [
                'title' => __('General endpoint', 'payoneer-checkout'),
                'type' => 'text',
                'default' => (string) $notificationUrl,
                'description' => __(
                    'Please make sure the endpoint URL is not blocked by a firewall',
                    'payoneer-checkout'
                ),
                'custom_attributes' => ['readonly' => 'readonly'],
            ],
        ];
    }
);
