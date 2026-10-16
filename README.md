# Girofeeds Product API for Magento 2

Product feed export, update and creation REST API endpoints for Magento 2.

Versión actual: 1.26.0

## Installation

Este módulo no está disponible en Packagist todavía. Elige uno de los siguientes métodos de instalación manual:

---

### Método 1: Instalación via SSH (Recomendado)

**Paso 1:** Conecta al servidor Magento via SSH

```bash
ssh usuario@tu-servidor.com
cd /path/to/magento/root
```

**Paso 2:** Crea el directorio del módulo

```bash
mkdir -p app/code/Girofeeds/ProductApi
```

**Paso 3:** Descarga o copia los archivos del módulo

Opción A - Si tienes el módulo en un repositorio Git:
```bash
cd app/code/Girofeeds/ProductApi
git clone https://github.com/tu-usuario/girofeeds-productapi.git .
```

Opción B - Si tienes los archivos localmente, usa SCP desde tu máquina:
```bash
# Desde tu máquina local (no en el servidor)
scp -r /path/to/module/* usuario@servidor:/path/to/magento/app/code/Girofeeds/ProductApi/
```

**Paso 4:** Establece permisos correctos

```bash
cd /path/to/magento/root
chmod -R 755 app/code/Girofeeds
chown -R www-data:www-data app/code/Girofeeds
# Nota: Reemplaza www-data con el usuario de tu servidor web (puede ser apache, nginx, etc.)
```

**Paso 5:** Habilita el módulo

```bash
bin/magento module:enable Girofeeds_ProductApi
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

**Paso 6:** (Solo en modo producción)

```bash
bin/magento setup:static-content:deploy es_ES en_US
bin/magento cache:flush
```

---

### Método 2: Instalación via ZIP Upload

**Paso 1:** Prepara el archivo ZIP

Empaqueta todos los archivos del módulo (sin la carpeta raíz):

```bash
# Desde la carpeta del módulo
zip -r girofeeds-productapi.zip . -x "*.git*" -x "*.DS_Store"
```

El ZIP debe contener:
```
girofeeds-productapi.zip
├── Api/
├── Block/
├── Console/
├── Controller/
├── Helper/
├── etc/
├── Model/
├── Observer/
├── Plugin/
├── Service/
├── Setup/
├── view/
├── composer.json
├── registration.php
└── README.md
```

**Paso 2:** Sube el ZIP al servidor

Usando tu panel de hosting (cPanel, Plesk, etc.) o FTP:
- Sube `girofeeds-productapi.zip` a `/path/to/magento/root/`

**Paso 3:** Extrae el ZIP via SSH

```bash
ssh usuario@tu-servidor.com
cd /path/to/magento/root

# Crea el directorio del módulo
mkdir -p app/code/Girofeeds/ProductApi

# Extrae el ZIP
unzip girofeeds-productapi.zip -d app/code/Girofeeds/ProductApi/

# Limpia
rm girofeeds-productapi.zip
```

**Paso 4:** Establece permisos y habilita el módulo

```bash
chmod -R 755 app/code/Girofeeds
chown -R www-data:www-data app/code/Girofeeds

bin/magento module:enable Girofeeds_ProductApi
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

**Paso 5:** (Solo en modo producción)

```bash
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

---

### Método 3: Composer con Repositorio Local

Este método es útil si quieres gestionar el módulo con Composer sin publicarlo en Packagist.

**Paso 1:** Copia los archivos del módulo al servidor

```bash
# Crea un directorio para repositorios locales
mkdir -p /path/to/magento/local-modules/girofeeds-productapi

