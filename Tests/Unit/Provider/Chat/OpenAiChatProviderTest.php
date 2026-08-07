<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Provider\Chat;

use GuzzleHttp\Psr7\Utils;
use Maispace\MaiSearch\Domain\Model\ChatMessage;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use Maispace\MaiSearch\Provider\Chat\OpenAiChatProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiChatProviderTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
    }

    private function sseResponse(string $sse, int $statusCode = 200): ResponseInterface&MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn(Utils::streamFor($sse));

        return $response;
    }

    #[Test]
    public function getIdentifierReturnsOpenai(): void
    {
        $provider = new OpenAiChatProvider($this->requestFactory, 'sk-test');

        self::assertSame('openai', $provider->getIdentifier());
    }

    #[Test]
    public function streamYieldsTokensFromSseChunks(): void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $this->requestFactory->method('request')->willReturn($this->sseResponse($sse));

        $provider = new OpenAiChatProvider($this->requestFactory, 'sk-test');
        $tokens = iterator_to_array($provider->stream(new ChatRequest([new ChatMessage('user', 'Hi')])));

        self::assertSame(['Hello', ' world'], $tokens);
    }

    #[Test]
    public function completeAggregatesStreamedTokens(): void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $this->requestFactory->method('request')->willReturn($this->sseResponse($sse));

        $provider = new OpenAiChatProvider($this->requestFactory, 'sk-test');
        $response = $provider->complete(new ChatRequest([new ChatMessage('user', 'Hi')]));

        self::assertSame('Hello world', $response->content);
    }

    #[Test]
    public function streamSendsBearerTokenAndModel(): void
    {
        $this->requestFactory
            ->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('api.openai.com'),
                'POST',
                self::callback(static function (array $options): bool {
                    $body = json_decode((string) $options['body'], true);

                    return ($options['headers']['Authorization'] ?? '') === 'Bearer sk-test'
                        && ($body['model'] ?? '') === 'gpt-4o-mini';
                }),
            )
            ->willReturn($this->sseResponse("data: [DONE]\n\n"));

        $provider = new OpenAiChatProvider($this->requestFactory, 'sk-test');
        iterator_to_array($provider->stream(new ChatRequest([new ChatMessage('user', 'Hi')])));
    }

    #[Test]
    public function streamUsesRequestModelOverride(): void
    {
        $this->requestFactory
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode((string) $options['body'], true);

                    return ($body['model'] ?? '') === 'gpt-4o';
                }),
            )
            ->willReturn($this->sseResponse("data: [DONE]\n\n"));

        $provider = new OpenAiChatProvider($this->requestFactory, 'sk-test');
        iterator_to_array($provider->stream(new ChatRequest([new ChatMessage('user', 'Hi')], model: 'gpt-4o')));
    }

    #[Test]
    public function streamThrowsOnErrorStatusInsteadOfYieldingEmptyAnswer(): void
    {
        $this->requestFactory->method('request')->willReturn($this->sseResponse('{"error":"unauthorized"}', 401));

        $provider = new OpenAiChatProvider($this->requestFactory, 'sk-test');

        $this->expectException(\RuntimeException::class);
        iterator_to_array($provider->stream(new ChatRequest([new ChatMessage('user', 'Hi')])));
    }
}
