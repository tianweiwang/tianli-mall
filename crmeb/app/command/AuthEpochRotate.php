<?php
declare(strict_types=1);

namespace app\command;

use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\AuthConfigGuard;
use crmeb\services\auth\AuthSessionStore;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** Clean one retired namespace after operators deploy a new epoch. */
class AuthEpochRotate extends Command
{
    protected function configure()
    {
        $this->setName('auth:epoch-rotate')
            ->setDescription('Delete a retired authentication epoch after configuration changes')
            ->addOption('old-epoch', null, Option::VALUE_REQUIRED, 'Retired epoch to delete')
            ->addOption('confirm', null, Option::VALUE_NONE, 'Confirm deletion of the retired epoch');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $oldEpoch = $input->getOption('old-epoch');
            if ($input->getOption('confirm') !== true || !is_string($oldEpoch)
                || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $oldEpoch) !== 1) {
                throw new AuthUnavailableException();
            }
            $auth = AuthConfigGuard::authConfig();
            if ($oldEpoch === $auth['session_epoch']) {
                throw new AuthUnavailableException();
            }
            $deleted = AuthSessionStore::fromConfig()->deleteOldEpoch($oldEpoch);
            $output->writeln('deleted_keys: ' . $deleted);
            return 0;
        } catch (\Throwable $error) {
            // Do not expose epochs, keys, credentials or private driver messages.
            $output->writeln('epoch cleanup failed');
            return 1;
        }
    }
}
