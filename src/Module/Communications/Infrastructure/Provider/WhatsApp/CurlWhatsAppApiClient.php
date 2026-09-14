<?php

declare(strict_types=1);

namespace App\Module\Communications\Infrastructure\Provider\WhatsApp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CurlWhatsAppApiClient implements WhatsAppApiClient
{
    public function __construct(
        #[Autowire('%env(WHATSAPP_GRAPH_API_BASE_URL)%')]
        private string $baseUrl = 'https://graph.facebook.com',
        #[Autowire('%env(WHATSAPP_GRAPH_API_VERSION)%')]
        private string $apiVersion = 'v26.0',
        private int $timeoutSeconds = 15,
    ) {
    }

    public function sendMessage(string $accessToken, string $phoneNumberId, array $payload): WhatsAppApiResponse
    {
        $url = sprintf('%s/%s/%s/messages', rtrim($this->baseUrl, '/'), trim($this->apiVersion, '/'), rawurlencode($phoneNumberId));
        $handle = curl_init($url);
        if (false === $handle) {
            throw new WhatsAppApiException('Не удалось инициализировать запрос к WhatsApp.', 0, true);
        }
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $body = curl_exec($handle);
        if (false === $body) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new WhatsAppApiException('Не удалось отправить сообщение в WhatsApp: '.mb_substr($error, 0, 300), 0, true);
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
                    throw new WhatsAppApiException('WhatsApp вернул некорректный ответ.', $statusCode, true);
                }
            }
        }

        return new WhatsAppApiResponse($statusCode, $decoded);
    }
}
