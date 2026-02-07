# Magento 2 Extension Specification: Product Feed & Update API

## Overview

Custom Magento 2 extension that exposes REST API endpoints for product data feed export and product update. Designed to be compatible with the Girofeeds AI Feed Editor application, following the same URL patterns and data structure as the Channable PrestaShop module.

**Base reference:** [magmodules/magento2-channable](https://github.com/magmodules/magento2-channable)

The extension should be built as a standalone Magento 2 module (not a fork of magmodules/magento2-channable) but can use it as architectural reference for feed generation patterns, product type handling (configurable/bundle/grouped), and filter logic.

---

## Module Structure

```
app/code/Girofeeds/ProductApi/
  registration.php
  etc/
    module.xml
    di.xml
    webapi.xml
    adminhtml/
      system.xml
      routes.xml
  Api/
    FeedInterface.php
    UpdateProductInterface.php
  Model/
    Feed.php
    UpdateProduct.php
    Config.php
    Feed/
      ProductDataCollector.php
      ImageCollector.php
      CategoryCollector.php
      PriceCollector.php
      StockCollector.php
      MetaDataCollector.php
      AttributeMapper.php
    Update/
      FieldProcessor.php
      AttributeUpdater.php
      StockUpdater.php
      PriceUpdater.php
      ImageUpdater.php
      CategoryUpdater.php
      MetaUpdater.php
      ValidationService.php
  Controller/
    Adminhtml/
      Config/
        Index.php
  Block/
    Adminhtml/
      Config/
        Form.php
  Plugin/
    ProductSaveAfter.php (optional: webhook on product save)
  Setup/
    InstallSchema.php
    UpgradeSchema.php
```

**Module name:** `Girofeeds_ProductApi`
**Namespace:** `Girofeeds\ProductApi`

---

## Configuration (Admin Panel)

Located at: **Stores > Configuration > Girofeeds > Product API**

### General Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Enable Module | Yes/No | Yes | Enable/disable the API endpoints |
| API Key | Text (auto-generated) | Random 32 chars | Authentication key for API access |
| Products Per Page | Number | 100 | Max products returned per request |
| Include Disabled Products | Yes/No | No | Whether to include disabled products in the feed |
| Include Out of Stock | Yes/No | Yes | Whether to include out-of-stock products |

### Product Data Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Name Attribute | Dropdown | `name` | Attribute used for product name |
| Description Attribute | Dropdown | `description` | Attribute for full description |
| Short Description Attribute | Dropdown | `short_description` | Attribute for short description |
| Brand Attribute | Dropdown | `manufacturer` | Attribute for brand/manufacturer |
| EAN/GTIN Attribute | Dropdown | (auto-detect) | Attribute containing EAN/GTIN barcode |
| Color Attribute | Dropdown | `color` | Attribute for color |
| Size Attribute | Dropdown | `size` | Attribute for size |
| Material Attribute | Dropdown | `material` | Attribute for material |
| Gender Attribute | Dropdown | `gender` | Attribute for gender |

### Image Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Image Source | Dropdown | All Images | Main Image / All Images |
| Main Image Role | Dropdown | image | image / small_image / thumbnail |
| Include Hidden Images | Yes/No | No | Include images marked as hidden |

### Price Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Tax Calculation | Dropdown | Including Tax | Including Tax / Excluding Tax / Both |
| Use Special Price | Yes/No | Yes | Include special/sale price if available |

### Configurable Product Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Export Type | Dropdown | Simple Products Only | Parent Only / Simple Products Only / Both |
| Use Parent URL for Simples | Yes/No | Yes | Use configurable product URL for simple children |
| Use Parent Image for Simples | Dropdown | Only if Empty | Always / Only if Empty / Never |
| Parent Data Fallback Fields | Multiselect | description | Fields to inherit from parent if empty on simple |

### Extra Attribute Mappings

Dynamic rows allowing admin to map any Magento attribute to a custom field name:

| Magento Attribute | Feed Field Name |
|-------------------|-----------------|
| `custom_attr_1` | `my_custom_field` |
| `special_note` | `product_note` |

### Filter Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Filter by Visibility | Yes/No | Yes | Filter by product visibility |
| Allowed Visibility | Multiselect | Catalog,Search | Visibility values to include |
| Filter by Category | Yes/No | No | Enable category filtering |
| Category Filter Type | Dropdown | Include | Include / Exclude |
| Categories | Multiselect | - | Categories to include/exclude |
| Advanced Filters | Yes/No | No | Enable advanced attribute filters |
| Filter Conditions | Dynamic rows | - | Attribute + Condition + Value |

---

## API Endpoints

### 1. Product Feed Endpoint

**URL Pattern:** `GET /rest/V1/girofeeds/feed`

**Alternative URL (controller-based, no auth required beyond API key):**
`GET /girofeeds/feed`

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `key` | string | Yes | - | API key for authentication |
| `limit` | string | No | `0,100` | Pagination: `{offset},{count}` |
| `store` | string | No | default | Store view code |

**Response:** JSON array of product objects.

**Example request:**
```
GET https://mystore.com/rest/V1/girofeeds/feed?key=ABC123&limit=0,100
```

**Example response:**
```json
[
  {
    "id": "1234",
    "parent_id": "",
    "type_id": "simple",
    "sku": "PROD-001",
    "name": "Blue Cotton T-Shirt",
    "description": "<p>Premium cotton t-shirt...</p>",
    "short_description": "Premium cotton t-shirt",
    "meta_title": "Blue Cotton T-Shirt | MyStore",
    "meta_description": "Buy Blue Cotton T-Shirt online",
    "meta_keyword": "t-shirt, cotton, blue",
    "url": "https://mystore.com/blue-cotton-t-shirt.html",
    "url_key": "blue-cotton-t-shirt",
    "status": "1",
    "visibility": "4",
    "active": "1",
    "price": 29.99,
    "special_price": 24.99,
    "price_incl_tax": 36.29,
    "price_excl_tax": 29.99,
    "special_price_incl_tax": 30.24,
    "special_price_excl_tax": 24.99,
    "cost": 12.50,
    "tax_rate": 21.0,
    "tax_class_id": "2",
    "currency": "EUR",
    "qty": 150,
    "is_in_stock": "1",
    "manage_stock": "1",
    "min_sale_qty": 1,
    "max_sale_qty": 0,
    "backorders": "0",
    "brand": "Nike",
    "manufacturer": "Nike",
    "ean": "8718469123456",
    "gtin": "8718469123456",
    "upc": "",
    "isbn": "",
    "color": "Blue",
    "size": "M",
    "material": "Cotton",
    "gender": "Men",
    "weight": 0.25,
    "length": 0,
    "width": 0,
    "height": 0,
    "condition": "new",
    "country_of_origin": "ES",
    "category": "Clothing > Men > T-Shirts",
    "categories": [
      "Clothing",
      "Clothing > Men",
      "Clothing > Men > T-Shirts"
    ],
    "category_ids": [3, 5, 12],
    "image_link": "https://mystore.com/media/catalog/product/b/l/blue-tshirt.jpg",
    "additional_images": [
      "https://mystore.com/media/catalog/product/b/l/blue-tshirt-back.jpg",
      "https://mystore.com/media/catalog/product/b/l/blue-tshirt-detail.jpg"
    ],
    "delivery_period": "2-3 business days",
    "created_at": "2025-01-15 10:30:00",
    "updated_at": "2025-06-20 14:22:00"
  }
]
```

### 2. Product Update Endpoint

**URL Pattern:** `POST /rest/V1/girofeeds/updateproduct`

**Alternative URL:** `POST /girofeeds/updateproduct`

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes | API key for authentication |

**Request Body:** JSON object with `id_product` and any combination of updatable fields. The endpoint must accept ALL fields dynamically, not a hardcoded subset. Any field present in the request body (besides `id_product`) should be processed as an update.

**Required field:** `id_product` (integer) - The Magento product entity_id.

#### Design Principle: Accept All Modified Fields

The Girofeeds app sends only the fields that were actually modified by the optimization engine. The endpoint must NOT reject unknown fields -- it should process known fields and silently ignore unknown ones, logging them for debugging.

This is critical because the Girofeeds optimization engine can produce modifications to ANY attribute, including custom attributes added via Extra Attribute Mappings. The endpoint must handle this dynamically, not with a static field list.

#### Complete Updatable Fields Reference

The following table lists ALL fields the endpoint must support. Fields are grouped by update mechanism:

##### Text Attributes (direct EAV update)

| Request Field | Magento Attribute | Type | Validation | Notes |
|---------------|-------------------|------|------------|-------|
| `name` | `name` | string | max 255 chars | Product name |
| `description` | `description` | string | HTML allowed | Full product description. Accept and store HTML content as-is |
| `short_description` | `short_description` | string | HTML allowed | Short description. Accept and store HTML content as-is |
| `meta_title` | `meta_title` | string | max 255 chars | SEO meta title |
| `meta_description` | `meta_description` | string | max 255 chars | SEO meta description |
| `meta_keyword` | `meta_keyword` | string | max 255 chars | Comma-separated SEO keywords |
| `url_key` | `url_key` | string | URL-safe chars only | URL slug. Validate uniqueness before saving |
| `color` | Uses configured color attribute | string | Must match existing option | See "Select/Dropdown Attribute Updates" below |
| `size` | Uses configured size attribute | string | Must match existing option | See "Select/Dropdown Attribute Updates" below |
| `material` | Uses configured material attribute | string | Must match existing option | See "Select/Dropdown Attribute Updates" below |
| `gender` | Uses configured gender attribute | string | Must match existing option | See "Select/Dropdown Attribute Updates" below |
| `brand` / `manufacturer` | Uses configured brand attribute | string | Must match existing option or free text | If EAV type is `select`, see dropdown handling |
| `condition` | `condition` | string | new/used/refurbished | Product condition |
| `country_of_origin` | `country_of_manufacture` | string | ISO 2-letter code | Country of origin |

##### Numeric Attributes

| Request Field | Magento Attribute | Type | Validation | Notes |
|---------------|-------------------|------|------------|-------|
| `weight` | `weight` | decimal | >= 0 | Product weight in store's configured unit |
| `length` | `ts_dimensions_length` (or custom) | decimal | >= 0 | Only if dimension attributes exist |
| `width` | `ts_dimensions_width` (or custom) | decimal | >= 0 | Only if dimension attributes exist |
| `height` | `ts_dimensions_height` (or custom) | decimal | >= 0 | Only if dimension attributes exist |

##### Price Fields (require special handling)

| Request Field | Magento Attribute | Type | Validation | Notes |
|---------------|-------------------|------|------------|-------|
| `price` | `price` | decimal | > 0 | Base price excluding tax |
| `special_price` | `special_price` | decimal/null | >= 0 or null | Sale price. Send `null` or `0` to remove special price |
| `specific_price` | `special_price` | decimal/null | >= 0 or null | Alias for `special_price` (PrestaShop compatibility) |
| `cost` | `cost` | decimal | >= 0 | Product cost/COGS |

##### Stock Fields (require StockRegistry API)

| Request Field | Magento Attribute | Type | Validation | Notes |
|---------------|-------------------|------|------------|-------|
| `qty` | StockItem qty | integer | >= 0 | Inventory quantity |
| `stock` | StockItem qty | integer | >= 0 | Alias for `qty` (PrestaShop compatibility). `qty` takes priority if both present |
| `is_in_stock` | StockItem is_in_stock | boolean/"0"/"1" | 0 or 1 | In-stock flag. Auto-set based on qty if not provided |
| `manage_stock` | StockItem manage_stock | boolean/"0"/"1" | 0 or 1 | Whether to manage stock |
| `min_sale_qty` | StockItem min_sale_qty | integer | >= 1 | Minimum purchase quantity |
| `max_sale_qty` | StockItem max_sale_qty | integer | >= 0 | Maximum purchase quantity. 0 = unlimited |
| `backorders` | StockItem backorders | integer | 0/1/2 | 0=No backorders, 1=Allow qty below 0, 2=Allow qty below 0 and notify customer |

##### Status and Visibility Fields

| Request Field | Magento Attribute | Type | Validation | Notes |
|---------------|-------------------|------|------------|-------|
| `status` | `status` | integer/"1"/"2" | 1 or 2 | 1=Enabled, 2=Disabled |
| `active` | `status` | boolean/"0"/"1"/true/false | truthy/falsy | PrestaShop compat. Converts: `true`/"1"/1 -> status=1, `false`/"0"/0 -> status=2. `status` takes priority if both present |
| `visibility` | `visibility` | integer | 1-4 | 1=Not Visible Individually, 2=Catalog, 3=Search, 4=Catalog,Search |
| `visible` | `visibility` | string/"0"/"1" | | Alias: "1" -> visibility=4, "0" -> visibility=1. `visibility` takes priority if both present |

##### Category Assignment

| Request Field | Magento Attribute | Type | Validation | Notes |
|---------------|-------------------|------|------------|-------|
| `category_ids` | Category assignments | array of integers | Valid category IDs | Replaces all category assignments |
| `category` | Category by path | string | "Clothing > Men > T-Shirts" | Find category by path and assign. Does NOT create new categories. Only used if `category_ids` is not present |

##### Image Fields

| Request Field | Type | Notes |
|---------------|------|-------|
| `image` | string (URL) | Main product image. Accepts full URL or base64 data URL. See "Image Update Logic" below |
| `image_link` | string (URL) | Alias for `image` |
| `additional_images` | array of strings (URLs) | Additional gallery images. Accepts full URLs or base64 data URLs |

##### Identifier Fields

| Request Field | Magento Attribute | Type | Notes |
|---------------|-------------------|------|-------|
| `sku` / `reference` | `sku` | string | SKU. `sku` takes priority. WARNING: changing SKU can break external integrations |
| `ean` / `ean13` / `gtin` | Uses configured EAN attribute | string | Barcode. Priority: `ean` > `ean13` > `gtin`. All map to the same configured attribute |
| `upc` | `upc` (or configured) | string | UPC barcode if separate attribute exists |
| `isbn` | `isbn` (or configured) | string | ISBN if separate attribute exists |

##### Custom Mapped Attributes

Any field name that matches an "Extra Attribute Mapping" configured in admin should be processed. The mapping works in reverse during updates:

- During **feed export**: Magento attribute `custom_attr_1` is exported as `my_custom_field`
- During **update**: field `my_custom_field` in the request body maps back to Magento attribute `custom_attr_1`

```php
// Reverse mapping lookup
$extraMappings = $this->config->getExtraAttributeMappings();
$reverseMappings = array_flip($extraMappings); // ['my_custom_field' => 'custom_attr_1']

foreach ($data as $field => $value) {
    if (isset($reverseMappings[$field])) {
        $magentoAttribute = $reverseMappings[$field];
        $product->setData($magentoAttribute, $value);
    }
}
```

#### Example Requests

**Minimal update (single field):**
```json
POST /rest/V1/girofeeds/updateproduct?key=ABC123

{
  "id_product": 1234,
  "name": "Updated Product Name"
}
```

**Full optimization sync (multiple fields from AI optimization):**
```json
POST /rest/V1/girofeeds/updateproduct?key=ABC123

{
  "id_product": 1234,
  "name": "Premium Blue Cotton T-Shirt - Lightweight Comfort",
  "description": "<p>Experience ultimate comfort with our premium blue cotton t-shirt. Made from 100% organic cotton, this lightweight shirt features a modern fit and breathable fabric perfect for any occasion.</p><ul><li>100% organic cotton</li><li>Modern regular fit</li><li>Machine washable</li></ul>",
  "short_description": "Premium organic cotton t-shirt with modern fit and breathable fabric",
  "meta_title": "Premium Blue Cotton T-Shirt | Organic & Sustainable | MyStore",
  "meta_description": "Shop our premium blue cotton t-shirt made from 100% organic cotton. Lightweight, breathable, and sustainably made. Free shipping on orders over 50 EUR.",
  "meta_keyword": "organic cotton t-shirt, blue t-shirt, sustainable fashion, premium cotton shirt",
  "brand": "Nike",
  "color": "Blue",
  "size": "M",
  "price": 34.99,
  "special_price": 29.99,
  "active": true,
  "category": "Clothing > Men > T-Shirts"
}
```

**Stock and price update:**
```json
POST /rest/V1/girofeeds/updateproduct?key=ABC123

{
  "id_product": 1234,
  "price": 39.99,
  "special_price": null,
  "qty": 200,
  "is_in_stock": "1"
}
```

**PrestaShop-compatible field names (the endpoint must accept both):**
```json
POST /rest/V1/girofeeds/updateproduct?key=ABC123

{
  "id_product": 1234,
  "name": "Updated Name",
  "stock": 150,
  "reference": "NEW-SKU-001",
  "ean13": "8718469123456",
  "active": "1",
  "specific_price": 24.99
}
```

#### Response Format

**Success response:**
```json
{
  "status": "success",
  "message": "Product 1234 updated successfully",
  "updated_fields": ["name", "description", "price", "special_price", "qty"],
  "warnings": [],
  "product_id": 1234
}
```

**Partial success response (some fields failed):**
```json
{
  "status": "success",
  "message": "Product 1234 updated with warnings",
  "updated_fields": ["name", "description", "price"],
  "warnings": [
    "Field 'color': value 'Cerulean' is not a valid option for attribute 'color'. Available options: Red, Blue, Green, Black, White",
    "Field 'image': could not download image from URL"
  ],
  "failed_fields": ["color", "image"],
  "product_id": 1234
}
```

**Error response:**
```json
{
  "status": "error",
  "message": "Product not found with ID 9999",
  "errors": ["Product with entity_id 9999 does not exist"]
}
```

**Validation error response:**
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": [
    "Field 'price' must be a positive number",
    "Field 'visibility' must be between 1 and 4"
  ]
}
```

---

### 3. Product Count Endpoint

**URL Pattern:** `GET /rest/V1/girofeeds/count`

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes | API key |
| `store` | string | No | Store view code |

**Response:**
```json
{
  "count": 1523
}
```

### 4. Connection Test Endpoint

**URL Pattern:** `GET /rest/V1/girofeeds/test`

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes | API key |

**Response:**
```json
{
  "status": "success",
  "version": "2.0.0",
  "magento_version": "2.4.7",
  "store": "default",
  "product_count": 1523,
  "module_name": "Girofeeds_ProductApi",
  "supported_fields": [
    "name", "description", "short_description", "price", "special_price",
    "qty", "status", "visibility", "brand", "sku", "ean", "gtin",
    "meta_title", "meta_description", "meta_keyword", "url_key",
    "color", "size", "material", "gender", "weight",
    "image", "image_link", "additional_images",
    "category_ids", "category", "active", "stock", "reference",
    "ean13", "specific_price", "visible", "cost",
    "is_in_stock", "manage_stock", "min_sale_qty", "max_sale_qty", "backorders",
    "condition", "country_of_origin", "length", "width", "height"
  ],
  "extra_mapped_fields": ["my_custom_field", "product_note"]
}
```

---

## Implementation Details

### Authentication

- API key-based authentication (no OAuth/Bearer token required)
- API key is passed as query parameter `key`
- Key is compared against the configured value in admin
- Rate limiting: 60 requests per minute per API key (configurable)

### Product Data Collection (Feed)

The feed generation should follow this order for each product:

1. **Load product collection** with pagination (`$offset, $count`)
2. **Apply visibility/status/category filters** at the collection level (SQL)
3. **For each product:**
   a. Collect basic attributes (name, sku, status, visibility, etc.)
   b. Collect prices (regular, special, incl/excl tax)
   c. Collect stock data (qty, is_in_stock, manage_stock)
   d. Collect category data (names with hierarchy, IDs)
   e. Collect images (main + additional)
   f. Collect meta data (title, description, keywords)
   g. Collect custom mapped attributes
   h. Apply configurable product logic (parent fallback)
   i. Flatten all data into a single-level JSON object

### Category Hierarchy

Categories must include the full hierarchy path:

```
"category": "Clothing > Men > T-Shirts"
"categories": [
  "Clothing",
  "Clothing > Men",
  "Clothing > Men > T-Shirts"
]
"category_ids": [3, 5, 12]
```

Use ` > ` as the hierarchy separator (space-arrow-space).

### Price Handling

Always provide both tax-inclusive and tax-exclusive prices:

```json
{
  "price": 29.99,
  "price_incl_tax": 36.29,
  "price_excl_tax": 29.99,
  "special_price": 24.99,
  "special_price_incl_tax": 30.24,
  "special_price_excl_tax": 24.99,
  "tax_rate": 21.0,
  "currency": "EUR"
}
```

Use Magento's `Tax\Model\Calculation` for accurate tax computation based on the product's tax class and the store's default tax rules.

### Stock Handling

Use `StockRegistryInterface` for stock data, supporting both legacy and MSI:

```php
$stockItem = $this->stockRegistry->getStockItem($product->getId());
$data['qty'] = (int) $stockItem->getQty();
$data['is_in_stock'] = $stockItem->getIsInStock() ? '1' : '0';
$data['manage_stock'] = $stockItem->getManageStock() ? '1' : '0';
$data['min_sale_qty'] = (int) $stockItem->getMinSaleQty();
$data['max_sale_qty'] = (int) $stockItem->getMaxSaleQty();
$data['backorders'] = $stockItem->getBackorders();
```

### Image Handling

```php
$mediaGallery = $product->getMediaGalleryImages();
$mainImage = $product->getData($this->config->getMainImageRole()); // 'image', 'small_image', 'thumbnail'

