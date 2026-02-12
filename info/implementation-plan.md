# Implementation Plan: Transform Magmodules_Channable into Girofeeds_ProductApi

## Context

The existing codebase is the `Magmodules_Channable` Magento 2 module — a full marketplace integration with Channable that includes product feeds, order import, returns, item update webhooks, custom payment/carrier, and admin grids.

The goal is to transform this codebase into `Girofeeds_ProductApi` — a focused module that provides:

1. **Product Feed Export** (GET endpoint returning JSON product data)
2. **Product Update** (POST endpoint that receives field updates from the Girofeeds app)
3. **Product Count** (GET endpoint returning total product count)
4. **Connection Test** (GET endpoint returning module info and supported fields)

Everything related to orders, returns, item update webhooks (push to Channable), custom payment method, custom carrier, and marketplace-specific admin grids must be removed.
 
---

## Step 1: Global Rename — Namespace, Module Name, and Config Paths

**Goal:** Replace all references from `Magmodules\Channable` / `Magmodules_Channable` to `Girofeeds\ProductApi` / `Girofeeds_ProductApi` across the entire codebase.

### 1.1 PHP Namespace and Module References

Replace in ALL `.php` files:

| Find | Replace |
|------|---------|
| `Magmodules\Channable` | `Girofeeds\ProductApi` |
| `Magmodules_Channable` | `Girofeeds_ProductApi` |
| `magmodules/magento2-channable` | `girofeeds/module-product-api` |

This affects:
- `namespace` declarations
- `use` statements
- String references (e.g., in `registration.php`, DI configs)
- Class doc comments

### 1.2 XML Configuration Paths

Replace in ALL `.xml` files:

| Find | Replace |
|------|---------|
| `Magmodules\Channable` | `Girofeeds\ProductApi` |
| `Magmodules_Channable` | `Girofeeds_ProductApi` |
| `magmodules_channable` | `girofeeds_productapi` |

This affects:
- `etc/module.xml` — module name
- `etc/di.xml` — all type/preference declarations
- `etc/adminhtml/system.xml` — section IDs
- `etc/adminhtml/system/*.xml` — all group/field paths
- `etc/config.xml` — default values paths
- `etc/acl.xml` — resource IDs
- `etc/adminhtml/menu.xml` — menu item IDs
- `etc/adminhtml/routes.xml` — route frontName
- `etc/frontend/routes.xml` — route frontName
- `etc/events.xml`, `etc/adminhtml/events.xml`, `etc/frontend/events.xml`
- `etc/crontab.xml`
- All layout XML files in `view/`
- All UI component XML files in `view/`

### 1.3 XPATH Constants in PHP

Update ALL XPATH constants in Helper and Config classes. The pattern is:

| Find | Replace |
|------|---------|
| `magmodules_channable/` | `girofeeds_productapi/` |

Files with XPATH constants:
- `Helper/Source.php` — ~50 constants
- `Helper/General.php`
- `Helper/Feed.php`
- `Helper/Item.php`
- `Helper/Preview.php`
- `Model/Config/System/BaseRepository.php`
- `Model/Config/System/FeedRepository.php`
- `Model/Config/System/ItemupdateRepository.php`
- `Model/Config/System/OrderRepository.php`
- `Model/Config/System/ReturnsRepository.php`
- `Model/Config/Repository.php`

### 1.4 Route FrontNames

- `etc/frontend/routes.xml`: change frontName from `channable` to `girofeeds`
- `etc/adminhtml/routes.xml`: change frontName from `channable` to `girofeeds`

### 1.5 Module Metadata

- `etc/module.xml`: name=`Girofeeds_ProductApi`, remove setup_version (use composer version)
- `composer.json`: update name, description, type, require (PHP >=8.1), autoload PSR-4 path
- `registration.php`: register `Girofeeds_ProductApi`

### Files affected: ALL files in the project (~160 files)

---

## Step 2: Remove Order, Returns, and Marketplace Functionality

**Goal:** Delete all files related to order import, returns processing, item update webhooks (push to Channable), custom payment method, custom carrier, transaction fees, and marketplace-specific admin grids.

### 2.1 Delete Order Files

```
Api/Order/                                    (entire directory - 3 files)
Model/Order/                                  (entire directory - 4 files)
Service/Order/                                (entire directory - 16 files)
Controller/Order/                             (entire directory - 4 files)
Controller/Adminhtml/Order/                   (entire directory - 4 files)
Ui/Component/Listing/Column/Order/            (entire directory - 8 files)
Ui/Component/OrderDataProvider.php
Block/Adminhtml/Order/                        (entire directory - 4 files)
Block/Adminhtml/System/Config/Form/Table/Orders.php
Api/Config/System/OrderInterface.php
Model/Config/System/OrderRepository.php
Exceptions/CouldNotImportOrder.php
Console/Command/OrderSimulate.php
Service/Test/OrderImport.php
```

### 2.2 Delete Returns Files

```
Api/Returns/                                  (entire directory - 3 files)
Model/Returns/                                (entire directory - 5 files)
Service/Returns/                              (entire directory - 10 files)
Controller/Returns/                           (entire directory - 2 files)
Controller/Adminhtml/Returns/                 (entire directory - 7 files)
Ui/Component/Listing/Column/Returns/          (entire directory - 6 files)
Block/Adminhtml/System/Config/Form/Table/Returns.php
Api/Config/System/ReturnsInterface.php
Model/Config/System/ReturnsRepository.php
```

