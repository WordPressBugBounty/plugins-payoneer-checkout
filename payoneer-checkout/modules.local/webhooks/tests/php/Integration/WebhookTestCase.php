<?php

declare(strict_types=1);


namespace Inpsyde\PayoneerForWoocommerce\Webhooks\Tests\Integration;


use Inpsyde\PayoneerForWoocommerce\Tests\Integration\AbstractApplicationTestCase;
use Mockery\MockInterface;
use WC_Order;

class WebhookTestCase extends AbstractApplicationTestCase
{

    protected function getDefaultRequestData(): array
    {
        return [
            'transactionId' => uniqid('transaction-id-'),
            'statusCode' => 'charged',
            'entity' => 'payment',
            'longId' => uniqid('long-id-'),
            'amount' => floatval(rand(1, 10000) / rand(1, 10)),
            'currency' => 'EUR',
            'pspCode' => uniqid('pspcode-'),
            'notificationId' => uniqid('auth-header-value-'),
        ];
    }

    /**
     * @return \WP_REST_Request&MockInterface
     */
    protected function createRequestMock(
        array $requestData = []
    ): \WP_REST_Request {
        $requestData = array_merge($this->getDefaultRequestData(), $requestData);

        $restRequest = \Mockery::mock(\WP_REST_Request::class);

        $restRequest->allows('get_param')->andReturnUsing(
            static function (string $paramName) use ($requestData) {
                return $requestData[$paramName] ?? null;
            }
        );

        return $restRequest;
    }

    /**
     * @param \WP_REST_Request&MockInterface $request
     * @param string $headerValue
     *
     * @return \WP_REST_Request
     */
    protected function expectListSecurityHeader(
        \WP_REST_Request $request,
        string $headerValue = null
    ): \WP_REST_Request {
        $headerValue = $headerValue ?? uniqid('auth-header-value-');

        $request->expects('get_header')
                ->andReturn($headerValue);

        return $request;
    }

    /**
     * @param array<string, mixed> $orderMetaData
     *
     * @return WC_Order|MockInterface
     */
    public function createWcOrderMock(array $orderMetaData): WC_Order
    {
        $wcOrder = \Mockery::mock(WC_Order::class);
        $wcOrder->allows('get_meta')
            ->andReturnUsing(
                function (string $metaKey) use ($orderMetaData){
                    $this->assertArrayHasKey($metaKey, $orderMetaData);
                    return $orderMetaData[$metaKey];
                });

        return $wcOrder;
    }
}
