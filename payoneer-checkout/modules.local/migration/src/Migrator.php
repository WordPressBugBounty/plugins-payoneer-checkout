<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Migration;

use WC_Payment_Gateway;

/**
 * Migrates pre-1.0.0 to 1.0.0.
 *
 * There was more complicated logic for managing multi-steps update if we need to move through
 * a few versions. It was considered unneeded so far and moved to the separate branch so
 * that we can use it when and if we need it. This is the branch
 * https://github.com/inpsyde/payoneer-for-woocommerce/tree/feature/advanced-migrator and related
 * discussion https://github.com/inpsyde/payoneer-for-woocommerce/pull/239#discussion_r1005679309.
 */
class Migrator implements MigratorInterface
{
    /**
     * @var WC_Payment_Gateway
     */
    protected $gateway;
    /**
     * @var string
     */
    protected $defaultCustomCss;

    /**
     * @param WC_Payment_Gateway $gateway
     * @param string $defaultCustomCss
     *
     * @throws \Exception
     */
    public function __construct(WC_Payment_Gateway $gateway, string $defaultCustomCss)
    {

        $this->gateway = $gateway;
        $this->defaultCustomCss = $defaultCustomCss;
    }

    /**
     * @inheritDoc
     */
    public function migrate(): void
    {
        $savedCustomCSS = $this->gateway
            ->get_option('checkout_css_custom_css');

        if (! $savedCustomCSS) {
            $this->gateway->update_option('checkout_css_custom_css', $this->defaultCustomCss);
        }

        $isSandbox = $this->gateway->get_option('is_sandbox');
        if (! empty($isSandbox)) {
            $liveMode = $isSandbox === 'no' ? 'yes' : 'no';
            $this->gateway->update_option('live_mode', $liveMode);
        }
    }
}