### 2.3 Delete Item Update Webhook Files (Push to Channable)

```
Model/Item.php
Model/ResourceModel/Item.php
Model/ResourceModel/Item/                     (entire directory)
Cron/ItemUpdate.php
Cron/ItemCleanup.php
Controller/Item/                              (entire directory)
Controller/Adminhtml/Item/                    (entire directory - 6 files)
Ui/Component/Listing/Column/Item/             (entire directory)
Helper/Item.php
Service/ItemUpdate/                           (entire directory)
Block/Adminhtml/System/Config/Form/Table/Itemupdates.php
Api/Config/System/ItemupdateInterface.php
Model/Config/System/ItemupdateRepository.php
Service/Test/ItemUpdate.php
Service/Test/Cron.php
Console/Command/ItemUpdate.php
```

### 2.4 Delete Payment, Carrier, and Transaction Fee Files

```
Model/Payment/Channable.php
Model/Carrier/Channable.php
Block/Info/Channable.php
Model/Total/TransactionFee.php
Model/Total/TransactionFeeCreditmemo.php
Model/Total/TransactionFeeInvoice.php
Plugin/AddDiscountToInvoice.php
```

### 2.5 Delete LVB/Marketplace Plugins

```
Plugin/AfterCheckQty.php
Plugin/AfterGetBackorders.php
Plugin/AfterGetShippingDescription.php
Plugin/AfterStockItemConfiguration.php
Plugin/AfterValidateMinimumAmount.php
Plugin/AroundIsAllowedRequest.php
Plugin/AroundIsAnySourceItemInStockCondition.php
Plugin/AroundIsSalableWithReservationsCondition.php
Plugin/AroundQuantityValidator.php
Plugin/AroundSourceDeductionService.php
Plugin/CreditmemoSaveAfter.php
```

### 2.6 Delete Observers Not Needed

```
Observer/Checkout/AllSubmitAfter.php
Observer/Sales/OrderItemCancel.php
Observer/Sales/OrderPlaceAfter.php
Observer/Sales/QuoteSubmitBefore.php
Observer/SalesRule/ValidatorProcess.php
Observer/Xtento/InvalidateProducts.php
```

### 2.7 Delete Admin Config System Files for Marketplace

```
etc/adminhtml/system/order.xml
etc/adminhtml/system/returns.xml
etc/adminhtml/system/itemupdates.xml
etc/adminhtml/system/payment.xml
etc/adminhtml/system/carriers.xml
```

### 2.8 Delete Marketplace-Specific Config Sources

```
Model/Config/Source/Channels.php
Model/Config/Source/CustomerGroups.php
Model/Config/Source/ReturnLabel.php
Model/Config/Source/ShippingMethods.php
Model/Config/Source/Status.php
Model/Config/Backend/Serialized/ReturnLabel.php
Model/Config/Backend/Serialized/DeliveryTime.php
Model/System/Config/Source/ReturnsStatus.php
Model/System/Config/Source/ShippingMethods.php
```

### 2.9 Delete UI/View Files for Removed Features

```
view/adminhtml/layout/channable_item_index.xml
view/adminhtml/layout/channable_order_index.xml
view/adminhtml/layout/channable_returns_index.xml
view/adminhtml/layout/sales_order_creditmemo_new.xml
view/adminhtml/layout/sales_order_creditmemo_updateqty.xml
view/adminhtml/layout/sales_order_creditmemo_view.xml
view/adminhtml/layout/sales_order_invoice_view.xml
view/adminhtml/layout/sales_order_view.xml
view/adminhtml/ui_component/channable_item_grid.xml
view/adminhtml/ui_component/channable_order_grid.xml
view/adminhtml/ui_component/channable_returns_grid.xml
view/adminhtml/ui_component/sales_order_grid.xml
view/adminhtml/ui_component/catalogstaging_category_update_form.xml
view/adminhtml/templates/info/channable_payment.phtml
view/adminhtml/templates/info/pdf/channable_payment.phtml
view/adminhtml/templates/order/                (entire directory)
view/adminhtml/templates/system/config/fieldset/table/  (entire directory)
view/adminhtml/web/js/order-table-tooltip.js
view/adminhtml/web/js/show-more.js
view/adminhtml/web/template/grid/order_import_status.html
view/frontend/templates/info/channable_payment.phtml
```

### 2.10 Delete Version Check and Selftest (marketplace-specific)

```
Controller/Adminhtml/VersionCheck/            (entire directory - 2 files)
Controller/Adminhtml/Selftest/Index.php
Block/Adminhtml/System/Config/Button/VersionCheck.php
Block/Adminhtml/Design/Version.php
view/adminhtml/templates/system/config/button/version.phtml
```

### 2.11 Delete Other Files Not Needed

```
Helper/Preview.php
Controller/Feed/Preview.php
Service/Test/ExtensionVersion.php
Service/Test/ExtensionStatus.php
Service/Test/PhpExtensions.php
Service/Test/PhpVersion.php
Service/Test/MagentoVersion.php
Service/Test/ProductFeed.php
Api/Selftest/RepositoryInterface.php
Model/Selftest/Repository.php
```

