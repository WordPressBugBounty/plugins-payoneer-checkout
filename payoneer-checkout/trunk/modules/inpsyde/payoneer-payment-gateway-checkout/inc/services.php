<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout;

use Syde\Vendor\Dhii\Services\Factories\Alias;
use Syde\Vendor\Dhii\Services\Factories\Constructor;
use Syde\Vendor\Dhii\Services\Factories\StringService;
use Syde\Vendor\Dhii\Services\Factories\Value;
use Syde\Vendor\Dhii\Services\Factory;
use Syde\Vendor\Dhii\Services\Service;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\Authentication\TokenGenerator;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\HashProvider\CheckoutHashProvider;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\MisconfigurationDetector\MisconfigurationDetector;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\ProductTaxCodeProvider\ProductTaxCodeProvider;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\StateProvider\StateProvider;
use Syde\Vendor\Inpsyde\PayoneerForWoocommerce\Checkout\TransactionIdGenerator\TransactionIdGenerator;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\System\System;
use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Entities\System\SystemInterface;
use Syde\Vendor\Psr\Container\ContainerInterface;
use WC_Session;
return static function (): array {
    return ['checkout.module_root_path' => static function (): string {
        return dirname(__DIR__);
    }, 'checkout.module_directory_name' => static function (): string {
        return 'checkout';
    }, 'checkout.ajax_library_path' => static function (): string {
        return '';
    }, 'checkout.ajax_library_url' => static function (): string {
        return '';
    }, 'checkout.list_session_manager.cache_key.salt.option_name' => new Factory(['checkout.plugin.version_string'], static function (string $pluginVersion): string {
        $versionHash = md5($pluginVersion);
        $versionShortHash = (string) substr($versionHash, 0, 8);
        return sprintf('payoneer_list_session_cache_key_salt_%1$s', $versionShortHash);
    }), 'checkout.list_session_manager.cache_key.salt' => new Factory(['checkout.list_session_manager.cache_key.salt.option_name'], static function (string $optionName): string {
        $salt = (string) get_option($optionName);
        if (!$salt) {
            $salt = wp_generate_password();
            update_option($optionName, $salt);
        }
        return $salt;
    }), 'checkout.list_session_manager.cache_key.checkout' => new StringService('payoneer_list_checkout_{0}', ['checkout.list_session_manager.cache_key.salt']), 'checkout.list_session_manager.cache_key.payment' => new StringService('payoneer_list_pay_{0}_{1}', ['wc.pay_for_order_id', 'checkout.list_session_manager.cache_key.salt']), 'checkout.list_session_manager.cache_key' => static function (ContainerInterface $container): string {
        $isPayment = (bool) $container->get('wc.is_checkout_pay_page');
        $key = $isPayment ? $container->get('checkout.list_session_manager.cache_key.payment') : $container->get('checkout.list_session_manager.cache_key.checkout');
        return (string) $key;
    }, 'list_session.list_session_system' => new Factory(['checkout.plugin.version_string'], static function (string $version): SystemInterface {
        return new System('SHOP_PLATFORM', 'WOOCOMMERCE', $version);
    }), 'checkout.security_token' => new Factory(['wc.session', 'checkout.order.security_header_field_name'], static function (WC_Session $wcSession, string $tokenKey): string {
        $token = $wcSession->get($tokenKey);
        if (!is_string($token)) {
            throw new \RuntimeException(sprintf("Invalid value for WC_Session key %s. Expected string, got %s", $tokenKey, print_r($token, \true)));
        }
        return $token;
    }), 'checkout.js_extension' => static function (): string {
        //todo: use min.js when script debug is enabled
        return '.js';
    }, 'checkout.ajax_library_filename' => static function (ContainerInterface $container): string {
        $jsExtension = (string) $container->get('checkout.js_extension');
        return sprintf('op-payment-widget%1$s', $jsExtension);
    }, 'checkout.checkout_script_filename' => static function (ContainerInterface $container): string {
        $jsExtension = (string) $container->get('checkout.js_extension');
        return sprintf('payoneer-checkout%1$s', $jsExtension);
    }, 'checkout.success_url' => new Value(''), 'checkout.failure_url' => new Value(''), 'checkout.script_debug' => new Value(\false), 'checkout.css_assets_relative_path' => new Factory(['core.local_modules_directory_name', 'checkout.module_directory_name'], static function (string $modulesDirectoryRelativePath, string $moduleDirectoryName): string {
        $moduleRelativePath = sprintf('%1$s/%2$s', $modulesDirectoryRelativePath, $moduleDirectoryName);
        return sprintf('%1$s/assets/css', $moduleRelativePath);
    }), 'checkout.list_url_container_attribute_name' => static function (): string {
        return 'data-payoneer-list-url';
    }, 'checkout.session_hash_key' => new Value('_payoneer_checkout_hash'), 'checkout.list_hash_container_id' => new Value('data-payoneer-list-hash'), 'checkout.module_name' => new Value('checkout'), 'checkout.templates_dir_virtual_path' => new StringService('{module_name}/static/templates', ['module_name' => 'checkout.module_name']), 'checkout.module_path' => new Value(dirname(__FILE__, 2)), 'checkout.templates_dir_local_path' => new StringService('{module_path}/static/templates', ['module_path' => 'checkout.module_path']), 'checkout.security_token_generator' => new Constructor(TokenGenerator::class), 'checkout.transaction_id_generator' => new Constructor(TransactionIdGenerator::class), 'checkout.checkout_hash_provider' => new Constructor(CheckoutHashProvider::class, ['wc']), 'checkout.misconfiguration_detector' => new Constructor(MisconfigurationDetector::class), 'checkout.gateway_icon_elements_css' => new Value(<<<CSS
input:is(#payment_method_payoneer-checkout):checked + label > #gateway-icons-payoneer {
\tdisplay: none;
}
CSS
), 'checkout.gateway_icon_elements_filenames' => new Factory(['checkout.amex_icon_enabled', 'checkout.jcb_icon_enabled', 'checkout.diners_icon_enabled', 'checkout.discover_icon_enabled', 'checkout.afterpay_icon_enabled', 'checkout.selected_payment_flow', 'checkout.gateway_icon_elements_filenames_all'], static function (bool $amexEnabled, bool $jcbEnabled, bool $dinersEnabled, bool $discoverEnabled, bool $afterpayEnabled, string $selectedPaymentFlow, array $icons): array {
        if (!$amexEnabled) {
            $icons = array_diff($icons, ['amex.svg']);
        }
        if (!$jcbEnabled) {
            $icons = array_diff($icons, ['jcb.svg']);
        }
        if (!$dinersEnabled) {
            $icons = array_diff($icons, ['diners.svg']);
        }
        if (!$discoverEnabled) {
            $icons = array_diff($icons, ['discover.svg']);
        }
        if ($selectedPaymentFlow === 'embedded' || !$afterpayEnabled) {
            $icons = array_diff($icons, ['afterpay.svg']);
        }
        return $icons;
    }), 'checkout.gateway_icon_elements.base_path' => new Factory(['checkout.module_root_path'], static function (string $moduleRootPath): string {
        return "{$moduleRootPath}/assets/img";
    }), 'checkout.gateway_icon_elements' => new Factory(['checkout.gateway_icon_elements.base_path', 'checkout.gateway_icon_elements_filenames'], static function (string $basePath, array $imgFiles): array {
        return array_map(static function (string $file) use ($basePath): string {
            return plugins_url('img/' . $file, $basePath);
        }, $imgFiles);
    }), 'checkout.gateway_icon_elements_filenames_cards' => new Value(['visa.svg', 'mastercard.svg', 'amex.svg', 'discover.svg', 'diners.svg', 'jcb.svg']), 'checkout.gateway_icon_elements_filenames_cards.enabled' => new Factory(['checkout.gateway_icon_elements_filenames', 'checkout.gateway_icon_elements_filenames_cards'], static function (array $enabledIconsFilenames, array $cardsIconsFilenames): array {
        return array_intersect($enabledIconsFilenames, $cardsIconsFilenames);
    }), 'checkout.gateway_icon_elements_filenames_afterpay' => new Value(['afterpay.svg']), 'checkout.gateway_icon_elements_filenames_all' => new Factory(['checkout.gateway_icon_elements_filenames_cards', 'checkout.gateway_icon_elements_filenames_afterpay'], static function (array $iconsCards, array $iconsAfterpay): array {
        return array_merge($iconsCards, $iconsAfterpay);
    }), 'checkout.gateway_icon_elements_cards' => new Factory(['checkout.gateway_icon_elements.base_path', 'checkout.gateway_icon_elements_filenames_cards'], static function (string $basePath, array $imgFiles): array {
        return array_map(static function (string $file) use ($basePath): string {
            return plugins_url('img/' . $file, $basePath);
        }, $imgFiles);
    }), 'checkout.gateway_icon_elements_cards.enabled' => new Factory(['checkout.gateway_icon_elements.base_path', 'checkout.gateway_icon_elements_filenames_cards.enabled'], static function (string $basePath, array $imgFiles): array {
        return array_map(static function (string $file) use ($basePath): string {
            return plugins_url('img/' . $file, $basePath);
        }, $imgFiles);
    }), 'checkout.gateway_icon_elements_afterpay' => new Factory(['checkout.gateway_icon_elements.base_path', 'checkout.gateway_icon_elements_filenames_afterpay'], static function (string $basePath, array $imgFiles): array {
        return array_map(static function (string $file) use ($basePath): string {
            return plugins_url('img/' . $file, $basePath);
        }, $imgFiles);
    }), 'checkout.settings.general_settings_fields' => Service::fromFile(__DIR__ . "/general_settings_fields.php"), 'checkout.settings.appearance_settings_fields' => Service::fromFile(__DIR__ . "/appearance_settings_fields.php"), 'checkout.on_error_refresh_fragment_flag' => new Value('payoneer_refresh_fragment_onError'), 'checkout.is_on_error_refresh_fragment_flag' => new Factory(['checkout.on_error_refresh_fragment_flag'], static function (string $onErrorFlag): bool {
        /**
         * We can force refresh if a special flag is added
         */
        $postData = [];
        $data = filter_input(\INPUT_POST, 'post_data') ?? '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        assert(is_string($data));
        parse_str($data, $postData);
        if (isset($postData[$onErrorFlag]) && $postData[$onErrorFlag] === 'true') {
            return \true;
        }
        return \false;
    }), 'checkout.flow_options' => new Value([]), 'checkout.flow_options_description' => static function (): string {
        return __('Select the payment flow for every transaction.', 'payoneer-checkout');
    }, 'checkout.payment_flow_override_flag' => new Value('payoneer_force_hosted_flow'), 'checkout.payment_flow_override_flag.is_set' => new Factory(['checkout.payment_flow_override_flag'], static function (string $forceHostedFlowFlag): bool {
        return filter_input(\INPUT_GET, $forceHostedFlowFlag, (int) \FILTER_VALIDATE_BOOL) || filter_input(\INPUT_POST, $forceHostedFlowFlag, (int) \FILTER_VALIDATE_BOOL);
    }), 'checkout.selected_payment_flow' => new Factory(['inpsyde_payment_gateway.options', 'checkout.payment_flow_override_flag.is_set'], static function (ContainerInterface $options, bool $forceHostedFlowFlagIsSet): string {
        if ($forceHostedFlowFlagIsSet) {
            return 'hosted';
        }
        try {
            return (string) $options->get('payment_flow');
        } catch (\Throwable $exc) {
            return "embedded";
            // default
        }
    }), 'checkout.http_request_timeout' => new Value(70), 'checkout.notification_received' => new Factory(['checkout.notification_received.option_name'], static function (string $optionName): bool {
        return get_option($optionName) === 'yes';
    }), 'checkout.notification_received.option_name' => new Value('payoneer-checkout-notification-received'), 'checkout.state_provider' => new Constructor(StateProvider::class, ['checkout.wc.countries']), 'inpsyde_payment_gateway.is_live_mode' => new Factory(['inpsyde_payment_gateway.options'], static function (ContainerInterface $options): bool {
        $optionValue = $options->get('live_mode');
        $optionValue = $optionValue !== 'no';
        return $optionValue;
    }), 'checkout.amex_icon_enabled' => new Factory(['checkout.payment_gateway_options'], static function (ContainerInterface $options): bool {
        if (!$options->has('show_amex_icon')) {
            return \true;
            //Show icon by default even if options wasn't saved yet.
        }
        $enabled = $options->get('show_amex_icon') !== 'no';
        return $enabled;
    }), 'checkout.product_tax_code_field_name' => new Value('_payoneer-checkout_tax-code'), 'checkout.default_product_tax_code' => new Value(null), 'checkout.product_tax_code_provider' => new Constructor(ProductTaxCodeProvider::class, ['checkout.product_tax_code_field_name', 'checkout.default_product_tax_code']), 'checkout.jcb_icon_enabled' => new Factory(['checkout.payment_gateway_options'], static function (ContainerInterface $options): bool {
        if (!$options->has('show_jcb_icon')) {
            return \false;
            //Hide icon by default if options wasn't saved yet.
        }
        return $options->get('show_jcb_icon') !== 'no';
    }), 'checkout.diners_icon_enabled' => new Factory(['payment_methods.payoneer-afterpay.instance'], static function (\WC_Payment_Gateway $gateway): bool {
        return $gateway->get_option('show_diners_discover_icon') !== 'no';
    }), 'checkout.discover_icon_enabled' => new Alias('checkout.diners_icon_enabled'), 'checkout.afterpay_icon_enabled' => new Factory(['payment_methods.payoneer-afterpay.instance'], static function (\WC_Payment_Gateway $gateway): bool {
        return $gateway->get_option('show_afterpay_icon') !== 'no';
    })];
};
