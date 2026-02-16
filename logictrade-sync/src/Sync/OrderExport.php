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
     */
    private function buildPayload(\WC_Order $order): array
    {
        $billingAddress  = $order->get_address('billing');
        $shippingAddress = $order->get_address('shipping');

        // Prefer shipping address, fall back to billing.
        $address = !empty($shippingAddress['address_1']) ? $shippingAddress : $billingAddress;

        $lines = [];
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
                'productCode' => $productCode,
                'quantity'    => $item->get_quantity(),
                'price'       => (float) $item->get_total() / max($item->get_quantity(), 1),
                'description' => $item->get_name(),
            ];
        }

        return [
            'reference'      => (string) $order->get_id(),
            'webshopNumber'  => $order->get_order_number(),
            'customerName'   => $order->get_formatted_billing_full_name(),
            'email'          => $order->get_billing_email(),
            'phone'          => $order->get_billing_phone(),
            'address'        => trim(($address['address_1'] ?? '') . ' ' . ($address['address_2'] ?? '')),
            'city'           => $address['city'] ?? '',
            'postalCode'     => $address['postcode'] ?? '',
            'country'        => $address['country'] ?? '',
            'comment'        => $order->get_customer_note(),
            'orderLines'     => $lines,
        ];
    }
}
