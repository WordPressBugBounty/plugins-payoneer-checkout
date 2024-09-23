<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\PaymentGateway\Tests\Functional;

use Dhii\Collection\MutableContainerInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\ContainerMapMerchantModel;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\Merchant;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantDeserializerInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantFactory;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantFactoryInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantQueryInterface;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantSerializer;
use Inpsyde\PayoneerForWoocommerce\PaymentGateway\Merchant\MerchantSerializerInterface;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 *
 * @psalm-type MerchantData = array{
 *  id?: int,
 *  label: string,
 *  code: string,
 *  token: string,
 *  base_url: string,
 *  transaction_url_template: string
 * }
 */
class MerchantTest extends TestCase
{
    public function testLoad()
    {
        {
            $data = $this->createMerchantData();
            $storageKey = uniqid('key1');
            $storage = $this->createStoraga([
                $storageKey => $data,
            ]);
            $urlFactory = $this->createUrlFactory();
            $merchantTemplate = $this->createMerchant($urlFactory, null);
            $serializer = $this->createSerializer($merchantTemplate);
            $deserializer = $serializer;
            $query = $this->createQuery($storage, $storageKey, $serializer, $deserializer);
        }

        {
            $merchants = $query->execute();
            $this->assertEquals(
                count($data),
                count($merchants),
                'Number of merchants in result'
            );

            foreach ($merchants as $merchant) {
                $dto = $serializer->serializeMerchant($merchant);
                $this->assertContains($this->normalizeMap($dto), $data, 'Merchant not found');
            }
        }
    }

    /**
     * @return array<int, MerchantData>
     */
    protected function createMerchantData(): array
    {
        $code1 = uniqid('code1');
        $code2 = uniqid('code2');

        return [
            $this->normalizeMap([
                'id' => 1,
                'code' => $code1,
                'environment' => uniqid('environment1'),
                'token' => uniqid('token1'),
                'base_url' => sprintf('https://%1$s.com', uniqid('domain1')),
                'transaction_url_template' => sprintf(
                    'https://%1$s.com/transaction/%2$s?merchant=%3$s',
                    uniqid('domain1'),
                    '%1$s',
                    $code1
                ),
                'label' => uniqid('label1'),
                'division'=> uniqid('division_')
            ]),
            $this->normalizeMap([
                'id' => 2,
                'code' => $code2,
                'environment' => uniqid('environment2'),
                'token' => uniqid('token2'),
                'base_url' => sprintf('https://%1$s.com', uniqid('domain2')),
                'transaction_url_template' => sprintf(
                    'https://%1$s.com/transaction/%2$s?merchant=%3$s',
                    uniqid('domain2'),
                    '%1$s',
                    $code2
                ),
                'label' => uniqid('label2'),
                'division'=> uniqid('division_')
            ]),
        ];
    }

    /**
     * Normalizes a map, such that two maps can be compared.
     *
     * @param array $map The map to normalize.
     *
     * @return array The normalized map.
     */
    protected function normalizeMap(array $map): array
    {
        ksort($map, SORT_NATURAL);

        return $map;
    }

    /**
     * @return MutableContainerInterface&MockObject
     */
    protected function createStoraga(array $data): MutableContainerInterface
    {
        $mock = $this->getMockBuilder(MutableContainerInterface::class)
            ->onlyMethods([
                'get',
                'set',
            ])
            ->getMockForAbstractClass();
        $mock->method('get')
            ->will($this->returnCallback(function ($key) use (&$data) {
                return array_key_exists($key, $data)
                    ? $data[$key]
                    : null;
            }));
        $mock->method('set')
            ->will($this->returnCallback(function ($key, $value) use (&$data) {
                $data[$key] = $value;
            }));

        return $mock;
    }

    /**
     * @return MerchantFactoryInterface&MockObject
     */
    protected function createFactory(UriFactoryInterface $urlFactory): MerchantFactoryInterface
    {
        $mock = $this->getMockBuilder(MerchantFactory::class)
            ->setConstructorArgs([
                $urlFactory
            ])
            ->enableProxyingToOriginalMethods()
            ->getMock();

        return $mock;
    }

    /**
     * @return MerchantSerializerInterface&MerchantDeserializerInterface&MockObject
     */
    protected function createSerializer(MerchantInterface $merchant): MerchantSerializerInterface
    {
        $mock = $this->getMockBuilder(MerchantSerializer::class)
             ->setConstructorArgs([$merchant])
             ->enableProxyingToOriginalMethods()
             ->getMock();

        return $mock;
    }

    /**
     * @return MerchantInterface&MockObject
     */
    protected function createMerchant(UriFactoryInterface $uriFactory, ?int $id): MerchantInterface
    {
        $mock = $this->getMockBuilder(Merchant::class)
            ->setConstructorArgs([$uriFactory, $id])
            ->enableProxyingToOriginalMethods()
            ->getMock();

        return $mock;
    }

    /**
     * @return MerchantQueryInterface&MockObject
     */
    protected function createQuery($storage, string $storageKey, $serializer, $deserializer): MerchantQueryInterface
    {
        $mock = $this->getMockBuilder(ContainerMapMerchantModel::class)
            ->setConstructorArgs([
                $storage,
                $storageKey,
                $serializer,
                $deserializer
            ])
            ->enableProxyingToOriginalMethods()
            ->getMock();

        return $mock;
    }

    /**
     * @return UriFactoryInterface&MockObject
     */
    protected function createUrlFactory(): UriFactoryInterface
    {
        $mock = $this->getMockBuilder(UriFactoryInterface::class)
            ->onlyMethods([
                'createUri'
            ])
            ->getMock();

        $mock->method('createUri')
             ->will($this->returnCallback(function (string $url): UriInterface {
                     return new Uri($url);
             }));

        return $mock;
    }
}