$data['image_link'] = $this->getMediaUrl($mainImage);
$data['additional_images'] = [];

foreach ($mediaGallery as $image) {
    if ($image->getFile() !== $mainImage) {
        $data['additional_images'][] = $this->getMediaUrl($image->getFile());
    }
}
```

### Configurable Product Handling

When "Simple Products Only" is selected:

```php
if ($product->getTypeId() === 'configurable') {
    $childProducts = $product->getTypeInstance()->getUsedProducts($product);
    foreach ($childProducts as $child) {
        $childData = $this->collectProductData($child);

        // Parent URL fallback
        if ($this->config->useParentUrl()) {
            $childData['url'] = $parentData['url'];
        }

        // Parent image fallback
        if ($this->config->useParentImageIfEmpty() && empty($childData['image_link'])) {
            $childData['image_link'] = $parentData['image_link'];
            $childData['additional_images'] = $parentData['additional_images'];
        }

        // Parent data fallback for configured fields
        foreach ($this->config->getParentFallbackFields() as $field) {
            if (empty($childData[$field]) && !empty($parentData[$field])) {
                $childData[$field] = $parentData[$field];
            }
        }

        $childData['parent_id'] = $product->getId();
        $feedProducts[] = $childData;
    }
}
```

---

## Product Update Implementation

### Update Architecture

The update endpoint must process fields dynamically using a pipeline approach. Each field in the request body is routed to the appropriate updater based on its type:

```
Request Body -> Validation -> Field Resolution -> Type-Specific Updaters -> Save -> Response
```

### Field Resolution (Alias Handling)

The endpoint receives fields using Girofeeds normalized names OR Magento native names OR PrestaShop-compatible names. The first step is to resolve aliases to canonical Magento attributes:

```php
class FieldProcessor
{
    private array $aliasMap = [
        // PrestaShop compatibility aliases
        'stock'          => 'qty',
        'reference'      => 'sku',
        'ean13'          => 'ean',
        'specific_price' => 'special_price',
        'active'         => 'status',        // needs value conversion
        'visible'        => 'visibility',    // needs value conversion
        'image'          => 'image_link',
        'manufacturer'   => 'brand',         // reverse: brand -> configured attribute
    ];

