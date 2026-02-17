<?php

declare(strict_types=1);

namespace LogicTradeSync\Sync;

use LogicTradeSync\Api\LogicTradeClient;
use LogicTradeSync\Api\RequestException;
use LogicTradeSync\Database\OrderMappingRepository;
use LogicTradeSync\Database\SyncLogRepository;
use LogicTradeSync\Utils\Logger;

/**
 * Exports WooCommerce orders to LogicTrade via POST /orders.
 *
 * Resolves (or creates) a LogicTrade customer before exporting the order,
 * since the API requires a customer reference.
 */
final class OrderExport
{
    private LogicTradeClient $client;
    private OrderMappingRepository $orderMappingRepo;
    private SyncLogRepository $syncLogRepo;
    private Logger $logger;

    public function __construct(
        LogicTradeClient $client,
        OrderMappingRepository $orderMappingRepo,
        SyncLogRepository $syncLogRepo,
        Logger $logger
    ) {
        $this->client           = $client;
        $this->orderMappingRepo = $orderMappingRepo;
        $this->syncLogRepo      = $syncLogRepo;
        $this->logger           = $logger;
    }

    /**
     * Hook callback: auto-export when order status transitions to processing.
     */
    public function onOrderProcessing(int $orderId): void
    {
        if ($this->orderMappingRepo->isExported($orderId)) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        if (!$order->is_paid()) {
            return;
        }

        $this->exportOrder($orderId);
    }

    /**
     * Export a single WooCommerce order to LogicTrade.
     *
     * @return array{success: bool, message: string, logictrade_order_id?: string}
     */
    public function exportOrder(int $orderId): array
    {
        if (!$this->client->isConfigured()) {
            return ['success' => false, 'message' => __('API key not configured.', 'logictrade-sync')];
        }

        if (empty(get_option('logictrade_salesman_username', ''))) {
            return ['success' => false, 'message' => __('SalesMan Username is not configured. Go to LogicTrade Sync → Settings.', 'logictrade-sync')];
        }

        if ($this->orderMappingRepo->isExported($orderId)) {
            return ['success' => false, 'message' => __('Order has already been exported.', 'logictrade-sync')];
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return ['success' => false, 'message' => __('Order not found.', 'logictrade-sync')];
        }

        try {
            // 1. Resolve or create customer in LogicTrade.
            $customerNumber = $this->resolveCustomer($order);

            // 2. Build and send order payload.
            $payload  = $this->buildPayload($order, $customerNumber);
            $response = $this->client->createOrder($payload);

            $ltOrderId = (string) ($response['id'] ?? $response['number'] ?? '');

            $order->update_meta_data('_logictrade_order_id', $ltOrderId);
            $order->update_meta_data('_logictrade_order_exported', 'yes');
            $order->save();

            $this->orderMappingRepo->recordSuccess($orderId, $ltOrderId);
            $this->logger->success(
                sprintf('Order #%d exported to LogicTrade (LT ID: %s).', $orderId, $ltOrderId),
                'order_export'
            );

            return [
                'success'             => true,
                'message'             => sprintf(__('Order exported successfully. LogicTrade ID: %s', 'logictrade-sync'), $ltOrderId),
                'logictrade_order_id' => $ltOrderId,
            ];
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $this->orderMappingRepo->recordFailure($orderId, $errorMsg);
            $this->logger->error(sprintf('Order #%d export failed: %s', $orderId, $errorMsg), 'order_export');

            return ['success' => false, 'message' => $errorMsg];
        }
    }

    // ------------------------------------------------------------------
    // Customer resolution
    // ------------------------------------------------------------------

    /**
     * Find an existing LogicTrade customer by email, or create a new one.
     *
     * @return string The LogicTrade customer number.
     * @throws RequestException
     */
    private function resolveCustomer(\WC_Order $order): string
    {
        $email = $order->get_billing_email();

        // Check if we already resolved this customer before (stored on user meta or order meta).
        $cachedNumber = $order->get_meta('_logictrade_customer_number');
        if (!empty($cachedNumber)) {
            return $cachedNumber;
        }

        // Try to find by email.
        if (!empty($email)) {
            $found = $this->findCustomerByEmail($email);
            if ($found) {
                $order->update_meta_data('_logictrade_customer_number', $found);
                $order->save();
                return $found;
            }
        }

        // Try to find by name.
        $firstName = $order->get_billing_first_name();
        $lastName  = $order->get_billing_last_name();
        if (!empty($lastName)) {
            $found = $this->findCustomerByName($firstName, $lastName);
            if ($found) {
                $order->update_meta_data('_logictrade_customer_number', $found);
                $order->save();
                return $found;
            }
        }

        // Not found — create a new customer.
        $customerNumber = $this->createCustomerFromOrder($order);

        $order->update_meta_data('_logictrade_customer_number', $customerNumber);
        $order->save();

        $this->logger->info(
            sprintf('Created new LogicTrade customer %s for %s.', $customerNumber, $email ?: "$firstName $lastName"),
            'order_export'
        );

        return $customerNumber;
    }