# Copia todos los archivos del módulo
cp -r /path/to/module/* /path/to/magento/local-modules/girofeeds-productapi/
```

**Paso 2:** Configura el repositorio local en composer.json

Edita `/path/to/magento/composer.json` y añade:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./local-modules/girofeeds-productapi",
            "options": {
                "symlink": false
            }
        }
    ]
}
```

**Paso 3:** Instala con Composer

```bash
cd /path/to/magento/root
composer require girofeeds/module-product-api:@dev
bin/magento module:enable Girofeeds_ProductApi
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

**Ventajas de este método:**
- Gestión de dependencias con Composer
- Fácil actualización del módulo
- Integración con el flujo de trabajo de Composer

---

### Verificar la Instalación

Después de instalar con cualquier método, verifica que el módulo esté activo:

```bash
bin/magento module:status Girofeeds_ProductApi
```

Salida esperada:
```
Module is enabled
```

Lista todos los módulos instalados:
```bash
bin/magento module:status | grep Girofeeds
```

Comprueba que los archivos están en su lugar:
```bash
ls -la app/code/Girofeeds/ProductApi/
```

---

### Solución de Problemas Comunes

**Error: "Module 'Girofeeds_ProductApi' is not enabled"**
```bash
bin/magento module:enable Girofeeds_ProductApi
bin/magento setup:upgrade
```

**Error: "Class not found"**
```bash
bin/magento setup:di:compile
composer dump-autoload
```

**Error de permisos**
```bash
# Ajusta permisos (reemplaza www-data con tu usuario del servidor web)
find app/code/Girofeeds -type f -exec chmod 644 {} \;
find app/code/Girofeeds -type d -exec chmod 755 {} \;
chown -R www-data:www-data app/code/Girofeeds
```

**Caché no se limpia**
```bash
rm -rf var/cache/* var/page_cache/* var/generation/*
bin/magento cache:flush
```

## Configuration

Go to **Stores > Configuration > Girofeeds > Product API** to configure the module.

### API Rate Limit

**General Settings > General > API Rate Limit** sets how many API requests per minute are
accepted for each API key (default: 600). Requests above the limit get HTTP 429
`Rate limit exceeded`. The value is stored in `girofeeds_productapi/general/rate_limit`
(default scope) and can also be set from the CLI:

```bash
bin/magento config:set girofeeds_productapi/general/rate_limit 600
bin/magento cache:clean config
```

### Write Scope

**General Settings > General > Write Scope** decides where product updates sent by Girofeeds
are written (default scope only, path `girofeeds_productapi/general/write_scope`):

- `all` (default, recommended): **All Store Views** (`store_id 0`), whatever store view the
  REST route uses (`/rest/default/V1/...` no longer writes into the `default` view).
- a store view code: values are written for that store view only.

A request can override it with the `store` query parameter or a `store_id` field in the payload
(`all`, `admin` or `0` mean All Store Views; a store code or id means that view; an unknown store
returns HTTP 400). By default only the attributes received are written
(`Product\Action::updateAttributes`, which respects each attribute's global/website/store scope),
followed by a reindex of that product (price, EAV, flat, fulltext) for the indexers that are not
in "Update by Schedule" mode. The product is saved as a whole, at the resolved store id, only
when the payload carries `url_key` (formatted, checked for uniqueness, URL rewrites regenerated),
categories (`category_ids` / `category`), or images that are not already in the gallery. Select
option labels and category names are matched in the admin store first, then in the resolved
store view. The update response includes `store_id`, `scope` (`all` | `store`) and
`saved_full` (`true` | `false`); the test endpoint reports `write_scope`.

> **Nota (Magento 2.3):** cada escritura con `updateAttributes` limpia la etiqueta de caché
> genérica del catálogo (`cat_p`), así que las sincronizaciones masivas invalidan la FPC del
> catálogo en Magento 2.3. Usar la cola de indexación "Update by Schedule" y Varnish.

```bash
bin/magento config:set girofeeds_productapi/general/write_scope all
bin/magento cache:clean config
```

### Reparar valores por vista (girofeeds:scope:repair)

Las versiones anteriores a 1.25.0 guardaban el producto entero en la vista de la ruta REST
(p. ej. `default`), así que esa vista quedó con un valor propio para casi todos los atributos y
deja de reflejar lo que se edita en **All Store Views**. Este comando lleva a All Store Views
(`store_id 0`) los valores propios de una vista y después los elimina de la vista.

```bash
bin/magento girofeeds:scope:repair --store=default [--dry-run] [--apply] [--exclude=attr1,attr2] [--only=attr1,attr2] [--promote-global] [--batch-size=500]
bin/magento girofeeds:scope:restore --backup=<sufijo>
```

- Solo atributos de producto de las tablas EAV `varchar`, `text`, `int`, `decimal` y
  `datetime`, y solo las filas de la vista indicada (`--store` = código o id; `0`, `admin` y
  `all` se rechazan). No toca galería de imágenes, stock, precios por grupo ni `url_rewrite`.
- Por producto y atributo: si el valor de la vista difiere del global (o no hay global), pasa a
  ser el valor global; después se elimina la fila de la vista. Si es igual, solo se elimina.
- `--exclude`: atributos que **no** se promocionan (All Store Views conserva su valor; la vista
  solo deja de pisarlo). Excepción: los productos que no tienen valor global sí se promocionan,
  para que el valor no desaparezca de todas las vistas. `--only`: limita la operación a esos
  atributos.
- Atributos con ámbito **Global**: Magento solo lee su valor de All Store Views, así que las
  filas de la vista no se ven en la tienda. Por defecto solo se eliminan (con respaldo), sin
  promocionar. `--promote-global` las promociona como al resto: úsalo solo si el valor de la
  vista es el que debe pasar a verse. Si además están en `--exclude`, sin `--promote-global`
  siguen siendo solo limpieza.
- Sin `--apply` es una simulación que no escribe nada.
- Solo Magento Open Source: en Adobe Commerce con staging (`row_id`) aborta sin hacer nada.

Protocolo:

1. **Copia de seguridad completa de la BD** (en producción, además del respaldo del comando).
2. Simulación: `bin/magento girofeeds:scope:repair --store=default`. Revisa la tabla (por
   atributo: filas en la vista, cuántas tienen un global distinto, cuántas no tienen global,
   ámbito y acción) y los ejemplos (id · valor de la vista · valor global). Decide con el
   cliente qué atributos van en `--exclude`: los que se han editado en All Store Views después
   del guardado erróneo (p. ej. `is_discontinued`, `availability`, `special_from_date`,
   `special_to_date`). Revisa los atributos marcados «ámbito Global: solo limpieza» y los
   avisos sobre productos sin valor global.
3. Aplicar fuera de horario, con la sincronización de Girofeeds y los cron que editan productos
   en pausa (las filas de vista escritas durante la ejecución en productos ya procesados no se
   reparan):
   `bin/magento girofeeds:scope:repair --store=default --exclude=... --apply`.
   Cada lote de `--batch-size` productos de un atributo es una transacción; si un lote falla se
   revierte y el comando se detiene. Antes de escribir guarda las filas de la vista que borra,
   los valores globales que sobrescribe y las filas globales que crea en
   `girofeeds_scope_backup_<tipo>_<sufijo>` (sufijo = fecha-hora `AAAAMMDDHHMMSS`, que el comando
   imprime al terminar o al fallar).
4. Reindexar y limpiar caché:

   ```bash
   bin/magento indexer:reindex catalog_product_attribute catalog_product_price catalogsearch_fulltext catalog_product_flat
   bin/magento cache:flush
   ```

   (`catalog_product_flat` solo si ese indexador existe en la instalación.)
5. Revisar en la tienda productos de muestra y en la BD que la vista ya no tiene filas propias.

Deshacer: `bin/magento girofeeds:scope:restore --backup=<sufijo>` repone las filas de la vista,
los valores globales sobrescritos y elimina las filas globales que creó la reparación (los
cambios hechos en esos valores después de la reparación se pierden). Es idempotente; si se han
hecho varias reparaciones, restaurar en orden inverso. Después, reindexar y `cache:flush` como
en el paso 4. Las tablas de respaldo no se borran solas: eliminarlas a mano cuando ya no hagan
falta.

## Create product

Girofeeds can create new **simple** products in the store (capability `createproduct`, reported
by the test endpoint). One product per request:

| Method | URL |
|--------|-----|
| POST | `/rest/all/V1/girofeeds/createproduct?key={key}` |
| POST | `/girofeeds/createproduct/index?key={key}` |

The product is always created at **All Store Views** (`store_id 0`). A `store` query parameter
or a `store` / `store_id` field is **ignored on create** and only produces the warning
`store: ignored on create (products are created at store 0)`.

Payload (same field names and aliases as `updateproduct`; `sku`, `name` and `price` are
required):

```json
{
  "sku": "GF-0001",
  "name": "Camiseta básica algodón",
  "price": 19.9,
  "special_price": 14.9,
  "description": "<p>Camiseta de algodón 100%.</p>",
  "short_description": "Camiseta de algodón",
  "ean": "8412345678901",
  "brand": "Girofeeds",
  "color": "Rojo",
  "weight": 0.2,
  "qty": 25,
  "category": "Ropa > Camisetas",
  "attribute_set": "Default",
  "status": 1,
  "images": [
    { "filename": "camiseta-roja.jpg", "mimeType": "image/jpeg", "content": "<base64>" }
  ]
}
```

Response (HTTP 200):

```json
{
  "status": "created",
  "message": "Product 2045 created successfully",
  "product_id": 2045,
  "sku": "GF-0001",
  "url_key": "camiseta-basica-algodon",
  "store_id": 0,
  "warnings": [],
  "images": { "added": 1, "failed": 0 }
}
```

- `status`: `created`, `exists` (the product was already there; nothing is written) or `error`.
  `failed_fields` appears when a field could not be applied (e.g. a select option that does not
  exist; options are never created). `error` responses carry `errors` and, when known, `sku`.
- **Idempotent**: before writing, the SKU is looked up and, when **Data > EAN attribute** is
  configured and the request has `ean`, the EAN too. A match returns `exists` with the existing
  `product_id`, its real `sku` and `url_key`.
- `url_key`: the one received or the name, formatted. If another product already uses it at
  store 0, it is suffixed with `-<sku>`. If the save still hits a URL rewrite collision (another
  store view, a category or CMS path), the product is rebuilt and saved once more with the
  suffix, with the warning `url_key: collision, suffixed`.
- Categories: `category_ids` or `category` paths (`A > B`), like `updateproduct`. A path that
  does not exist is skipped with a warning; the product is still created.
- Stock: `qty` (default 0) and `manage_stock` (default 1); `is_in_stock` follows `qty > 0`.
- After the save the product row is reindexed (price, EAV, flat, fulltext) for the indexers that
  are not in "Update by Schedule" mode.

HTTP codes: 400 invalid JSON, 401/403 API key, 422 validation (`missing: sku|name|price`,
`price` ≤ 0, `attribute set not found`), 429 rate limit, 500 save or internal error.

**Limits**

- `images`: list of `{filename, mimeType, content}` with base64 `content`; a single object is
  also accepted. At most **10** images (the rest are skipped with a warning), **10 MB** each
  decoded, mimeType `image/jpeg`, `image/jpg`, `image/png`, `image/webp` or `image/gif`. The
  first image is the base image (image, small_image, thumbnail); the rest go to the gallery.
  Invalid entries are counted in `images.failed` with a warning `image #N: …`.
- Only simple products (`type_id` = `simple`).
- The request body must fit the web server and PHP limits (`client_max_body_size`,
  `post_max_size`): 10 images of several MB in base64 can exceed the defaults.

**Defaults** (**General Settings > Product Creation**, default scope only):

| Setting | Path | Default | Used when |
|---------|------|---------|-----------|
| Attribute Set | `girofeeds_productapi/create/attribute_set` | `4` (Default) | the request has no `attribute_set` (name or id) |
| Website IDs | `girofeeds_productapi/create/website_ids` | empty = website of the default store view | the request has no valid `website_ids` |
| Tax Class | `girofeeds_productapi/create/tax_class_id` | `2` (Taxable Goods) | the request has no valid `tax_class_id` |
| Visibility | `girofeeds_productapi/create/visibility` | `4` (Catalog, Search) | the request has no `visibility` |

Unknown `website_ids` (request or config) are dropped with the warning
`website_ids: unknown id N`; if none remain, the website of the default store view is used. The
test endpoint reports these values in `create_defaults`.

```bash
bin/magento config:set girofeeds_productapi/create/attribute_set 4
bin/magento config:set girofeeds_productapi/create/website_ids 1
bin/magento config:set girofeeds_productapi/create/tax_class_id 2
bin/magento config:set girofeeds_productapi/create/visibility 4
bin/magento cache:clean config
```

### Prueba en testo3

1. Instalar la versión 1.26.0 (ZIP `girofeeds-magento-1.26.0.zip` en
   `app/code/Girofeeds/ProductApi`) y ejecutar:

   ```bash
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```

2. `GET /rest/all/V1/girofeeds/test?key=<key>` → `version` 1.26.0, `capabilities`
   incluye `createproduct` y `create_defaults` muestra los valores configurados.
3. Crear un producto con **2 imágenes** (`POST /rest/all/V1/girofeeds/createproduct?key=<key>`,
   SKU nuevo, `qty` > 0) → `status: created`, `images.added: 2`. En el admin, en **All Store
   Views**: el producto existe con nombre, precio, website, las 2 imágenes en la galería (la
   primera como base) y stock con la cantidad enviada y «En stock». Comprobar también que se ve
   en la tienda (URL `url_key`.html o búsqueda).
4. Repetir **la misma petición** → `status: exists`, mismo `product_id`, sin cambios en el
   producto ni imágenes duplicadas.
5. SKU nuevo con `"category": "Ruta > Inexistente"` → `status: created` con un aviso de
   categoría no encontrada en `warnings` (`Category path 'Ruta > Inexistente' not found…`).
6. SKU nuevo con `"attribute_set": "NoExiste"` → HTTP 422, `status: error`,
   `message: attribute set not found`; no se crea nada.
7. Revisar `var/log` (log de Girofeeds) y borrar los productos de prueba.

## CLI Commands

```bash
bin/magento girofeeds:token:generate        # Generate API key
bin/magento girofeeds:token:generate --force # Regenerate API key
bin/magento girofeeds:selftest              # Run self-test
bin/magento girofeeds:scope:repair --store=default          # Report own store view values (dry-run)
bin/magento girofeeds:scope:repair --store=default --apply  # Promote them to All Store Views (with backup)
bin/magento girofeeds:scope:restore --backup=<suffix>       # Undo a repair
bin/magento config:set girofeeds_productapi/create/<field> <value>  # Product creation defaults (see Create product)
```

## API Endpoints

### Frontend Routes

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/girofeeds/feed/json?id={storeId}&token={key}` | Product feed |
| POST | `/girofeeds/updateproduct/index?key={key}` | Update product |
| POST | `/girofeeds/createproduct/index?key={key}` | Create product |
| GET | `/girofeeds/count/index?key={key}` | Product count |
| GET | `/girofeeds/test/index?key={key}` | Module test |

### REST API Routes

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/rest/V1/girofeeds/count` | Product count |
| GET | `/rest/V1/girofeeds/test` | Module test |
| POST | `/rest/V1/girofeeds/updateproduct` | Update product |
| POST | `/rest/V1/girofeeds/createproduct` | Create product (always at store 0; use `/rest/all/...`) |

## Compatibility

- PHP >= 7.1.3
- Magento 2.3.x / 2.4.x
