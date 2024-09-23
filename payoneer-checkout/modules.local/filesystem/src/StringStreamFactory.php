<?php

declare(strict_types=1);

namespace Inpsyde\PayoneerForWoocommerce\Filesystem;

use Psr\Http\Message\StreamInterface;

/**
 * Creates a string stream instance using the
 * {@link https://github.com/ancarda/psr7-string-stream ancarda/psr7-string-stream} package.
 */
class StringStreamFactory implements StringStreamFactoryInterface
{
    /**
     * @inheritDoc
     */
    public function createStreamFromString(string $contents): StreamInterface
    {
        return new StringStream($contents);
    }
}
