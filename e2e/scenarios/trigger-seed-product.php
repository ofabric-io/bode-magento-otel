<?php

declare(strict_types=1);

/**
 * One-shot bootstrap process (same shape as trigger-cron-fail-seed.php): seeds what the entity scenarios browse, on a
 * Magento that has no sample data. A category "E2E Gear" (url /e2e-gear.html) holding one simple product "e2e-widget"
 * (url /e2e-widget.html), in stock. Idempotent: running it twice changes nothing.
 *
 * Seeding goes through the repositories, so the category, product and URL-rewrite saves are real EAV / resource model
 * work, in the compiled-DI process. Not covered: indexes are not rebuilt (product and category pages read the entity
 * tables, which is what the entity scenarios are about; the category listing may be empty of products).
 */

require '/magento/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

/** @var \Magento\Framework\App\State $appState */
$appState = $objectManager->get(\Magento\Framework\App\State::class);
$appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
/** @var \Magento\Framework\App\ObjectManager\ConfigLoader $configLoader */
$configLoader = $objectManager->get(\Magento\Framework\App\ObjectManager\ConfigLoader::class);
$objectManager->configure($configLoader->load(\Magento\Framework\App\Area::AREA_ADMINHTML));

$storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
$storeManager->setCurrentStore(\Magento\Store\Model\Store::ADMIN_CODE);

// Category.
$categoryFactory = $objectManager->get(\Magento\Catalog\Model\CategoryFactory::class);
$categoryResource = $objectManager->get(\Magento\Catalog\Model\ResourceModel\Category::class);
$existing = $categoryFactory->create()->getCollection()->addAttributeToFilter('url_key', 'e2e-gear')->getFirstItem();
if ($existing->getId()) {
    $categoryId = (int) $existing->getId();
} else {
    /** @var \Magento\Catalog\Model\Category $category */
    $category = $categoryFactory->create();
    $category->setName('E2E Gear')->setUrlKey('e2e-gear')->setIsActive(true)->setIsAnchor(true)
        ->setParentId(2)->setPath('1/2')->setStoreId(0);
    $categoryResource->save($category);
    $categoryId = (int) $category->getId();
}
echo "seed-product: category id $categoryId\n";

// Product.
$productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
try {
    $productRepository->get('e2e-widget');
    echo "seed-product: product e2e-widget exists\n";
} catch (\Magento\Framework\Exception\NoSuchEntityException) {
    /** @var \Magento\Catalog\Model\Product $product */
    $product = $objectManager->create(\Magento\Catalog\Model\Product::class);
    $product->setTypeId('simple')->setAttributeSetId(4)->setSku('e2e-widget')->setName('E2E Widget Secret Name')
        ->setUrlKey('e2e-widget')->setPrice(9.99)->setVisibility(4)->setStatus(1)->setWeight(1)
        ->setWebsiteIds([1])->setCategoryIds([$categoryId])
        ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1]);
    $productRepository->save($product);
    echo "seed-product: created product e2e-widget\n";
}
