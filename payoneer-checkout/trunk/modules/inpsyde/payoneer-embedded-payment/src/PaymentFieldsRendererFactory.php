<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment;

use Syde\Vendor\Inpsyde\PaymentGateway\PaymentFieldsRendererInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\ListDebugFieldRenderer;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\EmbeddedPayment\PaymentFieldsRenderer\WidgetPlaceholderFieldRenderer;
use Syde\Vendor\Psr\Container\ContainerInterface;
class PaymentFieldsRendererFactory
{
    /**
     * @param string $component
     *
     * @return list<PaymentFieldsRendererInterface>
     */
    public static function forComponent(string $component, ContainerInterface $container): array
    {
        $renderers = [];
        $isEnabled = (bool) $container->get('embedded_payment.is_enabled');
        if (!$isEnabled) {
            return $renderers;
        }
        $isCheckout = (bool) $container->get('wc.is_checkout');
        $isFragmentUpdate = (bool) $container->get('wc.is_fragment_update');
        $isOrderPay = (bool) $container->get('wc.is_checkout_pay_page');
        $shouldRenderList = $isFragmentUpdate || $isOrderPay;
        if (!($isCheckout || $shouldRenderList)) {
            return $renderers;
        }
        $listUrlRenderer = $container->get('embedded_payment.payment_fields_renderer.list_url');
        assert($listUrlRenderer instanceof PaymentFieldsRendererInterface);
        $onErrorFlagRenderer = $container->get('embedded_payment.payment_fields_renderer.on_error_flag');
        assert($onErrorFlagRenderer instanceof PaymentFieldsRendererInterface);
        $hostedFlowOverrideFlag = $container->get('embedded_payment.payment_fields_renderer.hosted_override_flag');
        assert($hostedFlowOverrideFlag instanceof PaymentFieldsRendererInterface);
        $placeholderRenderer = $container->get("embedded_payment.payment_fields_renderer.placeholder.{$component}");
        assert($placeholderRenderer instanceof WidgetPlaceholderFieldRenderer);
        $renderers[] = $listUrlRenderer;
        $renderers[] = $onErrorFlagRenderer;
        $renderers[] = $hostedFlowOverrideFlag;
        $renderers[] = $placeholderRenderer;
        $isDebug = (bool) $container->get('checkout.is_debug');
        if ($isDebug && $shouldRenderList) {
            $debugRenderer = $container->get('embedded_payment.payment_fields_renderer.debug');
            assert($debugRenderer instanceof ListDebugFieldRenderer);
            $renderers[] = $debugRenderer;
        }
        return $renderers;
    }
}