    /**
     * Search LogicTrade customers by email.
     *
     * @return string|null Customer number if found, null otherwise.
     */
    private function findCustomerByEmail(string $email): ?string
    {
        try {
            $response  = $this->client->getCustomers(1, 10, ['email' => $email]);
            $customers = $response['data'] ?? $response['results'] ?? $response;

            if (isset($response['data']) && is_array($response['data'])) {
                $customers = $response['data'];
            } elseif (isset($response['results']) && is_array($response['results'])) {
                $customers = $response['results'];
            }

            if (!empty($customers) && is_array($customers)) {
                foreach ($customers as $c) {
                    if (isset($c['number']) && !empty($c['number'])) {
                        return (string) $c['number'];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Customer search by email failed: ' . $e->getMessage(), 'order_export');
        }

        return null;
    }

    /**
     * Search LogicTrade customers by name.
     *
     * @return string|null Customer number if found, null otherwise.
     */
    private function findCustomerByName(string $firstName, string $lastName): ?string
    {
        try {
            $response  = $this->client->getCustomers(1, 10, ['name' => $lastName]);
            $customers = $response['data'] ?? $response['results'] ?? $response;

            if (isset($response['data']) && is_array($response['data'])) {
                $customers = $response['data'];
            } elseif (isset($response['results']) && is_array($response['results'])) {
                $customers = $response['results'];
            }

            if (!empty($customers) && is_array($customers)) {
                foreach ($customers as $c) {
                    $cFirst = $c['firstName'] ?? '';
                    $cLast  = $c['lastName'] ?? '';

                    if (
                        strcasecmp($cLast, $lastName) === 0 &&
                        (empty($firstName) || strcasecmp($cFirst, $firstName) === 0)
                    ) {
                        if (isset($c['number']) && !empty($c['number'])) {
                            return (string) $c['number'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Customer search by name failed: ' . $e->getMessage(), 'order_export');
        }

        return null;
    }

    /**
     * Create a new customer in LogicTrade from WooCommerce order data.
     *
     * @return string The customer number of the newly created customer.
     * @throws RequestException
     */
    private function createCustomerFromOrder(\WC_Order $order): string
    {
        $billing = $order->get_address('billing');

        $data = [
            'firstName'    => $order->get_billing_first_name(),
            'lastName'     => $order->get_billing_last_name(),
            'companyName'  => $order->get_billing_company(),
            'email'        => $order->get_billing_email(),
            'phoneNumber'  => $order->get_billing_phone(),
            'address'      => [
                'street'      => trim(($billing['address_1'] ?? '') . ' ' . ($billing['address_2'] ?? '')),
                'houseNumber' => '',
                'zipCode'     => $billing['postcode'] ?? '',
                'city'        => $billing['city'] ?? '',
                'country'     => $billing['country'] ?? '',
            ],
        ];

        $response = $this->client->createCustomer($data);

        $number = $response['number'] ?? $response['id'] ?? '';
        if (empty($number)) {
            throw new RequestException('Customer created but no number returned.');
        }

        return (string) $number;
    }

    // ------------------------------------------------------------------
    // Order payload
    // ------------------------------------------------------------------

    /**
     * Build the order payload for POST /orders.
     *
     * The API expects customer as a reference (number), delivery with a code,
     * and structured line objects.
     */
    private function buildPayload(\WC_Order $order, string $customerNumber): array
    {
        $shipping     = $order->get_address('shipping');
        $billing      = $order->get_address('billing');
        $deliveryAddr = !empty($shipping['address_1']) ? $shipping : $billing;

        // Build order lines.
        $lines      = [];
        $lineNumber = 1;
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $product     = $item->get_product();
            $productCode = '';

            if ($product) {
                $productCode = get_post_meta($product->get_id(), '_logictrade_code', true);
                if (empty($productCode)) {
                    $productCode = $product->get_sku();
                }
            }

            $lines[] = [
                'lineNumber'  => $lineNumber,
                'code'        => $productCode,
                'description' => $item->get_name(),
                'quantity'    => $item->get_quantity(),
                'partPrice'   => (float) $item->get_total() / max($item->get_quantity(), 1),
            ];
            $lineNumber++;
        }

        $payload = [
            'reference'        => (string) $order->get_id(),
            'webshopNumber'    => $order->get_order_number(),
            'salesManUserName' => get_option('logictrade_salesman_username', ''),
            'customer'         => [
                'number' => $customerNumber,
            ],
            'delivery'         => [
                'code'    => get_option('logictrade_delivery_type_code', ''),
                'address' => [
                    'street'      => trim(($deliveryAddr['address_1'] ?? '') . ' ' . ($deliveryAddr['address_2'] ?? '')),
                    'houseNumber' => '',
                    'zipCode'     => $deliveryAddr['postcode'] ?? '',
                    'city'        => $deliveryAddr['city'] ?? '',
                    'country'     => $deliveryAddr['country'] ?? '',
                ],
            ],
            'lines'            => $lines,
        ];

        // Comment as object with intern/extern keys.
        $customerNote = $order->get_customer_note();
        if (!empty($customerNote)) {
            $payload['comment'] = [
                'intern' => '',
                'extern' => $customerNote,
            ];
        }

        return $payload;
    }
}
