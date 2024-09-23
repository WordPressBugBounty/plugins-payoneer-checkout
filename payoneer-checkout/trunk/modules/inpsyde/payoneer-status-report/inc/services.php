<?php

/**
 * The status report module services.
 *
 * @package Inpsyde\PayoneerForWoocommerce\StatusReport
 */
declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\StatusReport;

use Syde\Vendor\Psr\Container\ContainerInterface;
return static function (): array {
    return ['status-report.renderer' => static function (): Renderer {
        return new Renderer();
    }, 'status-report.boolToHtml' => static function (): object {
        return new class
        {
            public function convert(bool $value): string
            {
                return $value ? '<mark class="yes"><span class="dashicons dashicons-yes"></span></mark>' : '<mark class="no">&ndash;</mark>';
            }
        };
    }, 'status-report.fields' => static function (ContainerInterface $container): array {
        return [['label' => esc_html__('Shop country code', 'payoneer-checkout'), 'exported_label' => 'Shop country code', 'description' => esc_html__('Country / State value on Settings / General / Store Address.', 'payoneer-checkout'), 'value' => $container->get('inpsyde_payment_gateway.store_country')], ['label' => esc_html__('API username', 'payoneer-checkout'), 'exported_label' => 'API username', 'value' => $container->get('payoneer_settings.merchant_code')], ['label' => esc_html__('Payment flow', 'payoneer-checkout'), 'exported_label' => 'Payment flow', 'description' => esc_html__('Displays whether a plugin is using a hosted or an embedded payment flow', 'payoneer-checkout'), 'value' => $container->get('checkout.selected_payment_flow')]];
    }];
};
