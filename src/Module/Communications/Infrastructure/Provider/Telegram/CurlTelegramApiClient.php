<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\Telegram;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CurlTelegramApiClient implements TelegramApiClient
{
    public function __construct(
        #[Autowire('%env(TELEGRAM_API_BASE_URL)%')]
        private string $baseUrl = 'https://api.telegram.org',
        private int $timeoutSeconds = 15,
    ) {
    }

    public function sendMessage(string $botToken, array $payload): TelegramApiResponse
    {
        $handle = curl_init(rtrim($this->baseUrl, '/').'/bot'.$botToken.'/sendMessage');
        if (false === $handle) {
            throw new TelegramApiException('Не удалось инициализировать запрос к Telegram.', 0, true);
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $body = curl_exec($handle);
        if (false === $body) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new TelegramApiException('Не удалось отправить сообщение в Telegram: '.mb_substr($error, 0, 300), 0, true);
        }
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $decoded = null;
        if ('' !== trim($body)) {
            try {
                $candidate = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($candidate) && !array_is_list($candidate)) {
                    $decoded = $candidate;
                }
            } catch (\JsonException) {
                if (200 <= $statusCode && 300 > $statusCode) {
                    throw new TelegramApiException('Telegram вернул некорректный ответ.', $statusCode, true);
                }
            }
        }

        return new TelegramApiResponse($statusCode, $decoded);
    }
}
