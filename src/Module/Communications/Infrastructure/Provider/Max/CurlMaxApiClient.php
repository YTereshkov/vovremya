<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Max;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CurlMaxApiClient implements MaxApiClient
{
    public function __construct(
        #[Autowire('%env(MAX_API_BASE_URL)%')]
        private string $baseUrl = 'https://platform-api2.max.ru',
        private int $timeoutSeconds = 15,
    ) {
    }

    public function sendMessage(
        string $accessToken,
        string $recipientAddress,
        array $payload,
        string $idempotencyKey,
        ?string $chatId = null,
    ): MaxApiResponse {
        $target = null !== $chatId && '' !== trim($chatId) ? ['chat_id' => $chatId] : ['user_id' => $recipientAddress];
        $url = rtrim($this->baseUrl, '/').'/messages?'.http_build_query($target, '', '&', PHP_QUERY_RFC3986);
        $handle = curl_init($url);
        if (false === $handle) {
            throw new MaxApiException('Не удалось инициализировать запрос к MAX.', 0, true);
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: '.$accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $responseBody = curl_exec($handle);
        if (false === $responseBody) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new MaxApiException('Не удалось отправить сообщение в MAX: '.mb_substr($error, 0, 300), 0, true);
        }
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $decoded = null;
        if ('' !== trim($responseBody)) {
            try {
                $candidate = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($candidate) && !array_is_list($candidate)) {
                    $decoded = $candidate;
                }
            } catch (\JsonException) {
                if (200 <= $statusCode && 300 > $statusCode) {
                    throw new MaxApiException('MAX вернул некорректный ответ.', $statusCode, true);
                }
            }
        }

        return new MaxApiResponse($statusCode, $decoded);
    }
}
