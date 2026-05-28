<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Service\VectorEmbeddingAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VectorEmbeddingAdapterTest extends TestCase
{
    private VectorEmbeddingAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new VectorEmbeddingAdapter();
    }

    #[Test]
    public function embedTextReturnsEmptyArrayWhenMaiTranslateNotLoaded(): void
    {
        $result = $this->adapter->embedText('test');

        self::assertSame([], $result);
    }

    #[Test]
    public function getMaxInputTokensReturnsZeroWhenMaiTranslateNotLoaded(): void
    {
        $result = $this->adapter->getMaxInputTokens();

        self::assertSame(0, $result);
    }

    #[Test]
    public function implementsVectorEmbeddingInterface(): void
    {
        self::assertInstanceOf(
            \Maispace\MaiSearch\Domain\Service\VectorEmbeddingInterface::class,
            $this->adapter,
        );
    }
}
