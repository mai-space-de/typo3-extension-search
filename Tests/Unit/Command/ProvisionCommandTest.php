<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Command;

use Maispace\MaiSearch\Command\ProvisionCommand;
use Maispace\MaiSearch\Domain\Dto\ProvisioningResult;
use Maispace\MaiSearch\Domain\Service\ProvisioningService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProvisionCommandTest extends TestCase
{
    private function tester(ProvisioningService $provisioningService): CommandTester
    {
        return new CommandTester(new ProvisionCommand($provisioningService));
    }

    #[Test]
    public function reportsSuccessWhenItemsWereProvisioned(): void
    {
        $provisioningService = $this->createMock(ProvisioningService::class);
        $provisioningService->method('provision')->willReturn(
            new ProvisioningResult('solr', ['core_de', 'core_en']),
        );

        $tester = $this->tester($provisioningService);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('core_de, core_en', $tester->getDisplay());
    }

    #[Test]
    public function reportsNothingToDoWhenAlreadyProvisioned(): void
    {
        $provisioningService = $this->createMock(ProvisioningService::class);
        $provisioningService->method('provision')->willReturn(new ProvisioningResult('meilisearch', []));

        $tester = $this->tester($provisioningService);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('nothing to do', $tester->getDisplay());
    }

    #[Test]
    public function displaysMessageForBackendsWithNothingToProvision(): void
    {
        $provisioningService = $this->createMock(ProvisioningService::class);
        $provisioningService->method('provision')->willReturn(
            new ProvisioningResult('indexed_search', [], 'Nothing to provision.'),
        );

        $tester = $this->tester($provisioningService);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Nothing to provision.', $tester->getDisplay());
    }

    #[Test]
    public function returnsFailureWhenProvisioningThrows(): void
    {
        $provisioningService = $this->createMock(ProvisioningService::class);
        $provisioningService->method('provision')->willThrowException(new \RuntimeException('Solr unreachable'));

        $tester = $this->tester($provisioningService);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Solr unreachable', $tester->getDisplay());
    }
}