### 2.12 Estimated deletions: ~120 files

---

## Step 3: Clean Up XML Configuration Files

**Goal:** Rewrite all XML config files to remove references to deleted classes and restructure for Girofeeds.

### 3.1 `etc/db_schema.xml` — Remove All Custom Tables

Replace the entire file content. Remove:
- `channable_orders` table
- `channable_items` table
- `channable_returns` table
- `transaction_fee` column from `quote`, `sales_order`, `sales_invoice`, `sales_creditmemo`
- `channable_pickup_location` column from `sales_order`
- `channable_id`, `channel_id`, `channel_name`, `channel_label` columns from `sales_order_grid`

Add (optional, for API request logging):
- `girofeeds_api_log` table with columns: `entity_id`, `timestamp`, `ip_address`, `endpoint`, `api_key_hash`, `response_status`, `execution_time_ms`

Update `etc/db_schema_whitelist.json` accordingly.

### 3.2 `etc/di.xml` — Rewrite Dependency Injection

Remove ALL entries for deleted classes (orders, returns, items, plugins, carriers, payment, observers, console commands, UI data providers).

Keep and update:
- Config repository preference
- Logger virtual types and handlers (renamed to Girofeeds)
- CSRF validator plugin (adapted for new routes)
- Console command for token generation

Add new entries:
- `FeedInterface` -> `Model\Feed`
- `UpdateProductInterface` -> `Model\UpdateProduct`
- `CountInterface` -> `Model\Count`
- `TestInterface` -> `Model\Test`

### 3.3 `etc/config.xml` — Rewrite Default Values

Replace entire content. New structure under `girofeeds_productapi/`:

```xml
<default>
  <girofeeds_productapi>
    <general>
      <enable>1</enable>
      <limit>100</limit>
      <add_disabled>0</add_disabled>
      <stock>1</stock>
    </general>
    <data>
      <name_attribute>name</name_attribute>
      <description_attribute>description</description_attribute>
      <short_description_attribute>short_description</short_description_attribute>
      <brand_attribute>manufacturer</brand_attribute>
      <ean_attribute/>
      <color_attribute>color</color_attribute>
      <size_attribute>size</size_attribute>
      <material_attribute>material</material_attribute>
      <gender_attribute>gender</gender_attribute>
      <image>all</image>
      <main_image>image</main_image>
      <hidden_images>0</hidden_images>
      <sku_attribute>sku</sku_attribute>
    </data>
    <advanced>
      <tax>1</tax>
      <tax_include_both>1</tax_include_both>
      <inventory>1</inventory>
      <inventory_fields>min_sale_qty,backorders</inventory_fields>
    </advanced>
    <types>
      <configurable>simple</configurable>
      <configurable_link>1</configurable_link>
      <configurable_image>2</configurable_image>
      <bundle>parent</bundle>
      <grouped>parent</grouped>
    </types>
    <filter>
      <visbility_enabled>1</visbility_enabled>
      <visbility>2,4</visbility>
      <stock>0</stock>
    </filter>
  </girofeeds_productapi>
</default>
```

### 3.4 `etc/events.xml` — Simplify

Remove ALL observer registrations for orders, checkout, sales, xtento. Keep only:
- `Observer/Catalog/ProductSaveAfter.php` (if keeping product change tracking)
- `Observer/CatalogInventory/StockItemSaveAfter.php` (if keeping stock change tracking)
- `Observer/Controller/RemoveCsp.php`

Or remove all observers if none are needed for the API-only module.

### 3.5 `etc/adminhtml/events.xml` — Simplify

Remove all order/sales grid observer registrations.

### 3.6 `etc/frontend/events.xml` — Simplify

Remove CSP observer if not needed, or keep adapted.

### 3.7 `etc/crontab.xml` — Remove Entirely

No cron jobs are needed. Delete the file.

### 3.8 `etc/payment.xml` — Delete

No custom payment method.

### 3.9 `etc/sales.xml` — Delete

No sales integration.

### 3.10 `etc/pdf.xml` — Delete

No PDF customization.

### 3.11 `etc/extension_attributes.xml` — Delete

No extension attributes on orders.

### 3.12 `etc/fieldset.xml` — Delete

No fieldset mappings for orders.

### 3.13 `etc/acl.xml` — Simplify

Remove all order/return/item ACL resources. Keep only:
- `Girofeeds_ProductApi::config` — access to module configuration

### 3.14 `etc/adminhtml/menu.xml` — Simplify

Remove all menu items for order/return/item grids. Keep only a single link to the configuration page if desired, or remove entirely.

### 3.15 `etc/adminhtml/routes.xml` — Update

Change frontName to `girofeeds`.

### 3.16 `etc/frontend/routes.xml` — Update

Change frontName to `girofeeds`.

### 3.17 `etc/frontend/di.xml` — Update

Remove deleted class references.

---

## Step 4: Restructure Admin Configuration (system.xml)

**Goal:** Rewrite the admin system configuration to match the Girofeeds specification exactly.

### 4.1 `etc/adminhtml/system.xml` — Main File

Rewrite to define a single tab `girofeeds` and a single section `girofeeds_productapi` that includes the subsystem files.

### 4.2 `etc/adminhtml/system/general.xml` — Rewrite

