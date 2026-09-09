<?php

declare(strict_types=1);

namespace App\Tests\Module\Communications\Infrastructure\Provider;

use App\Module\Communications\Infrastructure\Provider\Max\CurlMaxApiClient;
use App\Module\Communications\Infrastructure\Provider\Max\MaxApiException;
use PHPUnit\Framework\TestCase;

final class CurlMaxApiClientTest extends TestCase
{
    public function testSendsJsonToLocalStubWithRawAuthorization(): void
    {
        $requestFile = tempnam(sys_get_temp_dir(), 'max-request-');
        self::assertIsString($requestFile);

        $this->withServer($requestFile, 200, '{"message":{"body":{"mid":"stub-mid"}}}', function (string $baseUrl) use ($requestFile): void {
            $response = (new CurlMaxApiClient($baseUrl, 2))->sendMessage('secret-token', '123', ['text' => 'Привет'], 'outbox-1');

            self::assertSame(200, $response->statusCode);
            self::assertSame('stub-mid', $response->payload['message']['body']['mid']);
            $request = json_decode((string) file_get_contents($requestFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['text' => 'Привет'], json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR));
            self::assertSame('secret-token', $request['headers']['Authorization']);
            self::assertArrayNotHasKey('X-Idempotency-Key', $request['headers']);
            self::assertStringContainsString('user_id=123', $request['uri']);
        });

        unlink($requestFile);
    }

    public function testMalformedSuccessfulResponseIsRejected(): void
    {
        $this->expectExceptionMessage('MAX вернул некорректный ответ.');
        $requestFile = tempnam(sys_get_temp_dir(), 'max-request-');
        self::assertIsString($requestFile);
        $this->withServer($requestFile, 200, 'not-json', function (string $baseUrl): void {
            (new CurlMaxApiClient($baseUrl, 2))->sendMessage('secret-token', '123', ['text' => 'Привет'], 'outbox-2');
        });
        unlink($requestFile);
    }

    public function testNetworkFailureIsTransient(): void
    {
        try {
            (new CurlMaxApiClient('http://127.0.0.1:1', 1))->sendMessage('secret-token', '123', ['text' => 'Привет'], 'outbox-3');
            self::fail('Expected network failure.');
        } catch (MaxApiException $exception) {
            self::assertSame(0, $exception->statusCode);
            self::assertTrue($exception->transient);
        }
    }

    /** @param callable(string): void $callback */
    private function withServer(string $requestFile, int $status, string $body, callable $callback): void
    {
        $router = tempnam(sys_get_temp_dir(), 'max-router-');
        self::assertIsString($router);
        file_put_contents($router, <<<'PHP'
<?php
$request = ['uri' => $_SERVER['REQUEST_URI'], 'body' => file_get_contents('php://input'), 'headers' => getallheaders()];
file_put_contents(getenv('MAX_STUB_REQUEST_FILE'), json_encode($request, JSON_THROW_ON_ERROR));
http_response_code((int) getenv('MAX_STUB_STATUS'));
echo getenv('MAX_STUB_BODY');
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
            ['MAX_STUB_REQUEST_FILE' => $requestFile, 'MAX_STUB_STATUS' => (string) $status, 'MAX_STUB_BODY' => $body],
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
            $callback('http://127.0.0.1:'.$port);
        } finally {
            proc_terminate($process);
            proc_close($process);
            unlink($router);
        }
    }
}