    // Priority rules: when multiple aliases present, which one wins
    private array $priorityRules = [
        'qty'            => ['qty', 'stock'],              // qty takes priority over stock
        'sku'            => ['sku', 'reference'],           // sku takes priority over reference
        'ean'            => ['ean', 'ean13', 'gtin'],       // ean > ean13 > gtin
        'status'         => ['status', 'active'],           // status takes priority over active
        'visibility'     => ['visibility', 'visible'],      // visibility takes priority
        'special_price'  => ['special_price', 'specific_price'],
        'image_link'     => ['image_link', 'image'],
    ];

    public function resolveFields(array $data): array
    {
        $resolved = [];
        $processedAliases = [];

        // Apply priority rules
        foreach ($this->priorityRules as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $data) && !in_array($canonical, $processedAliases)) {
                    $resolved[$canonical] = $data[$alias];
                    $processedAliases[] = $canonical;
                    // Mark all aliases as consumed
                    foreach ($aliases as $a) {
                        unset($data[$a]);
                    }
                    break;
                }
            }
        }

        // Process remaining fields
        foreach ($data as $field => $value) {
            if ($field === 'id_product') continue;

            if (isset($this->aliasMap[$field])) {
                $canonical = $this->aliasMap[$field];
                if (!isset($resolved[$canonical])) {
                    $resolved[$canonical] = $value;
                }
            } else {
                $resolved[$field] = $value;
            }
        }

        return $resolved;
    }
}
```

### Value Conversion

Some fields need value conversion when received with PrestaShop-compatible names:

```php
// 'active' field (boolean/string) -> Magento 'status' (1 or 2)
private function convertActiveToStatus($value): int
{
    if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
        return 1; // Enabled
    }
    return 2; // Disabled
}

