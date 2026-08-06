<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Provider\Chat;

use GuzzleHttp\Psr7\Utils;
use Maispace\MaiSearch\Domain\Model\ChatMessage;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use Maispace\MaiSearch\Provider\Chat\CustomChatProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class CustomChatProviderTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
    }

    private function sseResponse(string $sse): ResponseInterface&MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn(Utils::streamFor($sse));

        return $response;
    }

    #[Test]
    public function getIdentifierReturnsCustom(): void
    {
        $provider = new CustomChatProvider($this->requestFactory, 'https://chat.example.com');

        self::assertSame('custom', $provider->getIdentifier());
    }

    #[Test]
    public function streamOmitsAuthorizationHeaderWhenApiKeyIsEmpty(): void
    {
        $this->requestFactory
            ->method('request')
            ->with(
                'https://chat.example.com',
                'POST',
                self::callback(static fn(array $options): bool => !array_key_exists('Authorization', $options['headers'])),
            )
            ->willReturn($this->sseResponse("data: [DONE]\n\n"));

        $provider = new CustomChatProvider($this->requestFactory, 'https://chat.example.com');
        iterator_to_array($provider->stream(new ChatRequest([new ChatMessage('user', 'Hi')])));
    }

    #[Test]
    public function streamIncludesAuthorizationHeaderWhenApiKeyIsSet(): void
    {
        $this->requestFactory
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static fn(array $options): bool => ($options['headers']['Authorization'] ?? '') === 'Bearer custom-key'),
            )
            ->willReturn($this->sseResponse("data: [DONE]\n\n"));

        $provider = new CustomChatProvider($this->requestFactory, 'https://chat.example.com', 'custom-key');
        iterator_to_array($provider->stream(new ChatRequest([new ChatMessage('user', 'Hi')])));
    }

    #[Test]
    public function streamYieldsTokensFromSseChunks(): void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n\ndata: [DONE]\n\n";
        $this->requestFactory->method('request')->willReturn($this->sseResponse($sse));

        $provider = new CustomChatProvider($this->requestFactory, 'https://chat.example.com');
        $tokens = iterator_to_array($provider->stream(new ChatRequest([new ChatMessage('user', 'Hi')])));

        self::assertSame(['ok'], $tokens);
    }
}
