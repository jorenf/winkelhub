<?php

declare(strict_types=1);

namespace LogicTradeSync\Admin\Pages;

/**
 * Settings page — stores LogicTrade API Key.
 */
final class SettingsPage
{
    public function render(): void
    {
        // Handle form submission.
        if (isset($_POST['logictrade_settings_nonce']) && wp_verify_nonce($_POST['logictrade_settings_nonce'], 'logictrade_save_settings')) {
            $apiKey   = sanitize_text_field($_POST['logictrade_api_key'] ?? '');
            $salesMan = sanitize_text_field($_POST['logictrade_salesman_username'] ?? '');
            update_option('logictrade_api_key', $apiKey);
            update_option('logictrade_salesman_username', $salesMan);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'logictrade-sync') . '</p></div>';
        }

        $currentKey      = get_option('logictrade_api_key', '');
        $currentSalesMan = get_option('logictrade_salesman_username', '');
        ?>
        <div class="wrap logictrade-wrap">
            <h1><?php esc_html_e('LogicTrade Sync — Settings', 'logictrade-sync'); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field('logictrade_save_settings', 'logictrade_settings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="logictrade_api_key"><?php esc_html_e('LogicTrade API Key', 'logictrade-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   name="logictrade_api_key"
                                   id="logictrade_api_key"
                                   value="<?php echo esc_attr($currentKey); ?>"
                                   class="regular-text"
                                   autocomplete="off">
                            <p class="description">
                                <?php esc_html_e('Enter the API key from your LogicTrade account. This is required for all sync operations.', 'logictrade-sync'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="logictrade_salesman_username"><?php esc_html_e('SalesMan Username', 'logictrade-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   name="logictrade_salesman_username"
                                   id="logictrade_salesman_username"
                                   value="<?php echo esc_attr($currentSalesMan); ?>"
                                   class="regular-text"
                                   autocomplete="off">
                            <p class="description">
                                <?php esc_html_e('The LogicTrade username of the salesperson assigned to webshop orders. Required for order export.', 'logictrade-sync'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'logictrade-sync')); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Connection Test', 'logictrade-sync'); ?></h2>
            <p><?php esc_html_e('After saving your API key, use the button below to verify the connection.', 'logictrade-sync'); ?></p>
            <button type="button" id="logictrade-test-connection" class="button button-secondary">
                <?php esc_html_e('Test Connection', 'logictrade-sync'); ?>
            </button>
            <span id="logictrade-test-spinner" class="spinner" style="float: none;"></span>
            <div id="logictrade-test-result" style="margin-top: 10px;"></div>
        </div>
        <?php
    }
}