// 'visible' field (string) -> Magento 'visibility' (1-4)
private function convertVisibleToVisibility($value): int
{
    if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
        return 4; // Catalog, Search
    }
    return 1; // Not Visible Individually
}

// 'special_price' -> handle null/0 to remove
private function processSpecialPrice($value): ?float
{
    if ($value === null || $value === '' || $value === 0 || $value === '0') {
        return null; // Removes special price in Magento
    }
    return (float) $value;
}
```

### Select/Dropdown Attribute Updates

For EAV attributes of type `select` (like color, size, material, gender), the update endpoint receives the text label but must store the option_id. The module must resolve text values to option IDs:

```php
class AttributeUpdater
{
    public function updateSelectAttribute(
        ProductInterface $product,
        string $attributeCode,
        string $textValue
    ): ?string {
        $attribute = $this->eavConfig->getAttribute('catalog_product', $attributeCode);

        if (!$attribute || !$attribute->getId()) {
            return "Attribute '{$attributeCode}' does not exist";
        }

        if ($attribute->getFrontendInput() !== 'select') {
            // Not a dropdown, set value directly
            $product->setData($attributeCode, $textValue);
            return null;
        }

        // Find option ID by label (case-insensitive)
        $options = $attribute->getSource()->getAllOptions(false);
        $optionId = null;

        foreach ($options as $option) {
            if (strtolower(trim($option['label'])) === strtolower(trim($textValue))) {
                $optionId = $option['value'];
                break;
            }
        }

        if ($optionId === null) {
            // Option not found - return warning with available options
            $availableLabels = array_map(fn($o) => $o['label'], $options);
            return "Value '{$textValue}' is not a valid option for attribute '{$attributeCode}'. "
                 . "Available options: " . implode(', ', $availableLabels);
        }

        $product->setData($attributeCode, $optionId);
        return null; // success
    }
}
```

### Price Update Logic

```php
class PriceUpdater
{
    public function updatePrices(ProductInterface $product, array $resolvedData): array
    {
        $warnings = [];

        if (array_key_exists('price', $resolvedData)) {
            $price = (float) $resolvedData['price'];
            if ($price <= 0) {
                $warnings[] = "Field 'price' must be a positive number, received: {$price}";
            } else {
                $product->setPrice($price);
            }
        }

        if (array_key_exists('special_price', $resolvedData)) {
            $specialPrice = $this->processSpecialPrice($resolvedData['special_price']);
            $product->setSpecialPrice($specialPrice);

            // If setting special price, ensure dates allow it
            if ($specialPrice !== null) {
                // If no from/to dates set, special price applies immediately and indefinitely
                if (!$product->getSpecialFromDate()) {
                    $product->setSpecialFromDate(date('Y-m-d H:i:s'));
                }
            }
        }

        if (array_key_exists('cost', $resolvedData)) {
            $product->setData('cost', (float) $resolvedData['cost']);
        }

        return $warnings;
    }
}
```

### Stock Update Logic

```php
class StockUpdater
{
    private StockRegistryInterface $stockRegistry;

