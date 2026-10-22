<?php
declare(strict_types=1);

namespace app\command;

use app\services\marketplace\product\MarketplaceProductScanGcTimer;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/** Explicit deployment action only; constructing this command never registers or runs GC. */
final class MarketplaceProductScanGcInstall extends Command
{
    protected function configure()
    {
        $this->setName('marketplace:product-scan-gc-install')
            ->setDescription('Install or verify the product scan private-image GC timer');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $result = $this->app->make(MarketplaceProductScanGcTimer::class)->register();
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return 0;
        } catch (\Throwable $error) {
            $output->writeln('Marketplace product scan GC timer installation unavailable');
            return 1;
        }
    }
}
