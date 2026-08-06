<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Command;

use Maispace\MaiSearch\Domain\Service\ProvisioningService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mai-search:provision',
    description: 'Create the Solr cores or Meilisearch indexes the active search backend needs',
)]
final class ProvisionCommand extends Command
{
    public function __construct(
        private readonly ProvisioningService $provisioningService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Provisioning search infrastructure');

        try {
            $result = $this->provisioningService->provision();
        } catch (\Throwable $e) {
            $io->error('Provisioning failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($result->message !== null) {
            $io->note($result->message);

            return Command::SUCCESS;
        }

        if ($result->provisioned === []) {
            $io->success(sprintf('%s: everything already exists, nothing to do.', $result->backend));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%s: provisioned %d item(s): %s',
            $result->backend,
            count($result->provisioned),
            implode(', ', $result->provisioned),
        ));

        return Command::SUCCESS;
    }
}