    public function updateStock(ProductInterface $product, array $resolvedData): array
    {
        $warnings = [];
        $stockFields = ['qty', 'is_in_stock', 'manage_stock', 'min_sale_qty', 'max_sale_qty', 'backorders'];
        $hasStockUpdate = false;

        foreach ($stockFields as $field) {
            if (array_key_exists($field, $resolvedData)) {
                $hasStockUpdate = true;
                break;
            }
        }

        if (!$hasStockUpdate) {
            return $warnings;
        }

        $stockItem = $this->stockRegistry->getStockItem($product->getId());

        if (array_key_exists('qty', $resolvedData)) {
            $qty = (int) $resolvedData['qty'];
            $stockItem->setQty($qty);

            // Auto-set is_in_stock based on qty if not explicitly provided
            if (!array_key_exists('is_in_stock', $resolvedData)) {
                $stockItem->setIsInStock($qty > 0);
            }
        }

        if (array_key_exists('is_in_stock', $resolvedData)) {
            $stockItem->setIsInStock(
                in_array($resolvedData['is_in_stock'], [true, 1, '1', 'true'], true)
            );
        }

        if (array_key_exists('manage_stock', $resolvedData)) {
            $stockItem->setManageStock(
                in_array($resolvedData['manage_stock'], [true, 1, '1', 'true'], true)
            );
        }

        if (array_key_exists('min_sale_qty', $resolvedData)) {
            $stockItem->setMinSaleQty((int) $resolvedData['min_sale_qty']);
        }

        if (array_key_exists('max_sale_qty', $resolvedData)) {
            $stockItem->setMaxSaleQty((int) $resolvedData['max_sale_qty']);
        }

        if (array_key_exists('backorders', $resolvedData)) {
            $stockItem->setBackorders((int) $resolvedData['backorders']);
        }

        $this->stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);

