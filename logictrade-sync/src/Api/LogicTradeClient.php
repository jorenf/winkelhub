<?php

declare(strict_types=1);

namespace LogicTradeSync\Api;

use LogicTradeSync\Utils\Logger;

/**
 * HTTP client for the LogicTrade REST API.
 *
 * All requests use the api-key from WordPress options.
 */
final class LogicTradeClient
{
    private const BASE_URL = 'https://api.logictrade.cloud/rest/v1';
    private const TIMEOUT  = 30; // seconds

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    // ------------------------------------------------------------------
    // Products
    // ------------------------------------------------------------------

    /**
     * @return array{data: array, totalResults: int, totalPages: int, pageNumber: int}
     */
    public function getProducts(int $page = 1, int $pageSize = 100): array
    {
        return $this->get('/products', [
            'pageNumber' => $page,
            'pageSize'   => $pageSize,
        ]);
    }

    /**
     * @return array{data: array, totalResults: int, totalPages: int, pageNumber: int}
     */
    public function getPrices(int $page = 1, int $pageSize = 100): array
    {
        return $this->get('/products/prices', [
            'pageNumber' => $page,
            'pageSize'   => $pageSize,
        ]);
    }

    /**
     * @return array{data: array, totalResults: int, totalPages: int, pageNumber: int}
     */
    public function getStock(int $page = 1, int $pageSize = 100): array
    {
        return $this->get('/products/stock', [
            'pageNumber' => $page,
            'pageSize'   => $pageSize,
        ]);
    }

    // ------------------------------------------------------------------
    // Product groups (categories)
    // ------------------------------------------------------------------

    /**
     * @return array
     */
    public function getCategories(): array
    {
        return $this->get('/product-groups');
    }

    // ------------------------------------------------------------------
    // Orders
    // ------------------------------------------------------------------

    /**
     * Create an order in LogicTrade.
     *
     * @param array $orderData Structured order payload.
     * @return array API response body.
     */
    public function createOrder(array $orderData): array
    {
        return $this->post('/orders', $orderData);
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    /**
     * @throws RequestException
     */
    private function get(string $endpoint, array $query = []): array
    {
        $url = self::BASE_URL . $endpoint;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $response = wp_remote_get($url, [
            'headers' => $this->headers(),
            'timeout' => self::TIMEOUT,
        ]);

        return $this->handleResponse($response, 'GET', $endpoint);
    }

    /**
     * @throws RequestException
     */
    private function post(string $endpoint, array $body): array
    {
        $url = self::BASE_URL . $endpoint;

        $response = wp_remote_post($url, [
            'headers' => array_merge($this->headers(), [
                'Content-Type' => 'application/json',
            ]),
            'body'    => wp_json_encode($body),
            'timeout' => self::TIMEOUT,
        ]);

        return $this->handleResponse($response, 'POST', $endpoint);
    }

    /**
     * Build default request headers.
     */
    private function headers(): array
    {
        $apiKey = get_option('logictrade_api_key', '');

        return [
            'api-key' => $apiKey,
            'Accept'  => 'application/json',
        ];
    }

    /**
     * Validate the WP HTTP response and decode JSON.
     *
     * @param array|\WP_Error $response
     * @throws RequestException
     */
    private function handleResponse($response, string $method, string $endpoint): array
    {
        if (is_wp_error($response)) {
            $message = sprintf('[%s %s] WP HTTP Error: %s', $method, $endpoint, $response->get_error_message());
            $this->logger->error($message);
            throw new RequestException($message);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            $message = sprintf('[%s %s] HTTP %d: %s', $method, $endpoint, $code, $body);
            $this->logger->error($message);
            throw new RequestException($message, $code, $body);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $message = sprintf('[%s %s] Invalid JSON response.', $method, $endpoint);
            $this->logger->error($message);
            throw new RequestException($message, $code, $body);
        }

        return $decoded;
    }

    /**
     * Check whether a valid API key is configured.
     */
    public function isConfigured(): bool
    {
        return !empty(get_option('logictrade_api_key', ''));
    }
}
