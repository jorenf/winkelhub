<?php

declare(strict_types=1);

namespace LogicTradeSync\Sync;

use LogicTradeSync\Api\LogicTradeClient;
use LogicTradeSync\Api\RequestException;
use LogicTradeSync\Database\CategoryMappingRepository;
use LogicTradeSync\Database\SyncLogRepository;
use LogicTradeSync\Database\SyncStateRepository;
use LogicTradeSync\Utils\Logger;

/**
 * Orchestrates the full product sync from LogicTrade into WooCommerce.
 *
 * Fetches products, prices, and stock in paginated batches, merges data
 * by LogicTrade product ID, and upserts into WooCommerce.
 */
final class ProductSync
{
    private const BATCH_SIZE = 100;

    private LogicTradeClient $client;
    private CategoryMappingRepository $categoryMappingRepo;
    private SyncLogRepository $syncLogRepo;
    private SyncStateRepository $syncStateRepo;
    private Logger $logger;

    public function __construct(
        LogicTradeClient $client,
        CategoryMappingRepository $categoryMappingRepo,
        SyncLogRepository $syncLogRepo,
        SyncStateRepository $syncStateRepo,
        Logger $logger
    ) {
        $this->client              = $client;
        $this->categoryMappingRepo = $categoryMappingRepo;
        $this->syncLogRepo         = $syncLogRepo;
        $this->syncStateRepo       = $syncStateRepo;
        $this->logger              = $logger;
    }

    /**
     * Execute a full product sync.
     *
     * @return array{success: bool, created: int, updated: int, errors: int, message: string}
     */
    public function run(): array
    {
        if (!$this->client->isConfigured()) {
            return [
                'success' => false,
                'created' => 0,
                'updated' => 0,
                'errors'  => 0,
                'message' => __('API key not configured.', 'logictrade-sync'),
            ];
        }

        // Acquire lock to prevent concurrent syncs.
        if (!$this->syncStateRepo->acquireLock()) {
            return [
                'success' => false,
                'created' => 0,
                'updated' => 0,
                'errors'  => 0,
                'message' => __('A sync is already in progress.', 'logictrade-sync'),
            ];
        }

        $this->logger->info('Product sync started.', 'product_sync');

        $created = 0;
        $updated = 0;
        $errors  = 0;

        try {
            // 1. Fetch all prices indexed by product ID (non-fatal if endpoint unavailable).
            $priceMap = $this->fetchAllPricesGracefully();

            // 2. Fetch all stock indexed by product ID (non-fatal if endpoint unavailable).
            $stockMap = $this->fetchAllStockGracefully();

            // 3. Paginate through products and upsert.
            $page       = 1;
            $totalPages = 1;

            do {
                $response   = $this->client->getProducts($page, self::BATCH_SIZE);
                $totalPages = $response['totalPages'] ?? $totalPages;
                $products   = $this->extractItems($response);

                if (empty($products)) {
                    break;
                }

                foreach ($products as $product) {
                    try {
                        $productId = $product['id'] ?? null;
                        if (!$productId) {
                            continue;
                        }

                        // Merge price data.
                        $priceData = $priceMap[$productId] ?? [];
                        // Merge stock data.
                        $stockData = $stockMap[$productId] ?? [];

                        $merged = array_merge($product, $priceData, $stockData);

                        $result = $this->upsertProduct($merged);
                        if ($result === 'created') {
                            $created++;
                        } elseif ($result === 'updated') {
                            $updated++;
                        }
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->logger->error(
                            sprintf('Failed to sync product %s: %s', $product['code'] ?? $productId ?? '?', $e->getMessage()),
                            'product_sync'
                        );
                    }
                }

                // Free memory between pages.
                unset($products);

                $page++;
            } while ($page <= $totalPages);
        } catch (RequestException $e) {
            $this->logger->error('Product sync API error: ' . $e->getMessage(), 'product_sync');
            $this->syncStateRepo->releaseLock();

            return [
                'success' => false,
                'created' => $created,
                'updated' => $updated,
                'errors'  => $errors + 1,
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Product sync unexpected error: ' . $e->getMessage(), 'product_sync');
            $this->syncStateRepo->releaseLock();

            return [
                'success' => false,
                'created' => $created,
                'updated' => $updated,
                'errors'  => $errors + 1,
                'message' => $e->getMessage(),
            ];
        }

        // Record completion.
        $this->syncStateRepo->setLastSyncTime(current_time('mysql'));
        $this->syncStateRepo->setLastSyncProductCount($created + $updated);
        $this->syncStateRepo->releaseLock();

        $message = sprintf(
            __('Sync completed: %d created, %d updated, %d errors.', 'logictrade-sync'),
            $created,
            $updated,
            $errors
        );
        $this->logger->success($message, 'product_sync');

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
            'message' => $message,
        ];
    }

    // ------------------------------------------------------------------
    // Data fetching helpers
    // ------------------------------------------------------------------

