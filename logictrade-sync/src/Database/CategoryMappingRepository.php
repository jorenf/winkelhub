<?php

declare(strict_types=1);

namespace LogicTradeSync\Database;

/**
 * CRUD for LogicTrade → WooCommerce category mappings.
 */
final class CategoryMappingRepository
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'logictrade_category_mapping';
    }

    /**
     * Get all stored mappings.
     *
     * @return array<int, object>
     */
    public function getAll(): array
    {
        global $wpdb;

        return $wpdb->get_results("SELECT * FROM {$this->table()} ORDER BY logictrade_group_name ASC");
    }

    /**
     * Get mapping by LogicTrade group ID.
     */
    public function getByGroupId(int $groupId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE logictrade_group_id = %d",
            $groupId
        ));
    }

    /**
     * Get the WooCommerce category ID for a LogicTrade group, or 0 if unmapped.
     */
    public function getWcCategoryId(int $logictradeGroupId): int
    {
        global $wpdb;

        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT wc_category_id FROM {$this->table()} WHERE logictrade_group_id = %d",
            $logictradeGroupId
        ));

        return $val ? (int) $val : 0;
    }

    /**
     * Insert or update a single mapping.
     */
    public function upsert(int $logictradeGroupId, string $groupName, int $wcCategoryId): void
    {
        global $wpdb;

        $existing = $this->getByGroupId($logictradeGroupId);

        if ($existing) {
            $wpdb->update(
                $this->table(),
                [
                    'logictrade_group_name' => $groupName,
                    'wc_category_id'        => $wcCategoryId,
                ],
                ['logictrade_group_id' => $logictradeGroupId],
                ['%s', '%d'],
                ['%d']
            );
        } else {
            $wpdb->insert($this->table(), [
                'logictrade_group_id'   => $logictradeGroupId,
                'logictrade_group_name' => $groupName,
                'wc_category_id'        => $wcCategoryId,
            ], ['%d', '%s', '%d']);
        }
    }

    /**
     * Bulk-save mappings — replaces all existing entries.
     *
     * @param array<int, array{logictrade_group_id: int, logictrade_group_name: string, wc_category_id: int}> $mappings
     */
    public function saveAll(array $mappings): void
    {
        foreach ($mappings as $m) {
            $this->upsert(
                (int) $m['logictrade_group_id'],
                (string) $m['logictrade_group_name'],
                (int) $m['wc_category_id']
            );
        }
    }
}
