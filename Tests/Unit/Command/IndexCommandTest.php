<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Command;

use Maispace\MaiSearch\Command\IndexCommand;
use Maispace\MaiSearch\Domain\Dto\ProvisioningResult;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\ProvisioningService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IndexCommandTest extends TestCase
{
    private IndexManagementService&MockObject $indexManagementService;

    private ProvisioningService&MockObject $provisioningService;

    protected function setUp(): void
    {
        $this->indexManagementService = $this->createMock(IndexManagementService::class);
        $this->provisioningService = $this->createMock(ProvisioningService::class);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new IndexCommand(
            $this->indexManagementService,
            $this->provisioningService,
        ));
    }

    #[Test]
    public function reindexesMeilisearchWithoutTouchingSolr(): void
    {
        $this->provisioningService
            ->expects(self::once())
            ->method('provision')
            ->willReturn(new ProvisioningResult('meilisearch', ['mai_search_de']));
        $this->indexManagementService->expects(self::once())->method('reindexAll');
        $this->indexManagementService->method('getIndexStats')->willReturn([
            'totalDocuments' => 2,
            'cores' => [
                'de' => ['core' => 'mai_search_de', 'totalDocuments' => 2, 'types' => ['page' => 2]],
            ],
        ]);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('meilisearch', $tester->getDisplay());
        self::assertStringContainsString('Reindex completed successfully', $tester->getDisplay());
        self::assertStringContainsString('mai_search_de', $tester->getDisplay());
    }

    #[Test]
    public function reindexesSolrViaProvisioning(): void
    {
        $this->provisioningService
            ->expects(self::once())
            ->method('provision')
            ->willReturn(new ProvisioningResult('solr', []));
        $this->indexManagementService->expects(self::once())->method('reindexAll');
        $this->indexManagementService->method('getIndexStats')->willReturn([
            'totalDocuments' => 1,
            'cores' => [
                'de' => ['core' => 'core_de', 'totalDocuments' => 1, 'types' => ['page' => 1]],
            ],
        ]);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Reindexing all content into solr', $tester->getDisplay());
        self::assertStringContainsString('core_de', $tester->getDisplay());
    }

    #[Test]
    public function schemaOnlyProvisionsTheActiveBackendWithoutReindexing(): void
    {
        $this->provisioningService
            ->expects(self::once())
            ->method('provision')
            ->willReturn(new ProvisioningResult('meilisearch', ['mai_search_de']));
        $this->indexManagementService->expects(self::never())->method('reindexAll');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--schema-only' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('mai_search_de', $tester->getDisplay());
    }

    #[Test]
    public function doesNotReindexWhenProvisioningFails(): void
    {
        $this->provisioningService
            ->method('provision')
            ->willThrowException(new \RuntimeException('Meilisearch unreachable'));
        $this->indexManagementService->expects(self::never())->method('reindexAll');

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Meilisearch unreachable', $tester->getDisplay());
    }

    #[Test]
    public function statsSkipsProvisioningAndReindex(): void
    {
        $this->provisioningService->expects(self::never())->method('provision');
        $this->indexManagementService->expects(self::never())->method('reindexAll');
        $this->indexManagementService->method('getIndexStats')->willReturn([
            'totalDocuments' => 0,
            'cores' => [],
        ]);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--stats' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No documents indexed yet', $tester->getDisplay());
    }
}
