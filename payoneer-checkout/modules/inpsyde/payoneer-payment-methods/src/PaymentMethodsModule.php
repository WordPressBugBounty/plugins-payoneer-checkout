<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\PaymentMethods;

use Syde\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Syde\Vendor\Inpsyde\Modularity\Module\ExtendingModule;
use Syde\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Syde\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Syde\Vendor\Psr\Container\ContainerInterface;
use WC_Order;
class PaymentMethodsModule implements ServiceModule, ExtendingModule, ExecutableModule
{
    use ModuleClassNameIdTrait;
    public function run(ContainerInterface $container): bool
    {
        add_action('woocommerce_init', function () use ($container) {
            /** @var callable():void $excludeNotSupportedCountries */
            $excludeNotSupportedCountries = $container->get('payment_methods.exclude_not_supported_countries');
            $excludeNotSupportedCountries();
            /** @var string[] $payoneerGateways */
            $payoneerGateways = (array) $container->get('payment_gateways');
            $this->allowCancelingOnHoldOrders($payoneerGateways);
        });
        $this->setPaymentGatewaysEnabledStatus($container);
        return \true;
    }
    /**
     * @inheritDoc
     */
    public function services(): array
    {
        static $services;
        if ($services === null) {
            $services = require_once dirname(__DIR__) . '/inc/services.php';
        }
        /** @var callable(): array<string, callable(ContainerInterface $container):mixed> $services */
        return $services();
    }
    /**
     * @inheritDoc
     */
    public function extensions(): array
    {
        static $extensions;
        if ($extensions === null) {
            $extensions = require_once dirname(__DIR__) . '/inc/extensions.php';
        }
        /** @var callable(): array<string, callable(mixed $service, \Psr\Container\ContainerInterface $container):mixed> $extensions */
        return $extensions();
    }
    /**
     *  By default, only 'pending' and 'failed' order statuses can be cancelled.
     *  When returning from an aborted payment (with redirect->challenge->redirect)
     *  we do want to be able to cancel our 'on-hold' order though
     *
     * @param string[] $payoneerPaymentGateways
     */
    protected function allowCancelingOnHoldOrders(array $payoneerPaymentGateways): void
    {
        add_filter('woocommerce_valid_order_statuses_for_cancel', static function (array $validStatuses, WC_Order $order) use ($payoneerPaymentGateways): array {
            if (!in_array($order->get_payment_method(), $payoneerPaymentGateways, \true)) {
                return $validStatuses;
            }
            $validStatuses[] = 'on-hold';
            return $validStatuses;
        }, 10, 2);
    }
    protected function setPaymentGatewaysEnabledStatus(ContainerInterface $container): void
    {
        $gatewaysIds = $container->get('payment_gateways');
        assert(is_array($gatewaysIds));
        foreach ($gatewaysIds as $gatewayId) {
            assert(is_string($gatewayId));
            add_action($gatewayId . '_after_init_settings', static function (\WC_Payment_Gateway $gateway) use ($container): void {
                $serviceName = sprintf('payment_methods.%1$s.is_enabled', $gateway->id);
                $gateway->enabled = $container->has($serviceName) && $container->get($serviceName) ? 'yes' : 'no';
            });
        }
    }
}