New fields under `girofeeds_productapi/general`:

| Field ID | Type | Default | Label |
|----------|------|---------|-------|
| `enable` | select (Yes/No) | Yes | Enable Module |
| `api_key` | text (obscured) | auto-generated | API Key |
| `limit` | text (number) | 100 | Products Per Page |
| `add_disabled` | select (Yes/No) | No | Include Disabled Products |
| `stock` | select (Yes/No) | Yes | Include Out of Stock |

The `api_key` field should show a button to regenerate and display current value.

### 4.3 `etc/adminhtml/system/feed.xml` — Adapt Existing

Restructure groups to match specification:

**Group: `data` (Product Data Settings)**

Keep existing fields, add `short_description_attribute`:
- `name_attribute` — dropdown of text attributes
- `description_attribute` — dropdown of text attributes
- `short_description_attribute` — NEW, dropdown of text attributes, default `short_description`
- `brand_attribute` — dropdown
- `ean_attribute` — dropdown
- `color_attribute` — dropdown
- `size_attribute` — dropdown
- `material_attribute` — dropdown
- `gender_attribute` — dropdown

**Group: `images` (Image Settings)**

Keep existing:
- `image` — Image Source (All Images / Main Image)
- `main_image` — Main Image Role (image / small_image / thumbnail)
- `hidden_images` — Include Hidden Images (Yes/No)

**Group: `price` (Price Settings)**

Simplify from existing:
- `tax` — Tax Calculation: Including Tax / Excluding Tax / Store Default
- `tax_include_both` — NEW: Include Both Tax Variants (Yes/No), default Yes

**Group: `types` (Configurable Product Settings)**

Keep existing with simplified labels:
- `configurable` — Export Type: Parent Only / Simple Products Only / Both
- `configurable_link` — Use Parent URL for Simples (Yes/No)
- `configurable_image` — Use Parent Image for Simples: Always / Only if Empty / Never
- `configurable_parent_atts` — Parent Data Fallback Fields (multiselect)
- Same for bundle and grouped

**Group: `extra_fields` (Extra Attribute Mappings)**

Keep existing dynamic rows field (`Block/Adminhtml/System/Config/Form/Field/ExtraFields.php`):
- Magento Attribute (dropdown) → Feed Field Name (text input)

**Group: `filter` (Filter Settings)**

Keep existing:
- `visbility_enabled` — Filter by Visibility (Yes/No)
- `visbility` — Allowed Visibility (multiselect)
- `category_enabled` — Filter by Category (Yes/No)
- `category_type` — Category Filter Type: Include / Exclude
- `category` — Categories (multiselect)
- `filters` — Advanced Filters (Yes/No)
- `filters_data` — Filter Conditions (dynamic rows)

### 4.4 Source Models to Keep

These provide dropdown options in admin config and must be kept:

```
Model/System/Config/Source/Attributes.php          (product attribute list)
Model/System/Config/Source/CategoryList.php         (category tree)
Model/System/Config/Source/CategoryTypeList.php     (include/exclude)
Model/System/Config/Source/Conditions.php           (filter conditions)
Model/System/Config/Source/Country.php              (country list)
Model/System/Config/Source/ImageSource.php          (all/single)
Model/System/Config/Source/MainImage.php            (image roles)
Model/System/Config/Source/ParentAttributes.php     (parent attribute fallback)
Model/System/Config/Source/ProductCondition.php     (new/used/refurbished)
Model/System/Config/Source/ProductTypes.php         (product type filter)
Model/System/Config/Source/ProductVisibility.php    (visibility options)
Model/System/Config/Source/SourceType.php           (source type)
Model/System/Config/Source/Status.php               (enable/disable - only if generic)
Model/System/Config/Source/Tax.php                  (tax options)
Model/System/Config/Source/Inventory.php            (inventory fields)
Model/System/Config/Source/InvalidationModus.php    (remove if only for item updates)
Model/System/Config/Source/Configurable/*.php       (configurable product options)
Model/System/Config/Source/Bundle/*.php             (bundle product options)
Model/System/Config/Source/Grouped/*.php            (grouped product options)
```

### 4.5 Block Classes to Keep

```
Block/Adminhtml/Design/Header.php              (header branding — update branding)
Block/Adminhtml/Design/Heading.php             (section heading)
Block/Adminhtml/System/Config/Form/Field/ExtraFields.php     (extra attribute mappings)
Block/Adminhtml/System/Config/Form/Field/Filters.php         (advanced filters)
Block/Adminhtml/System/Config/Form/Field/Renderer/Attributes.php
Block/Adminhtml/System/Config/Form/Field/Renderer/Conditions.php
Block/Adminhtml/System/Config/Form/Field/Renderer/Countries.php
Block/Adminhtml/System/Config/Form/Field/Renderer/ProductTypes.php
Block/Adminhtml/System/Config/Form/Feeds.php   (feeds overview — adapt or remove)
```

