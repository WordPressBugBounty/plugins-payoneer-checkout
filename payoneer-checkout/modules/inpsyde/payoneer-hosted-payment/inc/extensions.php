<?php

declare (strict_types=1);
namespace Syde\Vendor;

use Syde\Vendor\Dhii\Collection\MapInterface;
use Syde\Vendor\Dhii\Services\Factory;
use Syde\Vendor\Inpsyde\PaymentGateway\PaymentProcessorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\Authentication\TokenGeneratorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetectorInterface;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\HostedPayment\PaymentFieldsRenderer\DescriptionFieldRenderer;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\HostedPayment\PaymentProcessor\HostedPaymentProcessor;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\ListSession\ListSession\ListSessionManager;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Api\Gateway\CommandFactory\WcOrderBasedUpdateCommandFactoryInterface;
use Syde\Vendor\Psr\Container\ContainerInterface;
return static function (): array {
    return ['checkout.flow_options' => static function (array $paymentFlowOptions): array {
        $paymentFlowOptions['hosted'] = \__('Hosted', 'payoneer-checkout');
        return $paymentFlowOptions;
    }, 'checkout.flow_options_description' => static function (string $paymentOptionsDescription): string {
        /* translators: Payment flow dropdown entry in the gateway settings */
        $hostedDescription = \__('Hosted: customers are redirected to a payment page hosted by Payoneer.', 'payoneer-checkout');
        $paymentOptionsDescription .= '<br>' . $hostedDescription;
        return $paymentOptionsDescription;
    }, 'inpsyde_payment_gateway.payment_processor' => static function (PaymentProcessorInterface $previous, ContainerInterface $container): PaymentProcessorInterface {
        $isEnabled = (bool) $container->get('hosted_payment.is_enabled');
        if (!$isEnabled) {
            return $previous;
        }
        /**
         * @var callable(ContainerInterface):PaymentProcessorInterface $factory
         */
        $factory = new Factory(['list_session.manager', 'inpsyde_payment_gateway.transaction_id_field_name', 'hosted_payment.misconfiguration_detector', 'hosted_payment.order_based_update_command_factory', 'checkout.security_token_generator', 'checkout.order.security_header_field_name', 'hosted_payment.payment_flow_override_flag.is_set', 'checkout.session_hash_key'], static function (ListSessionManager $listSessionManager, string $transactionIdFieldName, MisconfigurationDetectorInterface $misconfigurationDetector, WcOrderBasedUpdateCommandFactoryInterface $updateCommandFactory, TokenGeneratorInterface $tokenGenerator, string $tokenKey, bool $fallbackToHostedModeFlag, string $sessionHashKey): PaymentProcessorInterface {
            return new HostedPaymentProcessor($listSessionManager, $transactionIdFieldName, $misconfigurationDetector, $listSessionManager, $updateCommandFactory, $tokenGenerator, $tokenKey, $fallbackToHostedModeFlag, $sessionHashKey);
        });
        return $factory($container);
    }, 'checkout.payment_field_renderers' => static function (array $renderers, ContainerInterface $container): array {
        $isEnabled = (bool) $container->get('hosted_payment.is_enabled');
        if (!$isEnabled) {
            return $renderers;
        }
        /** @var MapInterface */
        $options = $container->get('inpsyde_payment_gateway.options');
        if (!$options->has('description')) {
            return $renderers;
        }
        $description = (string) $options->get('description');
        if (empty($description)) {
            return $renderers;
        }
        $renderers[] = new DescriptionFieldRenderer($description);
        return $renderers;
    }, 'inpsyde_payment_gateway.has_fields' => static function (bool $hasFields, ContainerInterface $container): bool {
        $isEnabled = (bool) $container->get('hosted_payment.is_enabled');
        if ($isEnabled) {
            return \false;
        }
        return $hasFields;
    }];
};
