<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Scheduler;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Scheduler\FullReindexTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FullReindexTaskTest extends TestCase
{
    #[Test]
    public function fullReindexTaskClassExists(): void
    {
        self::assertTrue(class_exists(FullReindexTask::class));
    }

    #[Test]
    public function executeMethodExistsAndReturnsBool(): void
    {
        $task = new FullReindexTask();

        self::assertTrue(method_exists($task, 'execute'));

        $returnType = (new \ReflectionMethod(FullReindexTask::class, 'execute'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame('bool', $returnType->getName());
    }

    #[Test]
    public function indexingContextCarriesLanguageCode(): void
    {
        $context = new IndexingContext('core_de', 100, 0, 'de');

        self::assertSame('core_de', $context->core);
        self::assertSame(100, $context->batchSize);
        self::assertSame(0, $context->offset);
        self::assertSame('de', $context->languageCode);
    }

    #[Test]
    public function indexingContextLanguageCodeDefaultsToNull(): void
    {
        $context = new IndexingContext('core_en');

        self::assertNull($context->languageCode);
    }
}