Delete unused blocks:
```
Block/Adminhtml/Design/Log.php
Block/Adminhtml/System/Config/Button/Log.php
Block/Adminhtml/System/Config/Button/Selftest.php
Block/Adminhtml/System/Config/Form/Field/AdvancedShipmentMapping.php
Block/Adminhtml/System/Config/Form/Field/DeliveryTime.php
Block/Adminhtml/System/Config/Form/Field/ReturnLabel.php
Block/Adminhtml/System/Config/Form/Field/Renderer/Actions.php
Block/Adminhtml/System/Config/Form/Field/Renderer/Carriers.php
Block/Adminhtml/System/Config/Form/Field/Renderer/Channels.php
Block/Adminhtml/System/Config/Form/Field/Renderer/ShippingMethods.php
```

---

## Step 5: Adapt Feed Generation to Girofeeds Specification

**Goal:** Modify the existing feed generation logic to output the exact JSON format defined in the specification.

### 5.1 Adapt `Controller/Feed/Json.php`

This is the main feed endpoint controller. Changes needed:

- **Authentication:** Change from URL-path token (`/token/ABC123`) to query parameter `key=ABC123`. Compare against stored API key in config path `girofeeds_productapi/general/api_key`.
- **Pagination:** Change from `page=N` (with config limit) to `limit=offset,count` format. Parse as `$offset,$count = explode(',', $request->getParam('limit', '0,100'))`.
- **Store:** Change from `id=storeId` (numeric) to `store=storeCode` (string). Use `StoreManagerInterface` to resolve code to store ID.
- **Response format:** Return the flat array of product objects directly (not wrapped in summary/config).
- **Content-Type:** Ensure `application/json` response header.

### 5.2 Adapt `Model/Generate.php` → Rename to `Model/Feed.php`

This class orchestrates feed generation. Key changes:

- Implement `Api\FeedInterface`
- Accept parameters: `key`, `limit` (as "offset,count" string), `store` (code)
- Simplify `generateByStore()` to accept offset/count instead of page number
- Remove references to `ItemModel` (deleted)
- Remove the summary/header section of the response
- Pass collection to product processor, return flat array

### 5.3 Adapt `Helper/Source.php` → Feed Config Provider

This class builds the attribute map and filter config. Key changes:

- Update ALL ~50 XPATH constants to use `girofeeds_productapi/` prefix
- Add new attributes to the `getAttributes()` method:
  - `short_description` — new field from configured attribute
  - `meta_title`, `meta_description`, `meta_keyword` — new fields from standard attributes
  - `url_key` — from `url_key` attribute
  - `cost` — from `cost` attribute
  - `condition` — from `condition` attribute
  - `country_of_origin` — from `country_of_manufacture` attribute
  - `tax_class_id` — from `tax_class_id` attribute
- Adapt `reformatData()` for new category format:
  - `category` → single deepest path string with ` > ` separator
  - `categories` → array of all path strings
  - `category_ids` → array of integer IDs
- Adapt image output to use `image_link` (single string) and `additional_images` (array) instead of `additional_imagelinks`

### 5.4 Adapt `Helper/Product.php` → Product Data Collector

Key changes to `getDataRow()`:

- Map output fields to exact specification names:
  - `id` (entity_id as string)
  - `parent_id` (empty string if no parent)
  - `type_id`
  - `sku`
  - `name`, `description`, `short_description`
  - `meta_title`, `meta_description`, `meta_keyword`
  - `url`, `url_key`
  - `status` (as string "1" or "2")
  - `visibility` (as string "1"-"4")
  - `active` (as string "1" or "0", derived from status)
- Ensure all fields from specification are present in output even if empty/null

### 5.5 Adapt `Service/Product/PriceData.php`

Change output format to specification:

```
price              → base price (excl tax by default)
special_price      → special/sale price
price_incl_tax     → price including tax
price_excl_tax     → price excluding tax
special_price_incl_tax
special_price_excl_tax
cost               → product cost
tax_rate           → applicable tax rate percentage
tax_class_id       → tax class ID
currency           → store currency code
```

Always include both incl/excl tax variants (the specification requires it).

### 5.6 Adapt `Service/Product/InventoryData.php`

Ensure output includes ALL stock fields:

```
qty                → integer quantity
is_in_stock        → "1" or "0"
manage_stock       → "1" or "0"
min_sale_qty       → integer
max_sale_qty       → integer (0 = unlimited)
backorders         → "0", "1", or "2"
```

### 5.7 Adapt `Service/Category/CategoryData.php`

Change category output format:

```
category           → "Clothing > Men > T-Shirts" (deepest path, single string)
categories         → ["Clothing", "Clothing > Men", "Clothing > Men > T-Shirts"] (array of all paths)
category_ids       → [3, 5, 12] (array of integer IDs)
```

Use ` > ` (space-arrow-space) as hierarchy separator.

### 5.8 Adapt `Service/Product/MediaData.php`

Change image output format:

```
image_link         → single URL string (main image)
additional_images  → array of URL strings (gallery minus main)
```

### 5.9 Adapt `Model/Collection/Products.php`

Minimal changes — this class already handles collection building with filters well. Only need:
- Update class references after namespace rename
- Ensure pagination works with offset/count (the collection already supports `setPageSize()` and `setCurPage()` but may need `getSelect()->limit($count, $offset)` for offset-based pagination)

### 5.10 Remove Unused Product Services

Delete:
```
Service/Product/CustomOptions.php
Service/Product/TierPriceData.php
```

---

## Step 6: Create Product Update Endpoint (NEW)

