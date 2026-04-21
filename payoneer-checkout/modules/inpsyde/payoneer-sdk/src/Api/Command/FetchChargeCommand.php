<?php

declare (strict_types=1);
namespace Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command;

use Syde\Vendor\Inpsyde\PayoneerSdk\Api\Command\Exception\CommandException;
use Syde\Vendor\Inpsyde\PayoneerSdk\Client\ApiClientInterface;
use RuntimeException;
/**
 * Fetches a charge entity from Payoneer (GET charges/{longId})
 * and returns its status code.
 */
class FetchChargeCommand implements FetchChargeCommandInterface
{
    /** @var ApiClientInterface */
    protected $apiClient;
    /** @var string */
    protected $pathTemplate;
    /** @var string|null */
    protected $longId;
    public function __construct(ApiClientInterface $apiClient, string $pathTemplate = 'charges/%1$s')
    {
        $this->apiClient = $apiClient;
        $this->pathTemplate = $pathTemplate;
    }
    public function withLongId(string $longId): FetchChargeCommandInterface
    {
        $new = clone $this;
        $new->longId = $longId;
        return $new;
    }
    public function execute(): string
    {
        if ($this->longId === null) {
            throw new RuntimeException('Charge longId must be set before execute()');
        }
        try {
            $url = sprintf($this->pathTemplate, $this->longId);
            $response = $this->apiClient->get($url, [], []);
            $body = (string) $response->getBody();
            $data = json_decode($body, \true);
            if (!is_array($data) || !isset($data['status']['code'])) {
                throw new RuntimeException('Unexpected charge response: missing status.code');
            }
            return (string) $data['status']['code'];
        } catch (\Throwable $exception) {
            throw new RuntimeException(sprintf('Failed to fetch charge %1$s: %2$s', $this->longId, $exception->getMessage()), 0, $exception);
        }
    }
}
