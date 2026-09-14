<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Infrastructure\Provider;

use App\Module\Communications\Infrastructure\Provider\Telegram\CurlTelegramApiClient;
use App\Module\Communications\Infrastructure\Provider\Telegram\TelegramApiException;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\CurlWhatsAppApiClient;
use App\Module\Communications\Infrastructure\Provider\WhatsApp\WhatsAppApiException;
use PHPUnit\Framework\TestCase;

final class CurlMessagingApiClientTest extends TestCase
{
    public function testTelegramTransportSendsDocumentedJsonRequest(): void
    {
        $this->withServer(200, '{"ok":true,"result":{"message_id":10}}', function (string $baseUrl, string $requestFile): void {
            $response = (new CurlTelegramApiClient($baseUrl, 2))->sendMessage('123:token', ['chat_id' => '42', 'text' => 'Привет']);
            $request = $this->request($requestFile);

            self::assertSame(200, $response->statusCode);
            self::assertSame(10, $response->payload['result']['message_id']);
            self::assertSame('/bot123:token/sendMessage', $request['uri']);
            self::assertSame(['chat_id' => '42', 'text' => 'Привет'], json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR));
        });
    }

    public function testWhatsAppTransportUsesBearerTokenAndVersionedEndpoint(): void
    {
        $this->withServer(200, '{"messages":[{"id":"wamid.1"}]}', function (string $baseUrl, string $requestFile): void {
            $response = (new CurlWhatsAppApiClient($baseUrl, 'v26.0', 2))->sendMessage('secret-token', 'phone-id', ['to' => '79990000000']);
            $request = $this->request($requestFile);

            self::assertSame(200, $response->statusCode);
            self::assertSame('wamid.1', $response->payload['messages'][0]['id']);
            self::assertSame('/v26.0/phone-id/messages', $request['uri']);
            self::assertSame('Bearer secret-token', $request['headers']['Authorization']);
        });
    }

    public function testMalformedSuccessfulResponsesAreTransientFailures(): void
    {
        foreach ([
            static fn (string $url) => (new CurlTelegramApiClient($url, 2))->sendMessage('token', ['text' => 'x']),
            static fn (string $url) => (new CurlWhatsAppApiClient($url, 'v26.0', 2))->sendMessage('token', 'phone', ['text' => 'x']),
        ] as $request) {
            try {
                $this->withServer(200, 'not-json', static fn (string $url): mixed => $request($url));
                self::fail('Malformed successful response must fail.');
            } catch (TelegramApiException|WhatsAppApiException $exception) {
                self::assertTrue($exception->transient);
            }
        }
    }

    public function testNetworkFailuresAreTransient(): void
    {
        foreach ([
            static fn () => (new CurlTelegramApiClient('http://127.0.0.1:1', 1))->sendMessage('token', ['text' => 'x']),
            static fn () => (new CurlWhatsAppApiClient('http://127.0.0.1:1', 'v26.0', 1))->sendMessage('token', 'phone', ['text' => 'x']),
        ] as $request) {
            try {
                $request();
                self::fail('Network failure must fail.');
            } catch (TelegramApiException|WhatsAppApiException $exception) {
                self::assertSame(0, $exception->statusCode);
                self::assertTrue($exception->transient);
            }
        }
    }

    /** @return array{uri: string, body: string, headers: array<string, string>} */
    private function request(string $file): array
    {
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param callable(string, string): mixed $callback */
    private function withServer(int $status, string $body, callable $callback): mixed
    {
        $requestFile = tempnam(sys_get_temp_dir(), 'messaging-request-');
        $router = tempnam(sys_get_temp_dir(), 'messaging-router-');
        self::assertIsString($requestFile);
        self::assertIsString($router);
        file_put_contents($router, <<<'PHP'
<?php
$request = ['uri' => $_SERVER['REQUEST_URI'], 'body' => file_get_contents('php://input'), 'headers' => getallheaders()];
file_put_contents(getenv('MESSAGING_STUB_REQUEST_FILE'), json_encode($request, JSON_THROW_ON_ERROR));
http_response_code((int) getenv('MESSAGING_STUB_STATUS'));
echo getenv('MESSAGING_STUB_BODY');
PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $process = proc_open(
            PHP_BINARY.' -S 127.0.0.1:'.$port.' '.escapeshellarg($router),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($router),
            ['MESSAGING_STUB_REQUEST_FILE' => $requestFile, 'MESSAGING_STUB_STATUS' => (string) $status, 'MESSAGING_STUB_BODY' => $body],
        );
        self::assertIsResource($process);
        try {
            for ($attempt = 0; $attempt < 30; ++$attempt) {
                $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
                if (false !== $probe) {
                    fclose($probe);
                    break;
                }
                usleep(20_000);
            }

            return $callback('http://127.0.0.1:'.$port, $requestFile);
        } finally {
            proc_terminate($process);
            proc_close($process);
            unlink($router);
            unlink($requestFile);
        }
    }
}