        return $warnings;
    }
}
```

### Image Update Logic

The Girofeeds app may send images as:
1. **Full URL** (e.g., `https://example.com/image.jpg`) - download and import
2. **Base64 data URL** (e.g., `data:image/webp;base64,UklGR...`) - decode and import (generated by AI image search)
3. **Existing Magento URL** (e.g., `https://mystore.com/media/catalog/product/...`) - skip, already in the store

```php
class ImageUpdater
{
    public function updateImages(ProductInterface $product, array $resolvedData): array
    {
        $warnings = [];

        // Main image
        if (array_key_exists('image_link', $resolvedData)) {
            $imageUrl = $resolvedData['image_link'];
            $warning = $this->processImage($product, $imageUrl, true);
            if ($warning) {
                $warnings[] = $warning;
            }
        }

        // Additional images
        if (array_key_exists('additional_images', $resolvedData) && is_array($resolvedData['additional_images'])) {
            foreach ($resolvedData['additional_images'] as $imageUrl) {
                $warning = $this->processImage($product, $imageUrl, false);
                if ($warning) {
                    $warnings[] = $warning;
                }
            }
        }

        return $warnings;
    }

    private function processImage(ProductInterface $product, string $imageSource, bool $isMain): ?string
    {
        try {
            // Check if it's already a local Magento URL
            $storeMediaUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
            if (strpos($imageSource, $storeMediaUrl) === 0) {
                return null; // Already in the store, no action needed
            }

            $tmpFilePath = null;

            if (strpos($imageSource, 'data:') === 0) {
                // Base64 data URL
                $tmpFilePath = $this->decodeBase64Image($imageSource);
            } elseif (filter_var($imageSource, FILTER_VALIDATE_URL)) {
                // Remote URL - download
                $tmpFilePath = $this->downloadImage($imageSource);
            } else {
                return "Invalid image source: not a valid URL or base64 data";
            }

            if (!$tmpFilePath) {
                return "Failed to process image: {$imageSource}";
            }

            // Import into Magento media gallery
            $product->addImageToMediaGallery(
                $tmpFilePath,
                $isMain ? ['image', 'small_image', 'thumbnail'] : null,
                false, // move file
                false  // not hidden
            );

            return null; // success
        } catch (\Exception $e) {
            return "Image error: " . $e->getMessage();
        }
    }

    private function decodeBase64Image(string $dataUrl): ?string
    {
        // Parse: data:image/webp;base64,UklGR...
        if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $dataUrl, $matches)) {
            return null;
        }

        $extension = $matches[1];
        $base64Data = $matches[2];
        $imageData = base64_decode($base64Data);

        if (!$imageData) {
            return null;
        }

        // Save to tmp directory
        $tmpDir = $this->filesystem->getDirectoryRead(DirectoryList::TMP)->getAbsolutePath();
        $tmpFile = $tmpDir . 'girofeeds_' . uniqid() . '.' . $extension;
        file_put_contents($tmpFile, $imageData);

        return $tmpFile;
    }

    private function downloadImage(string $url): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'Girofeeds-ImageImport/1.0'
            ]);

            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($httpCode !== 200 || !$imageData) {
                return null;
            }

            // Determine extension from content type
            $extensionMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
            ];
            $extension = $extensionMap[$contentType] ?? 'jpg';

            $tmpDir = $this->filesystem->getDirectoryRead(DirectoryList::TMP)->getAbsolutePath();
            $tmpFile = $tmpDir . 'girofeeds_' . uniqid() . '.' . $extension;
            file_put_contents($tmpFile, $imageData);

            return $tmpFile;
        } catch (\Exception $e) {
            return null;
        }
    }
}
```

### Category Update Logic

```php
class CategoryUpdater
{
    public function updateCategories(ProductInterface $product, array $resolvedData): array
    {
        $warnings = [];

        // Priority 1: category_ids (direct assignment)
        if (array_key_exists('category_ids', $resolvedData) && is_array($resolvedData['category_ids'])) {
            $validIds = [];
            foreach ($resolvedData['category_ids'] as $catId) {
                try {
                    $this->categoryRepository->get($catId);
                    $validIds[] = (int) $catId;
                } catch (NoSuchEntityException $e) {
                    $warnings[] = "Category ID {$catId} does not exist, skipping";
                }
            }
            $product->setCategoryIds($validIds);
            return $warnings;
        }

        // Priority 2: category path (find by name hierarchy)
        if (array_key_exists('category', $resolvedData) && is_string($resolvedData['category'])) {
            $categoryPath = $resolvedData['category'];
            $categoryId = $this->findCategoryByPath($categoryPath);

            if ($categoryId) {
                // Add to existing categories (don't replace)
                $existingIds = $product->getCategoryIds();
                if (!in_array($categoryId, $existingIds)) {
                    $existingIds[] = $categoryId;
                    $product->setCategoryIds($existingIds);
                }
            } else {
                $warnings[] = "Category path '{$categoryPath}' not found in store. Categories not updated.";
            }
        }

        return $warnings;
    }

    private function findCategoryByPath(string $path): ?int
    {
        // path format: "Clothing > Men > T-Shirts"
        $parts = array_map('trim', explode(' > ', $path));
        $lastCategoryName = end($parts);

        // Search for category by name
        $collection = $this->categoryCollectionFactory->create()
            ->addAttributeToFilter('name', $lastCategoryName)
            ->setPageSize(1);

        if ($collection->getSize() > 0) {
            return (int) $collection->getFirstItem()->getId();
        }

        return null;
    }
}
```

### Complete Update Orchestration

