<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Checkout;

use Inpsyde\PayoneerForWoocommerce\AssetCustomizer\AssetProcessorInterface;
use Stringable;

/**
 * Callable registering module assets.
 *
 * @psalm-type MapOfScalars = array<string, \Stringable|scalar|array<string, Stringable|scalar>>
 * @psalm-type Options = array<string, \Stringable|scalar|MapOfScalars>
 */
class RegisterCheckoutAssets
{
    /**
     * Path to the Payoneer AJAX library.
     *
     * @var string
     */
    protected $ajaxLibraryPath;

    /**
     * URL of the Payoneer AJAX library.
     *
     * @var string
     */
    protected $ajaxLibraryUrl;

    /**
     * Path to the checkout script.
     *
     * @var string
     */
    protected $checkoutScriptPath;

    /**
     * URL of the checkout script.
     *
     * @var string
     */
    protected $checkoutScriptUrl;

    /**
     * A handle used to register AJAX library.
     *
     * @var string
     */
    protected $ajaxLibrarySlug;

    /**
     * A handle used to register checkout script.
     *
     * @var string
     */
    protected $checkoutLibrarySlug;

    /**
     * A data to pass to checkout script.
     *
     * @var array
     */
    protected $checkoutScriptData;
    /**
     * @var AssetProcessorInterface
     */
    protected $assetProcessor;
    /**
     * @var string
     */
    protected $customCssPath;
    /**
     * @var array
     */
    protected $customCssProcessingOptions;
    /**
     * @var string
     */
    protected $paymentWidgetCssUrl;
    /**
     * @var string
     */
    protected $paymentWidgetCssPath;

    /**
     * @param string $ajaxLibraryPath Path to AJAX library script.
     * @param string $ajaxLibraryUrl URL of AJAX library script.
     * @param string $checkoutScriptPath Path to the checkout script.
     * @param string $checkoutScriptUrl URL of the checkout script.
     * @param array $checkoutScriptData Data to pass to checkout script.
     * @param string $paymentWidgetCssPath Path to the payment widget CSS.
     * @param string $paymentWidgetCssUrl Use Of the payment widget CSS.
     * @param AssetProcessorInterface $assetProcessor To process asset and return their URLs.
     * @param string $customCssPath A path to custom CSS that should be processed.
     * @param array $customCssProcessingOptions Options required to process assets.
     */
    public function __construct(
        string $ajaxLibraryPath,
        string $ajaxLibraryUrl,
        string $checkoutScriptPath,
        string $checkoutScriptUrl,
        string $paymentWidgetCssPath,
        string $paymentWidgetCssUrl,
        array $checkoutScriptData,
        AssetProcessorInterface $assetProcessor,
        string $customCssPath,
        array $customCssProcessingOptions
    ) {

        $this->ajaxLibraryPath = $ajaxLibraryPath;
        $this->ajaxLibraryUrl = $ajaxLibraryUrl;
        $this->ajaxLibrarySlug = basename($this->ajaxLibraryPath);
        $this->checkoutScriptPath = $checkoutScriptPath;
        $this->checkoutScriptUrl = $checkoutScriptUrl;
        $this->checkoutLibrarySlug = basename($this->checkoutScriptUrl);
        $this->checkoutScriptData = $checkoutScriptData;
        $this->assetProcessor = $assetProcessor;
        $this->customCssPath = $customCssPath;
        $this->customCssProcessingOptions = $customCssProcessingOptions;
        $this->paymentWidgetCssUrl = $paymentWidgetCssUrl;
        $this->paymentWidgetCssPath = $paymentWidgetCssPath;
    }

    /**
     * Enqueue AJAX library script.
     */
    public function __invoke(): void
    {
        $this->enqueueAjaxLibrary();
        $this->enqueueCheckoutScript();
        $this->enqueuePaymentWidgetCss();
    }

    /**
     * Add Payoneer AJAX library to the list of scripts loaded by WP.
     */
    protected function enqueueAjaxLibrary(): void
    {
        $version = file_exists($this->ajaxLibraryPath) ? filemtime($this->ajaxLibraryPath) : '';

        wp_enqueue_script(
            $this->ajaxLibrarySlug,
            $this->ajaxLibraryUrl,
            ['jquery'],
            (string) $version,
            true
        );
    }

    /**
     * Add checkout script to the list of scripts loaded by WP.
     */
    protected function enqueueCheckoutScript(): void
    {
        $version = file_exists($this->checkoutScriptPath) ?
            filemtime($this->checkoutScriptPath) :
            '';

        wp_enqueue_script(
            $this->checkoutLibrarySlug,
            $this->checkoutScriptUrl,
            [],
            (string) $version,
            true
        );

        /** @psalm-var Options $options */
        $options = $this->customCssProcessingOptions;

        $customCssUrl = (string) $this->assetProcessor->process(
            $this->customCssPath,
            $options
        );
        $checkoutScriptData = array_merge(
            $this->checkoutScriptData,
            [
                'cssUrl' => $customCssUrl,
                'isPayForOrder' => is_wc_endpoint_url('order-pay'),
            ]
        );

        wp_localize_script(
            $this->checkoutLibrarySlug,
            'PayoneerData',
            $checkoutScriptData
        );
    }

    protected function enqueuePaymentWidgetCss(): void
    {
        wp_enqueue_style(
            'op-payment-widget-css',
            $this->paymentWidgetCssUrl,
            [],
            file_exists($this->paymentWidgetCssPath) ?
                (string) filemtime($this->paymentWidgetCssPath) :
                ''
        );
    }
}
