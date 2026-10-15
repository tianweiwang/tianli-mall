<?php
declare(strict_types=1);

namespace app\command;

use app\services\marketplace\order\MarketplaceOrderPostCommitTimer;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/** Runs only when explicitly invoked against the application's configured database and cache. */
final class MarketplaceOrderPostCommitInstall extends Command
{
    protected function configure()
    {
        $this->setName('marketplace:post-commit-install')
            ->setDescription('Install or verify the marketplace order post-commit timer');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $result = $this->app->make(MarketplaceOrderPostCommitTimer::class)->register();
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return 0;
        } catch (\Throwable $error) {
            // Database/cache details and private application configuration stay out of console output.
            $output->writeln('Marketplace order post-commit timer installation unavailable');
            return 1;
        }
    }
}