    /**
     * Fetch all price records, indexed by product ID.
     * Returns empty array if the endpoint is unavailable.
     *
     * @return array<int|string, array>
     */
    private function fetchAllPricesGracefully(): array
    {
        try {
            return $this->fetchPaginatedMap(function (int $page, int $size) {
                return $this->client->getPrices($page, $size);
            });
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Prices endpoint unavailable — prices from product data will be used. ' . $e->getMessage(),
                'product_sync'
            );
            return [];
        }
    }

    /**
     * Fetch all stock records, indexed by product ID.
     * Returns empty array if the endpoint is unavailable.
     *
     * @return array<int|string, array>
     */
    private function fetchAllStockGracefully(): array
    {
        try {
            return $this->fetchPaginatedMap(function (int $page, int $size) {
                return $this->client->getStock($page, $size);
            });
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Stock endpoint unavailable — stock from product data will be used. ' . $e->getMessage(),
                'product_sync'
            );
            return [];
        }
    }

    /**
     * Generic paginated fetcher that indexes results by product ID.
     *
     * @param callable(int, int): array $fetcher
     * @return array<int|string, array>
     */
    private function fetchPaginatedMap(callable $fetcher): array
    {
        $map        = [];
        $page       = 1;
        $totalPages = 1;

        do {
            $response = $fetcher($page, self::BATCH_SIZE);

            // Empty response means endpoint returned no data.
            if (empty($response)) {
                break;
            }

            $items      = $this->extractItems($response);
            $totalPages = $response['totalPages'] ?? $totalPages;

            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $pid = $item['productId'] ?? $item['id'] ?? null;
                if ($pid !== null) {
                    $map[$pid] = $item;
                }
            }

            unset($items);
            $page++;
        } while ($page <= $totalPages);

        return $map;
    }

    /**
     * Extract the item array from various API response shapes.
     *
     * @return array<int, array>
     */
    private function extractItems(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }
        if (isset($response['results']) && is_array($response['results'])) {
            return $response['results'];
        }
        // If the response itself looks like a flat list of items.
        if (isset($response[0]) && is_array($response[0])) {
            return $response;
        }
        return [];
    }

    // ------------------------------------------------------------------
    // WooCommerce product upsert
    // ------------------------------------------------------------------

    /**
     * Create or update a WooCommerce product from merged LogicTrade data.
     *
     * @return string 'created' | 'updated'
     */
    private function upsertProduct(array $data): string
    {
        $code      = $data['code'] ?? '';
        $existingId = $this->findProductByCode($code);

        if ($existingId) {
            $this->updateProduct($existingId, $data);
            return 'updated';
        }

        $this->createProduct($data);
        return 'created';
    }

    /**
     * Find an existing WooCommerce product by LogicTrade code.
     */
    private function findProductByCode(string $code): int
    {
        if (empty($code)) {
            return 0;
        }

        global $wpdb;

        $productId = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_logictrade_code' AND meta_value = %s
             LIMIT 1",
            $code
        ));

        return $productId ? (int) $productId : 0;
    }

    /**
     * Create a new WooCommerce simple product.
     */
    private function createProduct(array $data): void
    {
        $product = new \WC_Product_Simple();
        $this->applyProductData($product, $data);
        $product->save();
    }

    /**
     * Update an existing WooCommerce product.
     */
    private function updateProduct(int $productId, array $data): void
    {
        $product = wc_get_product($productId);
        if (!$product) {
            return;
        }

        $this->applyProductData($product, $data);
        $product->save();
    }

    /**
     * Map LogicTrade fields to WooCommerce product fields.
     */
    private function applyProductData(\WC_Product $product, array $data): void
    {
        // Name → post_title
        if (!empty($data['name'])) {
            $product->set_name($data['name']);
        }

        // Description → post_content
        if (isset($data['description'])) {
            $product->set_description($data['description']);
        }

        // Barcode → SKU
        if (!empty($data['barcode'])) {
            $product->set_sku($data['barcode']);
        }

        // Price incl. VAT → _price and _regular_price
        $price = $data['priceInclVat'] ?? $data['salesPrice'] ?? null;
        if ($price !== null) {
            $product->set_regular_price((string) $price);
            $product->set_price((string) $price);
        }

        // Stock → _stock
        $stock = $data['availableQuantity'] ?? $data['quantity'] ?? null;
        if ($stock !== null) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $stock);
            $product->set_stock_status((int) $stock > 0 ? 'instock' : 'outofstock');
        }

        // Ensure the product is published.
        $product->set_status('publish');

        // Save the product first to ensure we have an ID.
        $product->save();

        $productId = $product->get_id();

        // LogicTrade code.
        if (!empty($data['code'])) {
            update_post_meta($productId, '_logictrade_code', sanitize_text_field($data['code']));
            update_post_meta($productId, '_logictrade_article_number', sanitize_text_field($data['code']));
        }

        // Purchase price.
        if (isset($data['purchasePrice'])) {
            update_post_meta($productId, '_purchase_price', sanitize_text_field((string) $data['purchasePrice']));
        }

        // Supplier name.
        if (!empty($data['supplierName'])) {
            update_post_meta($productId, '_logictrade_supplier', sanitize_text_field($data['supplierName']));
        }

        // Category mapping via groupId.
        $groupId = $data['groupId'] ?? $data['salesGroup'] ?? null;
        if ($groupId) {
            $wcCategoryId = $this->categoryMappingRepo->getWcCategoryId((int) $groupId);
            if ($wcCategoryId > 0) {
                wp_set_object_terms($productId, [$wcCategoryId], 'product_cat');
            }
        }
    }
}