**Goal:** Build the complete product update pipeline — the main new functionality that doesn't exist in the Channable module.

### 6.1 Create `Api/UpdateProductInterface.php`

```php
namespace Girofeeds\ProductApi\Api;

interface UpdateProductInterface
{
    /**
     * @return string JSON response
     */
    public function execute(): string;
}
```

### 6.2 Create `Model/Update/FieldProcessor.php`

Handles alias resolution and value conversion:

**Alias Map:**
```php
private array $aliasMap = [
    'stock'          => 'qty',
    'reference'      => 'sku',
    'ean13'          => 'ean',
    'specific_price' => 'special_price',
    'active'         => 'status',
    'visible'        => 'visibility',
    'image'          => 'image_link',
    'manufacturer'   => 'brand',
];
```

**Priority Rules** (when multiple aliases are present, first found in priority list wins):
```php
private array $priorityRules = [
    'qty'           => ['qty', 'stock'],
    'sku'           => ['sku', 'reference'],
    'ean'           => ['ean', 'ean13', 'gtin'],
    'status'        => ['status', 'active'],
    'visibility'    => ['visibility', 'visible'],
    'special_price' => ['special_price', 'specific_price'],
    'image_link'    => ['image_link', 'image'],
];
```

**Value Conversions:**
- `active` (boolean/string) → `status` (1=Enabled, 2=Disabled)
- `visible` (boolean/string) → `visibility` (4=Catalog,Search or 1=Not Visible)
- `special_price` null/0/"" → null (removes special price)

Method: `resolveFields(array $data): array` — returns canonicalized field=>value pairs.

### 6.3 Create `Model/Update/ValidationService.php`

Validates resolved fields before processing:

| Field | Validation |
|-------|-----------|
| `price` | Must be numeric and > 0 |
| `visibility` | Must be integer 1-4 |
| `status` | Must be 1 or 2 (or boolean equivalent) |
| `qty` | Must be numeric and >= 0 |
| `weight` | Must be numeric and >= 0 |
| `url_key` | Must match `/^[a-z0-9][a-z0-9-]*$/` |
| `backorders` | Must be 0, 1, or 2 |
| `min_sale_qty` | Must be >= 1 |

Returns array of error messages. Empty array = valid.

### 6.4 Create `Model/Update/AttributeUpdater.php`

Handles EAV attribute updates including select/dropdown resolution:

**Method:** `updateSelectAttribute(ProductInterface $product, string $attributeCode, string $textValue): ?string`

- Load attribute via `EavConfig::getAttribute('catalog_product', $attributeCode)`
- If frontend_input is NOT `select`, set value directly
- If frontend_input IS `select`, find option_id by label (case-insensitive match)
- If option not found, return warning string with available options list
- If found, set option_id on product

### 6.5 Create `Model/Update/PriceUpdater.php`

**Method:** `updatePrices(ProductInterface $product, array $resolvedData): array`

- Update `price` if present (validate > 0)
- Update `special_price` if present (null/0 removes it)
- If setting special_price and no `special_from_date` exists, set it to now
- Update `cost` if present
- Return array of warning messages

### 6.6 Create `Model/Update/StockUpdater.php`

**Method:** `updateStock(ProductInterface $product, array $resolvedData): array`

Uses `StockRegistryInterface`:
- Update `qty` if present, auto-set `is_in_stock` based on qty > 0 if not explicitly provided
- Update `is_in_stock`, `manage_stock`, `min_sale_qty`, `max_sale_qty`, `backorders`
- Save via `StockRegistryInterface::updateStockItemBySku()`
- Return array of warning messages

### 6.7 Create `Model/Update/ImageUpdater.php`

**Method:** `updateImages(ProductInterface $product, array $resolvedData): array`

Three image source types:
1. **Local Magento URL** (starts with store media URL) — skip, already present
2. **Remote URL** — download via curl (timeout 15s, follow redirects, max 10MB, validate MIME)
3. **Base64 data URL** (`data:image/webp;base64,...`) — decode, validate, save to tmp

For main image (`image_link`): assign roles `['image', 'small_image', 'thumbnail']`
For additional images: add to gallery without roles

Save downloaded/decoded files to Magento's tmp directory, then use `$product->addImageToMediaGallery()`.

### 6.8 Create `Model/Update/CategoryUpdater.php`

**Method:** `updateCategories(ProductInterface $product, array $resolvedData): array`

Two modes:
1. **`category_ids`** (priority) — validate each ID exists via `CategoryRepositoryInterface::get()`, set `$product->setCategoryIds($validIds)` replacing all
2. **`category`** (fallback) — parse path "Clothing > Men > T-Shirts", find matching category by name of last segment, add to existing categories without replacing

Return array of warning messages.

### 6.9 Create `Model/UpdateProduct.php`

Main orchestrator implementing `UpdateProductInterface`:

**Method:** `execute(): string`

