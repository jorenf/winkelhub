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
     *
     * @param int $orderId WooCommerce order ID.
     */
    public function onOrderProcessing(int $orderId): void
    {
        // Only export if not already exported.
        if ($this->orderMappingRepo->isExported($orderId)) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        // Ensure payment is complete (paid).
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
            return [
                'success' => false,
                'message' => __('API key not configured.', 'logictrade-sync'),
            ];
        }

        if (empty(get_option('logictrade_salesman_username', ''))) {
            return [
                'success' => false,
                'message' => __('SalesMan Username is not configured. Go to LogicTrade Sync → Settings.', 'logictrade-sync'),
            ];
        }

        // Prevent duplicate export.
        if ($this->orderMappingRepo->isExported($orderId)) {
            return [
                'success' => false,
                'message' => __('Order has already been exported.', 'logictrade-sync'),
            ];
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return [
                'success' => false,
                'message' => __('Order not found.', 'logictrade-sync'),
            ];
        }

        $payload = $this->buildPayload($order);

        try {
            $response = $this->client->createOrder($payload);

            $ltOrderId = (string) ($response['id'] ?? $response['orderId'] ?? $response['number'] ?? '');

            // Save meta on the order.
            $order->update_meta_data('_logictrade_order_id', $ltOrderId);
            $order->update_meta_data('_logictrade_order_exported', 'yes');
            $order->save();

            // Record in mapping table.
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
        } catch (RequestException $e) {
            $errorMsg = $e->getMessage();
            $this->orderMappingRepo->recordFailure($orderId, $errorMsg);
            $this->logger->error(
                sprintf('Order #%d export failed: %s', $orderId, $errorMsg),
                'order_export'
            );

            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $this->orderMappingRepo->recordFailure($orderId, $errorMsg);
            $this->logger->error(
                sprintf('Order #%d export unexpected error: %s', $orderId, $errorMsg),
                'order_export'
            );

            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        }
    }

    /**
     * Build the order payload for POST /orders.
     *
     * The API requires structured customer, delivery, and lines objects,
     * plus a salesManUserName field.
     */
    private function buildPayload(\WC_Order $order): array
    {
        $billing  = $order->get_address('billing');
        $shipping = $order->get_address('shipping');

        // Prefer shipping address for delivery, fall back to billing.
        $deliveryAddr = !empty($shipping['address_1']) ? $shipping : $billing;

        // Build order lines.
        $lines = [];
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
                'name'       => $order->get_formatted_billing_full_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address'    => trim(($billing['address_1'] ?? '') . ' ' . ($billing['address_2'] ?? '')),
                'city'       => $billing['city'] ?? '',
                'postalCode' => $billing['postcode'] ?? '',
                'country'    => $billing['country'] ?? '',
            ],
            'delivery'         => [
                'name'       => trim(($deliveryAddr['first_name'] ?? '') . ' ' . ($deliveryAddr['last_name'] ?? '')),
                'address'    => trim(($deliveryAddr['address_1'] ?? '') . ' ' . ($deliveryAddr['address_2'] ?? '')),
                'city'       => $deliveryAddr['city'] ?? '',
                'postalCode' => $deliveryAddr['postcode'] ?? '',
                'country'    => $deliveryAddr['country'] ?? '',
            ],
            'lines'            => $lines,
        ];

        // The API expects comment as an object with intern/extern keys, not a plain string.
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