```php
class UpdateProduct implements UpdateProductInterface
{
    public function execute(int $productId, array $data): array
    {
        try {
            // 1. Load product
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException $e) {
            return [
                'status' => 'error',
                'message' => "Product not found with ID {$productId}",
                'errors' => ["Product with entity_id {$productId} does not exist"]
            ];
        }

        // 2. Resolve field aliases
        $resolved = $this->fieldProcessor->resolveFields($data);

        // 3. Validate fields
        $validationErrors = $this->validationService->validate($resolved);
        if (!empty($validationErrors)) {
            return [
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validationErrors
            ];
        }

        $updatedFields = [];
        $warnings = [];
        $failedFields = [];

        // 4. Process text attributes
        $textFields = ['name', 'description', 'short_description', 'meta_title',
                       'meta_description', 'meta_keyword', 'url_key', 'condition',
                       'country_of_origin'];
        foreach ($textFields as $field) {
            if (array_key_exists($field, $resolved)) {
                $product->setData($field, $resolved[$field]);
                $updatedFields[] = $field;
            }
        }

        // 5. Process numeric attributes
        $numericFields = ['weight', 'length', 'width', 'height'];
        foreach ($numericFields as $field) {
            if (array_key_exists($field, $resolved)) {
                $product->setData($field, (float) $resolved[$field]);
                $updatedFields[] = $field;
            }
        }

        // 6. Process select/dropdown attributes (color, size, brand, etc.)
        $selectFields = [
            'color'    => $this->config->getColorAttribute(),
            'size'     => $this->config->getSizeAttribute(),
            'material' => $this->config->getMaterialAttribute(),
            'gender'   => $this->config->getGenderAttribute(),
            'brand'    => $this->config->getBrandAttribute(),
        ];
        foreach ($selectFields as $requestField => $magentoAttribute) {
            if (array_key_exists($requestField, $resolved) && $magentoAttribute) {
                $warning = $this->attributeUpdater->updateSelectAttribute(
                    $product, $magentoAttribute, $resolved[$requestField]
                );
                if ($warning) {
                    $warnings[] = "Field '{$requestField}': {$warning}";
                    $failedFields[] = $requestField;
                } else {
                    $updatedFields[] = $requestField;
                }
            }
        }

        // 7. Process identifier fields
        if (array_key_exists('sku', $resolved)) {
            $product->setSku($resolved['sku']);
            $updatedFields[] = 'sku';
        }
        if (array_key_exists('ean', $resolved)) {
            $eanAttribute = $this->config->getEanAttribute();
            if ($eanAttribute) {
                $product->setData($eanAttribute, $resolved['ean']);
                $updatedFields[] = 'ean';
            }
        }

        // 8. Process status and visibility
        if (array_key_exists('status', $resolved)) {
            $status = $this->convertActiveToStatus($resolved['status']);
            $product->setStatus($status);
            $updatedFields[] = 'status';
        }
        if (array_key_exists('visibility', $resolved)) {
            $product->setVisibility((int) $resolved['visibility']);
            $updatedFields[] = 'visibility';
        }

        // 9. Process prices
        $priceWarnings = $this->priceUpdater->updatePrices($product, $resolved);
        $warnings = array_merge($warnings, $priceWarnings);
        foreach (['price', 'special_price', 'cost'] as $pf) {
            if (array_key_exists($pf, $resolved) && !in_array($pf, $failedFields)) {
                $updatedFields[] = $pf;
            }
        }

        // 10. Process custom mapped attributes (Extra Attribute Mappings)
        $extraMappings = $this->config->getExtraAttributeMappings();
        $reverseMappings = array_flip($extraMappings);
        foreach ($resolved as $field => $value) {
            if (isset($reverseMappings[$field])) {
                $magentoAttr = $reverseMappings[$field];
                $product->setData($magentoAttr, $value);
                $updatedFields[] = $field;
            }
        }

        // 11. Save product (before stock and images, as they need product ID)
        try {
            $this->productRepository->save($product);
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => "Failed to save product: " . $e->getMessage(),
                'errors' => [$e->getMessage()]
            ];
        }

        // 12. Process stock (after product save)
        $stockWarnings = $this->stockUpdater->updateStock($product, $resolved);
        $warnings = array_merge($warnings, $stockWarnings);
        foreach (['qty', 'is_in_stock', 'manage_stock', 'min_sale_qty', 'max_sale_qty', 'backorders'] as $sf) {
            if (array_key_exists($sf, $resolved)) {
                $updatedFields[] = $sf;
            }
        }

        // 13. Process categories
        $categoryWarnings = $this->categoryUpdater->updateCategories($product, $resolved);
        $warnings = array_merge($warnings, $categoryWarnings);
        if (array_key_exists('category_ids', $resolved) || array_key_exists('category', $resolved)) {
            $this->productRepository->save($product); // Re-save with updated categories
            $updatedFields[] = array_key_exists('category_ids', $resolved) ? 'category_ids' : 'category';
        }

        // 14. Process images
        $imageWarnings = $this->imageUpdater->updateImages($product, $resolved);
        $warnings = array_merge($warnings, $imageWarnings);
        if (array_key_exists('image_link', $resolved)) $updatedFields[] = 'image_link';
        if (array_key_exists('additional_images', $resolved)) $updatedFields[] = 'additional_images';

        // 15. Build response
        $updatedFields = array_unique($updatedFields);

        if (empty($warnings)) {
            return [
                'status' => 'success',
                'message' => "Product {$productId} updated successfully",
                'updated_fields' => array_values($updatedFields),
                'warnings' => [],
                'product_id' => $productId
            ];
        } else {
            return [
                'status' => 'success',
                'message' => "Product {$productId} updated with warnings",
                'updated_fields' => array_values($updatedFields),
                'warnings' => $warnings,
                'failed_fields' => $failedFields,
                'product_id' => $productId
            ];
        }
    }
}
```

### Validation Service