Flow:
1. Read request body JSON and extract `id_product` and `store` query param
2. Load product by entity_id via `ProductRepositoryInterface::getById()`
3. If `store` param present, set store ID on product for store-specific saves
4. Call `FieldProcessor::resolveFields()` on request data
5. Call `ValidationService::validate()` — if errors, return error response
6. Process text attributes (name, description, short_description, meta_*, url_key, condition, country_of_origin)
7. Process numeric attributes (weight, length, width, height)
8. Process select/dropdown attributes via `AttributeUpdater` (color, size, material, gender, brand)
9. Process identifier fields (sku, ean)
10. Process status and visibility (with value conversion)
11. Process custom mapped attributes (reverse mapping from Extra Attribute Mappings config)
12. Process prices via `PriceUpdater`
13. Save product via `ProductRepositoryInterface::save()`
14. Process stock via `StockUpdater` (after product save)
15. Process categories via `CategoryUpdater` (re-save if needed)
16. Process images via `ImageUpdater`
17. Build and return JSON response with status, message, updated_fields, warnings, failed_fields, product_id

**Response format:** See specification section "Response Format" for success, partial success, and error responses.

### 6.10 Create `Controller/Update/Index.php`

Frontend controller for `POST /girofeeds/updateproduct`:
- Validate API key from `key` query parameter
- Delegate to `UpdateProductInterface::execute()`
- Return JSON response

---

## Step 7: Create Count and Test Endpoints

**Goal:** Implement the two auxiliary endpoints.

### 7.1 Create `Api/CountInterface.php`

```php
interface CountInterface
{
    /**
     * @return string JSON response with product count
     */
    public function getCount(): string;
}
```

### 7.2 Create `Model/Count.php`

Implements `CountInterface`:

- Read `store` query param, resolve to store ID
- Build product collection with same filters as feed (reuse `Helper/Source::getProductFilters()` and `Model/Collection/Products::getCollection()`)
- Use `Products::getCollectionCountWithFilters()` to get total count
- Return `{"count": N}`

### 7.3 Create `Controller/Count/Index.php`

Frontend controller for `GET /girofeeds/count`:
- Validate API key
- Delegate to `CountInterface::getCount()`
- Return JSON response

### 7.4 Create `Api/TestInterface.php`

```php
interface TestInterface
{
    /**
     * @return string JSON response with module info
     */
    public function test(): string;
}
```

### 7.5 Create `Model/Test.php`

Implements `TestInterface`:

- Return JSON with:
  - `status`: "success"
  - `version`: module version from `composer.json` (e.g., "2.0.0")
  - `magento_version`: from `ProductMetadataInterface::getVersion()`
  - `store`: current store code
  - `product_count`: total products (reuse Count logic)
  - `module_name`: "Girofeeds_ProductApi"
  - `supported_fields`: hardcoded array of all supported update fields per specification
  - `extra_mapped_fields`: dynamic array from Extra Attribute Mappings config (the feed field names)

### 7.6 Create `Controller/Test/Index.php`

Frontend controller for `GET /girofeeds/test`:
- Validate API key
- Delegate to `TestInterface::test()`
- Return JSON response

### 7.7 Create `etc/webapi.xml`

Register all 4 REST API routes:

```xml
<route url="/V1/girofeeds/feed" method="GET">
    <service class="Girofeeds\ProductApi\Api\FeedInterface" method="getProducts"/>
    <resources><resource ref="anonymous"/></resources>
</route>
<route url="/V1/girofeeds/updateproduct" method="POST">
    <service class="Girofeeds\ProductApi\Api\UpdateProductInterface" method="execute"/>
    <resources><resource ref="anonymous"/></resources>
</route>
<route url="/V1/girofeeds/count" method="GET">
    <service class="Girofeeds\ProductApi\Api\CountInterface" method="getCount"/>
    <resources><resource ref="anonymous"/></resources>
</route>
<route url="/V1/girofeeds/test" method="GET">
    <service class="Girofeeds\ProductApi\Api\TestInterface" method="test"/>
    <resources><resource ref="anonymous"/></resources>
</route>
```

Note: Routes use `anonymous` resource because authentication is handled via the `key` query parameter, not Magento's OAuth/Bearer token system.

---

## Step 8: Security, Logging, and Final Cleanup

**Goal:** Implement cross-cutting concerns and ensure the module is clean and ready for deployment.

### 8.1 API Key Validation

Create `Model/ApiKeyValidator.php`:

- Read stored API key from `girofeeds_productapi/general/api_key` config
- Compare with `key` query parameter using `hash_equals()` for timing-safe comparison
- If module is disabled (`girofeeds_productapi/general/enable` = 0), reject all requests
- Return 401 JSON response if key is missing or invalid

Use this validator in all 4 frontend controllers and in all 4 webapi implementations.

### 8.2 Rate Limiting

Create `Model/RateLimiter.php`:

- Use Magento's `CacheInterface` to store request counts per API key hash
- Cache key format: `girofeeds_rate_{sha256_first8chars_of_key}`
- TTL: 60 seconds
- Max requests: configurable, default 60 per minute
- Return 429 Too Many Requests if exceeded

### 8.3 API Request Logging

Create `Model/ApiLogger.php`:

- Log each API request with: timestamp, client IP, endpoint, API key hash (first 8 chars only), HTTP status, execution time in ms
- Write to `var/log/girofeeds.log` using the module's custom logger
- Log unknown fields received in update endpoint for debugging

### 8.4 Adapt CSRF Validator Plugin

Update `Plugin/AroundCsrfValidator.php`:

- Skip CSRF validation for the 4 Girofeeds frontend controller routes:
  - `girofeeds/feed/index`
  - `girofeeds/update/index`
  - `girofeeds/count/index`
  - `girofeeds/test/index`

