<?php

/*
 * This file is part of the Webmozarts Console Parallelization package.
 *
 * (c) Webmozarts GmbH <office@webmozarts.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Webmozarts\Console\Parallelization\ErrorHandler;

use Throwable;

final class ItemProcessingErrorHandlerLogger implements ItemProcessingErrorHandler
{
    private ItemProcessingErrorHandler $decoratedErrorHandler;

    public function __construct(ItemProcessingErrorHandler $decoratedErrorHandler)
    {
        $this->decoratedErrorHandler = $decoratedErrorHandler;
    }

    public function handleError(string $item, Throwable $throwable, $logger): void
    {
        $logger->logItemProcessingFailed($item, $throwable);

        $this->decoratedErrorHandler->handleError($item, $throwable, $logger);
    }
}