```php
class ValidationService
{
    public function validate(array $resolvedData): array
    {
        $errors = [];

        if (array_key_exists('price', $resolvedData)) {
            $price = $resolvedData['price'];
            if (!is_numeric($price) || (float) $price <= 0) {
                $errors[] = "Field 'price' must be a positive number";
            }
        }

        if (array_key_exists('visibility', $resolvedData)) {
            $vis = (int) $resolvedData['visibility'];
            if ($vis < 1 || $vis > 4) {
                $errors[] = "Field 'visibility' must be between 1 and 4";
            }
        }

        if (array_key_exists('status', $resolvedData)) {
            $status = $resolvedData['status'];
            // Allow boolean, string, or integer values
            $validStatuses = [1, 2, '1', '2', true, false, 'true', 'false', '0'];
            if (!in_array($status, $validStatuses, true)) {
                $errors[] = "Field 'status' must be 1 (enabled) or 2 (disabled)";
            }
        }

        if (array_key_exists('qty', $resolvedData)) {
            if (!is_numeric($resolvedData['qty']) || (int) $resolvedData['qty'] < 0) {
                $errors[] = "Field 'qty' must be a non-negative integer";
            }
        }

        if (array_key_exists('weight', $resolvedData)) {
            if (!is_numeric($resolvedData['weight']) || (float) $resolvedData['weight'] < 0) {
                $errors[] = "Field 'weight' must be a non-negative number";
            }
        }

        if (array_key_exists('url_key', $resolvedData)) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $resolvedData['url_key'])) {
                $errors[] = "Field 'url_key' must contain only lowercase letters, numbers, and hyphens";
            }
        }

        if (array_key_exists('backorders', $resolvedData)) {
            $bo = (int) $resolvedData['backorders'];
            if ($bo < 0 || $bo > 2) {
                $errors[] = "Field 'backorders' must be 0, 1, or 2";
            }
        }

        return $errors;
    }
}
```

### Store View Support for Updates

The update endpoint must respect the `store` query parameter to update store-specific attribute values:

```
POST /rest/V1/girofeeds/updateproduct?key=ABC123&store=de
```

When `store` parameter is provided:
1. Load the store by code
2. Set the store ID on the product before saving
3. This ensures translated values (name, description, meta fields) are saved per-store

```php
if ($storeCode = $request->getParam('store')) {
    $store = $this->storeManager->getStore($storeCode);
    $product->setStoreId($store->getId());
}
```

### Configurable Product Update Considerations

When updating a simple product that is a child of a configurable:
- The `id_product` in the request should always be the **simple product's entity_id**
- If the simple product was exported with `parent_id` in the feed, the app sends the simple's ID for updates
- Never update the configurable parent through this endpoint (it would affect all variants)
- SKU changes on simple children are allowed but should validate they don't conflict with other variants

---

## Field Mapping Summary (Feed Export vs Update Import)

This table shows how fields flow in both directions:

| Feed Export (Magento -> JSON) | Update Import (JSON -> Magento) | Magento Attribute | Notes |
|-------------------------------|--------------------------------|-------------------|-------|
| `id` | `id_product` | `entity_id` | Read-only in feed, identifier in update |
| `name` | `name` | `name` | Direct mapping |
| `description` | `description` | `description` | HTML preserved both ways |
| `short_description` | `short_description` | `short_description` | HTML preserved both ways |
| `sku` | `sku` / `reference` | `sku` | |
| `price` | `price` | `price` | Excl. tax both ways |
| `special_price` | `special_price` / `specific_price` | `special_price` | null removes it |
| `cost` | `cost` | `cost` | |
| `qty` | `qty` / `stock` | StockItem | Via StockRegistry |
| `is_in_stock` | `is_in_stock` | StockItem | Auto-calculated if not provided |
| `status` | `status` / `active` | `status` | 1/2 in Magento, boolean in PrestaShop compat |
| `visibility` | `visibility` / `visible` | `visibility` | 1-4 in Magento |
| `brand` / `manufacturer` | `brand` / `manufacturer` | Configured attribute | |
| `ean` / `gtin` | `ean` / `ean13` / `gtin` | Configured attribute | |
| `color` | `color` | Configured attribute | Text label <-> option_id |
| `size` | `size` | Configured attribute | Text label <-> option_id |
| `material` | `material` | Configured attribute | Text label <-> option_id |
| `gender` | `gender` | Configured attribute | Text label <-> option_id |
| `weight` | `weight` | `weight` | |
| `meta_title` | `meta_title` | `meta_title` | |
| `meta_description` | `meta_description` | `meta_description` | |
| `meta_keyword` | `meta_keyword` | `meta_keyword` | |
| `url_key` | `url_key` | `url_key` | Validate uniqueness |
| `url` | - | Generated | Read-only |
| `image_link` | `image_link` / `image` | Media gallery | URL in feed, URL/base64 in update |
| `additional_images` | `additional_images` | Media gallery | Array of URLs |
| `category` | `category` | Category names | Path format with " > " |
| `categories` | - | Category names | Read-only (array) |
| `category_ids` | `category_ids` | Category assignments | Array of integer IDs |
| `parent_id` | - | | Read-only |
| `type_id` | - | | Read-only |
| `created_at` | - | | Read-only |
| `updated_at` | - | | Read-only (auto-updated) |
| `currency` | - | | Read-only |
| `tax_rate` | - | | Read-only |
| `tax_class_id` | - | | Read-only |
| `price_incl_tax` | - | | Read-only (calculated) |
| `price_excl_tax` | - | | Read-only (calculated) |
| `special_price_incl_tax` | - | | Read-only (calculated) |
| `special_price_excl_tax` | - | | Read-only (calculated) |
| `delivery_period` | - | | Read-only |
| `condition` | `condition` | `condition` | |
| `country_of_origin` | `country_of_origin` | `country_of_manufacture` | |

---

## Compatibility

- **Magento 2:** 2.4.4 - 2.4.7+
- **PHP:** 8.1, 8.2, 8.3
- **Database:** MySQL 8.0+, MariaDB 10.6+
- **MSI:** Compatible with Multi-Source Inventory
- **Hyva:** No frontend dependency, API only

---

## Installation

```bash
composer require girofeeds/module-product-api
bin/magento module:enable Girofeeds_ProductApi
bin/magento setup:upgrade
bin/magento cache:clean
```

---

## Security Considerations

- API key must be at least 24 characters, auto-generated with cryptographic randomness
- Rate limiting to prevent abuse (configurable, default 60 req/min)
- No admin session or customer data exposed through the API
- Product update endpoint validates all input data types and sanitizes HTML content
- CORS headers configurable for development environments
- Log all API requests with timestamp, IP, endpoint, and response status
- The update endpoint must NOT allow updating `entity_id`, `created_at`, `updated_at`, or other system-managed fields
- Image downloads from external URLs should validate file type and enforce a maximum file size (10MB default)
- Base64 image data should be validated before decoding (check MIME type, enforce max size)