### 8.5 Adapt Logger Infrastructure

Update logger files:
- `Logger/Handler/Error.php` — log to `var/log/girofeeds_error.log`
- `Logger/Handler/Debug.php` — log to `var/log/girofeeds_debug.log`
- Update virtual type names in `di.xml`

### 8.6 Update Token Generation

Adapt `Service/Token/Generate.php` and `Setup/Patch/Data/CreateToken.php`:
- Generate 32-character cryptographically random API key
- Store in `girofeeds_productapi/general/api_key` config path
- Use `random_bytes(16)` + `bin2hex()` for generation

Adapt `Console/Command/GenerateToken.php`:
- Update command name to `girofeeds:token:generate`
- Update config path

### 8.7 Adapt Selftest Console Command

Simplify `Console/Command/Selftest.php` to:
- Verify module is enabled
- Verify API key is set
- Verify product collection can be built
- Update command name to `girofeeds:selftest`

### 8.8 Clean Up Remaining Files

- Delete `Model/Base/Metadata.php` if only used by orders
- Delete `Model/Config/Backend/Cron.php` (no cron)
- Simplify `Model/Config/Repository.php` — keep only getters needed for feed and update
- Update `Model/Config/System/FeedRepository.php` — adapt to new XPATH paths
- Delete `Model/Config/System/BaseRepository.php` if no longer needed as base class
- Delete or simplify `Api/Log/RepositoryInterface.php` and `Model/Log/Repository.php`

### 8.9 Update composer.json

```json
{
    "name": "girofeeds/module-product-api",
    "description": "Girofeeds Product API - Product feed export and update endpoints for Magento 2",
    "type": "magento2-module",
    "version": "2.0.0",
    "license": "proprietary",
    "require": {
        "php": ">=8.1",
        "magento/framework": ">=103.0.0",
        "magento/module-catalog": "*",
        "magento/module-catalog-inventory": "*",
        "magento/module-store": "*",
        "magento/module-tax": "*",
        "magento/module-eav": "*"
    },
    "autoload": {
        "files": ["registration.php"],
        "psr-4": {
            "Girofeeds\\ProductApi\\": ""
        }
    }
}
```

### 8.10 View/Template Cleanup

Delete all remaining view templates and assets that reference removed functionality:

```
view/adminhtml/templates/system/config/button/log.phtml      (if Log button removed)
view/adminhtml/templates/system/config/button/selftest.phtml  (if Selftest button removed)
view/adminhtml/templates/system/config/fieldset/feeds.phtml   (adapt or remove)
view/adminhtml/templates/system/config/fieldset/header.phtml  (update branding)
view/adminhtml/web/images/channable-logo.png                  (replace with Girofeeds logo or remove)
view/adminhtml/web/js/button-functions.js                     (adapt or remove)
view/adminhtml/web/css/                                       (adapt references)
```

### 8.11 Update i18n

Update `i18n/en_US.csv` — remove all translation strings for orders, returns, items, and add any new strings for Girofeeds.

### 8.12 Final Verification Checklist

- [ ] No references to `Magmodules` or `Channable` remain in any file
- [ ] No references to `channable_orders`, `channable_items`, `channable_returns` tables
- [ ] No references to deleted classes in `di.xml`
- [ ] No references to deleted observers in `events.xml`
- [ ] No references to deleted routes in layout XML
- [ ] All XPATH constants use `girofeeds_productapi/` prefix
- [ ] `registration.php` registers `Girofeeds_ProductApi`
- [ ] `etc/module.xml` declares `Girofeeds_ProductApi`
- [ ] All 4 API endpoints work: feed, updateproduct, count, test
- [ ] API key validation works on all endpoints
- [ ] Feed output matches specification JSON format exactly
- [ ] Update endpoint handles all field types from specification
- [ ] Update endpoint handles alias resolution correctly
- [ ] Update endpoint returns proper success/warning/error responses
- [ ] Rate limiting works
- [ ] Logging works
- [ ] CSRF bypass works for frontend controller routes

---

## Summary of File Changes

| Action | Count | Description |
|--------|-------|-------------|
| **Delete** | ~120 files | Orders, returns, items, payment, carrier, plugins, observers, views |
| **Rename/Adapt** | ~35 files | Namespace changes, XPATH updates, output format changes |
| **Create New** | ~15 files | Update endpoint (8 files), Count/Test (4 files), security (3 files) |
| **Final total** | ~50 files | Clean, focused module |

---

## Execution Order

Steps MUST be executed in order. Each step builds on the previous:

1. **Step 1** — Rename everything (makes subsequent edits consistent)
2. **Step 2** — Delete files (reduces codebase to only needed code)
3. **Step 3** — Fix XML configs (ensures DI, routes, schema are correct for remaining code)
4. **Step 4** — Admin config (provides the configuration layer the feed and update rely on)
5. **Step 5** — Adapt feed (core read functionality, built on adapted configs)
6. **Step 6** — Create update endpoint (core write functionality, the main new feature)
7. **Step 7** — Create count and test endpoints (auxiliary, depend on feed infrastructure)
8. **Step 8** — Security, logging, cleanup (cross-cutting, depends on everything else being in place)
