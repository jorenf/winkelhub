<?php

declare(strict_types=1);

namespace LogicTradeSync\Admin\Pages;

use LogicTradeSync\Api\LogicTradeClient;
use LogicTradeSync\Database\CategoryMappingRepository;

/**
 * Admin page for mapping LogicTrade product groups to WooCommerce categories.
 */
final class CategoryMappingPage
{
    private CategoryMappingRepository $repo;
    private LogicTradeClient $client;

    public function __construct(CategoryMappingRepository $repo, LogicTradeClient $client)
    {
        $this->repo   = $repo;
        $this->client = $client;
    }

    public function render(): void
    {
        $savedMappings = $this->repo->getAll();
        $savedMap      = [];
        foreach ($savedMappings as $m) {
            $savedMap[(int) $m->logictrade_group_id] = (int) $m->wc_category_id;
        }

        // Attempt to load LogicTrade groups.
        $ltGroups = [];
        $fetchError = '';
        if ($this->client->isConfigured()) {
            try {
                $response = $this->client->getCategories();
                // Handle various response shapes.
                $ltGroups = $response['data'] ?? $response['results'] ?? $response;
                if (!is_array($ltGroups)) {
                    $ltGroups = [];
                }
            } catch (\Throwable $e) {
                $fetchError = $e->getMessage();
            }
        } else {
            $fetchError = __('API key not configured.', 'logictrade-sync');
        }

        // Get WooCommerce product categories.
        $wcCategories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($wcCategories)) {
            $wcCategories = [];
        }

        ?>
        <div class="wrap logictrade-wrap">
            <h1><?php esc_html_e('LogicTrade Sync — Category Mapping', 'logictrade-sync'); ?></h1>

            <?php if ($fetchError): ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html($fetchError); ?></p>
                </div>
            <?php endif; ?>

            <?php if (empty($ltGroups) && !$fetchError): ?>
                <div class="notice notice-info inline">
                    <p><?php esc_html_e('No product groups found in LogicTrade.', 'logictrade-sync'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($ltGroups)): ?>
                <p><?php esc_html_e('Map each LogicTrade product group to a WooCommerce category. Unmapped groups will be skipped during sync.', 'logictrade-sync'); ?></p>

                <form id="logictrade-category-mapping-form">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('LogicTrade Group', 'logictrade-sync'); ?></th>
                                <th><?php esc_html_e('WooCommerce Category', 'logictrade-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ltGroups as $group):
                            $groupId   = (int) ($group['id'] ?? 0);
                            $groupName = $group['name'] ?? $group['description'] ?? ('Group #' . $groupId);
                            $groupCode = $group['code'] ?? '';
                            $selected  = $savedMap[$groupId] ?? 0;
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($groupName); ?></strong>
                                    <?php if ($groupCode): ?>
                                        <br><small><?php echo esc_html($groupCode); ?></small>
                                    <?php endif; ?>
                                    <input type="hidden"
                                           name="mappings[<?php echo esc_attr($groupId); ?>][logictrade_group_id]"
                                           value="<?php echo esc_attr($groupId); ?>">
                                    <input type="hidden"
                                           name="mappings[<?php echo esc_attr($groupId); ?>][logictrade_group_name]"
                                           value="<?php echo esc_attr($groupName); ?>">
                                </td>
                                <td>
                                    <select name="mappings[<?php echo esc_attr($groupId); ?>][wc_category_id]"
                                            class="logictrade-select">
                                        <option value="0"><?php esc_html_e('— Not Mapped —', 'logictrade-sync'); ?></option>
                                        <?php foreach ($wcCategories as $cat): ?>
                                            <option value="<?php echo esc_attr($cat->term_id); ?>"
                                                    <?php selected($selected, $cat->term_id); ?>>
                                                <?php echo esc_html($cat->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary" id="logictrade-save-mappings">
                            <?php esc_html_e('Save Mappings', 'logictrade-sync'); ?>
                        </button>
                        <span id="logictrade-mapping-spinner" class="spinner" style="float: none;"></span>
                    </p>
                </form>

                <div id="logictrade-mapping-result" style="margin-top: 10px;"></div>
            <?php endif; ?>
        </div>
        <?php
    }
}
