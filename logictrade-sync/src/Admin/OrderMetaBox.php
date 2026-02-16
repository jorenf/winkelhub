<?php

declare(strict_types=1);

namespace LogicTradeSync\Admin;

use LogicTradeSync\Sync\OrderExport;

/**
 * Adds a "Export to LogicTrade" meta box / button on WooCommerce order edit screens.
 */
final class OrderMetaBox
{
    private OrderExport $orderExport;

    public function __construct(OrderExport $orderExport)
    {
        $this->orderExport = $orderExport;
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
    }

    public function addMetaBox(): void
    {
        $screen = $this->getOrderScreen();

        add_meta_box(
            'logictrade_order_export',
            __('LogicTrade Export', 'logictrade-sync'),
            [$this, 'render'],
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Determine the correct screen ID for WooCommerce orders.
     * Supports both legacy (post type) and HPOS (wc-orders) screens.
     */
    private function getOrderScreen(): string
    {
        if (class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)) {
            $controller = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class);
            if ($controller && method_exists($controller, 'custom_orders_table_usage_is_enabled') && $controller->custom_orders_table_usage_is_enabled()) {
                return wc_get_page_screen_id('shop-order');
            }
        }

        return 'shop_order';
    }

    /**
     * Render the meta box content.
     *
     * @param \WP_Post|\WC_Order $postOrOrder
     */
    public function render($postOrOrder): void
    {
        $orderId = $postOrOrder instanceof \WC_Order
            ? $postOrOrder->get_id()
            : (int) $postOrOrder->ID;

        $order = wc_get_order($orderId);
        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'logictrade-sync') . '</p>';
            return;
        }

        $exported   = $order->get_meta('_logictrade_order_exported') === 'yes';
        $ltOrderId  = $order->get_meta('_logictrade_order_id');

        if ($exported) {
            echo '<p style="color: #46b450;">';
            echo esc_html__('This order has been exported to LogicTrade.', 'logictrade-sync');
            echo '</p>';
            if ($ltOrderId) {
                echo '<p><strong>' . esc_html__('LogicTrade ID:', 'logictrade-sync') . '</strong> ' . esc_html($ltOrderId) . '</p>';
            }
            echo '<button type="button" class="button" disabled>' . esc_html__('Already Exported', 'logictrade-sync') . '</button>';
        } else {
            echo '<p>' . esc_html__('This order has not been exported yet.', 'logictrade-sync') . '</p>';
            printf(
                '<button type="button" class="button button-primary logictrade-export-order" data-order-id="%d">%s</button>',
                esc_attr($orderId),
                esc_html__('Export to LogicTrade', 'logictrade-sync')
            );
            echo '<div class="logictrade-export-result" style="margin-top:10px;"></div>';
        }
    }
}
