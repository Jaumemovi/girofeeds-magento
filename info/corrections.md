# Plan de Correcciones y Mejoras - Girofeeds_ProductApi

## Resumen Ejecutivo

Este documento identifica problemas críticos, bugs, vulnerabilidades de seguridad y optimizaciones necesarias en el módulo `Girofeeds_ProductApi` para Magento 2. Los problemas están categorizados por severidad y se proporciona un plan de implementación por fases.

**Estado del módulo:** En proceso de transformación desde `Magmodules_Channable`
**Fecha de análisis:** 2026-02-08
**Prioridad general:** ALTA - Múltiples problemas críticos bloquean la funcionalidad correcta

---

## Índice de Problemas

1. [Problemas Críticos del Feed](#1-problemas-críticos-del-feed)
2. [Problemas de Config Paths](#2-problemas-de-config-paths)
3. [Bugs de Código](#3-bugs-de-código)
4. [Problemas de Seguridad](#4-problemas-de-seguridad)
5. [Problemas de Rendimiento](#5-problemas-de-rendimiento)
6. [Problemas de Logging y Debug](#6-problemas-de-logging-y-debug)
7. [Problemas de Branding](#7-problemas-de-branding)
8. [Plan de Implementación](#8-plan-de-implementación)

---

## 1. Problemas Críticos del Feed

### 1.1 URL del Feed con Formato Incorrecto

**Ubicación:** `Helper/Feed.php:58`

**Problema:**
```php
// ACTUAL (INCORRECTO)
return $url . sprintf('girofeeds/feed/json/id/%s/token/%s', $storeId, $token);
// Genera: https://example.com/girofeeds/feed/json/id/1/token/ABC123
```

**Debe ser:**
```php
// CORRECTO según especificación
return $url . sprintf('girofeeds/feed/json?store=%s&key=%s', $storeCode, $apiKey);
// Genera: https://example.com/girofeeds/feed/json?store=default&key=ABC123
```

**Impacto:** 🔴 CRÍTICO
- El endpoint no funciona correctamente con Girofeeds app
- Usa ID numérico en lugar de store code
- Usa `token` en path en lugar de query param `key`
- Incompatibilidad total con la especificación

**Archivos afectados:**
- `Helper/Feed.php:58`
- `Controller/Feed/Json.php` (necesita actualizar parsing de parámetros)

---

### 1.2 Respuesta del Feed con Formato Incorrecto

**Ubicación:** `Model/Generate.php:103-115`

**Problema:**
```php
// ACTUAL (INCORRECTO) - Devuelve objeto con config y products
$return['config'] = $this->feedHelper->getFeedSummary(...);
$return['products'] = $feed;
```

**Debe ser:**
```php
// CORRECTO según especificación - Array plano de productos
return $feed; // Solo el array de productos
```

**Impacto:** 🔴 CRÍTICO
- La respuesta incluye un objeto `config` con metadata que no está en la spec
- Girofeeds app espera un array plano JSON de productos
- La estructura actual rompe la compatibilidad

**Ejemplo de respuesta actual (incorrecta):**
```json
{
  "config": {
    "system": "Magento 2",
    "version": "2.0.0",
    "products_total": 1500,
    ...
  },
  "products": [...]
}
```

**Ejemplo de respuesta correcta:**
```json
[
  {
    "id": "1234",
    "sku": "PROD-001",
    "name": "Product Name",
    ...
  }
]
```

---

### 1.3 Paginación Incorrecta

**Ubicación:** `Model/Generate.php:48-53`, `Controller/Feed/Json.php`

**Problema:**
- Usa parámetro `page` con número de página (1, 2, 3...)
- La especificación requiere parámetro `limit` con formato `offset,count`

**Debe implementar:**
```php
// Ejemplo: limit=200,100 significa "desde el registro 200, devolver 100 productos"
$limitParam = $request->getParam('limit', '0,100');
list($offset, $count) = explode(',', $limitParam);
$collection->getSelect()->limit((int)$count, (int)$offset);
```

**Impacto:** 🔴 CRÍTICO
- Incompatibilidad con la API esperada por Girofeeds

---

## 2. Problemas de Config Paths

### 2.1 Nombre de Path Incorrecto para API Key

**Ubicación:** `Helper/General.php:33`

**Problema:**
```php
const XPATH_TOKEN = 'girofeeds_productapi/general/token';
```

**Debe ser:**
```php
const XPATH_API_KEY = 'girofeeds_productapi/general/api_key';
```

**Impacto:** 🟡 ALTO
- Inconsistencia entre código y especificación
- Confusión entre "token" y "api_key"
- Afecta configuración en admin y validación de requests

**Archivos afectados:**
- `Helper/General.php:33`
- `Setup/Patch/Data/CreateToken.php`
- `Console/Command/GenerateToken.php`
- `etc/adminhtml/system/general.xml`
- `etc/config.xml`

---

### 2.2 Falta Config Path para `limit` (Products Per Page)

**Ubicación:** `etc/adminhtml/system/general.xml`

**Problema:**
- La especificación define un campo "Products Per Page" con default 100
- Este campo no está implementado en la configuración
- El límite se toma de otro path que puede no existir

**Debe añadir:**
```xml
<field id="limit" translate="label comment" type="text" sortOrder="30" showInDefault="1" showInWebsite="1" showInStore="1">
    <label>Products Per Page</label>
    <comment>Maximum products returned per request (default pagination)</comment>
    <validate>validate-number validate-greater-than-zero</validate>
</field>
```

**Impacto:** 🟡 MEDIO
- La paginación puede no funcionar correctamente
- Falta opción de configuración esperada

---

## 3. Bugs de Código

### 3.1 Incorrect Type Declaration en UpdateProduct

**Ubicación:** `Model/UpdateProduct.php:25-38`

**Problema:**
```php
private RequestInterface $request;              // PHP 7.4+ typed properties
private ProductRepositoryInterface $productRepository;
private StoreManagerInterface $storeManager;
// ... más propiedades tipadas
```

**Contexto:**
- El proyecto requiere PHP >= 8.1 según `composer.json`
- ✅ Las typed properties están bien para PHP 8.1+
- ⚠️ PERO: Magento 2.4.4 soporta PHP 7.4, puede haber incompatibilidad

**Recomendación:**
- Verificar versión mínima de Magento soportada
- Si se soporta 2.4.4, eliminar typed properties y usar DocBlocks
- Si solo se soporta 2.4.6+, mantener typed properties

**Impacto:** 🟡 MEDIO
- Puede causar errores en instalaciones con PHP 7.4

---

### 3.2 Falta Manejo de HTML en Descripciones

**Ubicación:** `Model/UpdateProduct.php:222-236` (método `processTextAttributes`)

**Problema:**
```php
$product->setData($magentoAttr, $resolved[$field]);
// No hay sanitización ni validación de HTML
```

**Riesgo:**
- La especificación dice "HTML allowed" para `description` y `short_description`
- NO se debe sanitizar (debe aceptarse tal cual desde Girofeeds)
- PERO tampoco hay validación de HTML malformado

**Recomendación:**
- Aceptar HTML tal cual (comportamiento correcto)
- Añadir try/catch para HTML extremadamente malformado
- Documentar que la responsabilidad de HTML seguro es de Girofeeds

**Impacto:** 🟢 BAJO
- Comportamiento actual es correcto según spec
- Mejora opcional: mejor manejo de errores

---

### 3.3 Race Condition en Multiple Saves

**Ubicación:** `Model/UpdateProduct.php:154, 179`

**Problema:**
```php
// Primera vez
$this->productRepository->save($product);  // Línea 154

// Segunda vez (para categorías)
$this->productRepository->save($product);  // Línea 179
```

**Riesgo:**
- Se guarda el producto dos veces
- Puede causar problemas con indexers
- Ineficiente (doble escritura DB)
- Posible race condition si hay observers

**Recomendación:**
```php
// Guardar solo una vez al final después de todas las modificaciones
$needsCategorySave = array_key_exists('category_ids', $resolved)
                     || array_key_exists('category', $resolved);

// Procesar categorías ANTES del primer save
if ($needsCategorySave) {
    $categoryWarnings = $this->categoryUpdater->updateCategories($product, $resolved);
    $warnings = array_merge($warnings, $categoryWarnings);
}

// Un solo save final
$this->productRepository->save($product);
```

**Impacto:** 🟡 MEDIO
- Impacto en rendimiento
- Posibles problemas con indexación

---

## 4. Problemas de Seguridad

### 4.1 Validación de API Key sin Timing-Safe Comparison

**Ubicación:** `Model/ApiKeyValidator.php` (asumido, no leído)

**Problema:**
Si la validación usa comparación normal:
```php
// INSEGURO
if ($providedKey === $storedKey) {
    return true;
}
```

**Debe usar:**
```php
// SEGURO - timing-safe comparison
if (hash_equals($storedKey, $providedKey)) {
    return true;
}
```

**Impacto:** 🔴 ALTO
- Vulnerable a timing attacks
- Un atacante puede adivinar el API key carácter por carácter midiendo tiempos de respuesta

**Recomendación:**
- SIEMPRE usar `hash_equals()` para comparar secretos
- Esto está especificado en el plan de implementación pero debe verificarse

---

### 4.2 API Key Expuesto en URLs

**Ubicación:** `Helper/Feed.php:58`

**Problema:**
```php
// API key en la ruta de URL (MAL)
return $url . sprintf('girofeeds/feed/json/id/%s/token/%s', $storeId, $token);
```

**Riesgos:**
- El API key aparece en logs del servidor web
- Aparece en logs de proxies/CDN
- Aparece en historial del navegador
- Puede ser capturado por JavaScript de terceros

**Debe ser:**
```php
// API key como query parameter (sigue siendo visible pero es estándar para APIs)
return $url . sprintf('girofeeds/feed/json?store=%s&key=%s', $storeCode, $apiKey);
```

**Nota:** Esto ya se mencionó en 1.1, pero aquí enfatizamos el aspecto de seguridad.

**Impacto:** 🔴 ALTO
- Exposición de credenciales en logs

---

### 4.3 Sin Validación de Content-Type en Update

**Ubicación:** `Model/UpdateProduct.php:86-87`

**Problema:**
```php
$body = $this->request->getContent();
$data = json_decode($body, true);
```

**Falta validar:**
```php
$contentType = $this->request->getHeader('Content-Type');
if (!$contentType || strpos($contentType, 'application/json') === false) {
    return $this->buildResponse('error', 'Content-Type must be application/json', ...);
}
```

**Impacto:** 🟢 BAJO
- Mejor práctica de seguridad
- Previene requests malformados

---

### 4.4 Sin Límite de Tamaño de Request Body

**Ubicación:** `Model/UpdateProduct.php:86`

**Problema:**
```php
$body = $this->request->getContent();
// No hay límite de tamaño
```

**Riesgo:**
- Ataque DoS enviando JSON gigante (ej. 1GB)
- Puede saturar memoria PHP

**Recomendación:**
```php
$body = $this->request->getContent();
if (strlen($body) > 1048576) { // 1MB limit
    return $this->buildResponse('error', 'Request body too large', ...);
}
```

**Impacto:** 🟡 MEDIO
- Protección DoS

---

## 5. Problemas de Rendimiento

### 5.1 N+1 Query en Category Loading

**Ubicación:** `Service/Category/CategoryData.php` (asumido por análisis de `Generate.php:76`)

**Problema:**
```php
$config['categories'] = $this->categoryData->load($products, $parents, $storeId);
```

**Riesgo típico en este tipo de operaciones:**
- Si carga categorías producto por producto
- Puede causar cientos de queries para 100 productos

**Debe implementar:**
- Precarga todas las categorías en una sola query
- Uso de colecciones de Magento correctamente
- Cacheo de paths de categorías

**Impacto:** 🔴 ALTO
- Feed puede ser extremadamente lento con muchos productos

**Verificación necesaria:**
- Analizar `Service/Category/CategoryData.php` para confirmar
- Usar Magento Profiler para medir queries

---

### 5.2 Carga de SKUs en Memoria Completa

**Ubicación:** `Model/Generate.php:155`

**Problema:**
```php
$this->productHelper->getInventoryData()->load($products->getColumnValues('sku'), $config);
```

**Análisis:**
- `getColumnValues('sku')` carga TODOS los SKUs del batch en un array en memoria
- Para 1000 productos puede ser 20-30KB
- Para 10000 productos puede ser 200-300KB
- No es crítico pero podría optimizarse

**Recomendación:**
- Aceptable para batches de hasta 500 productos
- Para batches mayores considerar paginación interna

**Impacto:** 🟢 BAJO
- Solo afecta en instalaciones muy grandes

---

### 5.3 Environment Emulation sin Cache

**Ubicación:** `Model/Generate.php:58, 118`

**Problema:**
```php
$this->appEmulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
// ... procesamiento ...
$this->appEmulation->stopEnvironmentEmulation();
```

**Análisis:**
- `startEnvironmentEmulation` tiene overhead
- Se llama en CADA request del feed
- No hay cacheo del ambiente

**Impacto:** 🟡 MEDIO
- Overhead de ~10-50ms por request
- Multiplicado por miles de requests puede sumar

**Recomendación:**
- Difícil de optimizar sin romper Magento
- Aceptar el overhead como necesario
- Documentar como limitación conocida

---

## 6. Problemas de Logging y Debug

### 6.1 Logger Incorrecto en General Helper

**Ubicación:** `Helper/General.php:62-63, 294-297`

**Problema:**
```php
/**
 * @var LogRepository
 */
private $logger;

public function addTolog($type, $data)
{
    $this->logger->addDebugLog($type, $data);
}
```

**Análisis:**
- Usa un `LogRepository` custom en lugar del logger standard de Magento
- El método `addDebugLog` no es estándar PSR-3
- Dificulta debugging

**Debe usar:**
```php
use Psr\Log\LoggerInterface;

private LoggerInterface $logger;

public function addToLog($level, $message, array $context = [])
{
    $this->logger->log($level, $message, $context);
}
```

**Impacto:** 🟡 MEDIO
- Logging no estándar
- Dificulta integración con herramientas de monitoreo

---

### 6.2 Sin Logging de API Requests

**Ubicación:** Falta implementar en `Model/ApiLogger.php` (si existe)

**Debe implementar:**
- Log de cada API request con:
  - Timestamp
  - IP address
  - Endpoint llamado
  - API key hash (primeros 8 caracteres)
  - HTTP status code de respuesta
  - Tiempo de ejecución en ms
  - Errores si los hay

**Formato sugerido:**
```
[2026-02-08 14:23:45] girofeeds.INFO: API Request {"ip":"203.0.113.42","endpoint":"feed","key_hash":"a1b2c3d4","status":200,"time_ms":345}
```

**Impacto:** 🟡 ALTO
- Esencial para debugging en producción
- Necesario para detectar abusos
- Requerido para auditoría

---

### 6.3 Sin Debug Mode para Development

**Problema:**
- No hay manera de activar logging verboso
- Dificulta desarrollo y debugging

**Debe implementar:**
```xml
<!-- etc/adminhtml/system/general.xml -->
<field id="debug_mode" translate="label" type="select" sortOrder="100">
    <label>Debug Mode</label>
    <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
    <comment>Enable verbose logging for debugging (do not use in production)</comment>
</field>
```

**Impacto:** 🟢 MEDIO
- Quality of life para desarrollo

---

## 7. Problemas de Branding

### 7.1 Copyright Headers Antiguos

**Ubicación:** `Helper/General.php:2-5` y múltiples archivos

**Problema:**
```php
/**
 * Copyright © 2019 Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
```

**Debe ser:**
```php
/**
 * Copyright © 2026 Girofeeds. All rights reserved.
 * See COPYING.txt for license details.
 */
```

**Impacto:** 🟢 BAJO
- Problema cosmético pero importante para profesionalismo

**Archivos afectados:**
- Todos los archivos PHP del módulo (~50 archivos)

---

### 7.2 Module Code Constant Correcto pero Referencias Antiguas

**Ubicación:** `Helper/General.php:30`

**Análisis:**
```php
const MODULE_CODE = 'Girofeeds_ProductApi';  // ✅ CORRECTO
```

Esto está bien, pero verificar que no haya referencias hardcoded a "Channable" o "Magmodules" en:
- Strings de UI
- Mensajes de error
- Logs
- Comentarios de código

**Impacto:** 🟢 BAJO

---

## 8. Plan de Implementación

### Fase 1: Correcciones Críticas (BLOQUEANTES)

**Prioridad:** 🔴 URGENTE
**Tiempo estimado:** 2-3 horas
**Bloqueante para:** Testing básico de funcionalidad

#### Tareas:

1. **Corregir URL del feed** (`Helper/Feed.php:58`)
   - Cambiar de formato path a query params
   - Usar `store` code en lugar de `id`
   - Usar `key` en lugar de `token`

2. **Corregir formato de respuesta del feed** (`Model/Generate.php:103-115`)
   - Eliminar wrapper `config`
   - Devolver array plano de productos
   - Mantener metadata solo para modo debug

3. **Implementar paginación correcta**
   - Cambiar de `page` a `limit=offset,count`
   - Actualizar `Controller/Feed/Json.php`
   - Actualizar `Model/Generate.php:48-67`

4. **Corregir config path del API key**
   - Renombrar `XPATH_TOKEN` a `XPATH_API_KEY`
   - Actualizar todas las referencias
   - Migrar valor en `Setup/Patch/Data`

#### Test de validación Fase 1:
```bash
# El feed debe responder correctamente con este formato
curl "https://example.com/girofeeds/feed/json?store=default&key=ABC123&limit=0,10"

# Respuesta esperada: array plano JSON con 10 productos
[{"id":"1","sku":"PROD-001",...}, ...]
```

---

### Fase 2: Seguridad y Estabilidad

**Prioridad:** 🟡 ALTA
**Tiempo estimado:** 3-4 horas
**Bloqueante para:** Producción

#### Tareas:

1. **Implementar validación timing-safe** (`Model/ApiKeyValidator.php`)
   ```php
   if (!hash_equals($storedKey, $providedKey)) {
       return ['valid' => false, 'message' => 'Invalid API key'];
   }
   ```

2. **Añadir validaciones de seguridad en UpdateProduct**
   - Content-Type validation
   - Request body size limit (1MB)
   - JSON validation mejorada

3. **Eliminar double-save en UpdateProduct**
   - Procesar categorías antes del primer save
   - Un solo `save()` al final
   - Mejora rendimiento + estabilidad

4. **Implementar API Request Logging completo**
   - Log de cada request (feed, update, count, test)
   - IP, timestamp, endpoint, status, tiempo
   - Rotación de logs

#### Test de validación Fase 2:
```bash
# El rate limiter debe funcionar
for i in {1..70}; do
  curl "https://example.com/girofeeds/feed/json?store=default&key=ABC123&limit=0,1"
done
# Request 61-70 deben devolver 429 Too Many Requests

# Los logs deben contener las peticiones
tail -n 100 var/log/girofeeds.log
```

---

### Fase 3: Optimización y Polish

**Prioridad:** 🟢 MEDIA
**Tiempo estimado:** 2-3 horas
**Bloqueante para:** Performance óptimo

#### Tareas:

1. **Optimizar N+1 queries de categorías**
   - Analizar `Service/Category/CategoryData.php`
   - Implementar batch loading
   - Benchmarking antes/después

2. **Mejorar logging infrastructure**
   - Migrar a PSR-3 LoggerInterface
   - Implementar debug mode config
   - Structured logging (JSON)

3. **Actualizar branding completo**
   - Copyright headers en todos los archivos
   - Verificar no hay "Channable" o "Magmodules"
   - Actualizar i18n strings

4. **Typed properties: decisión final**
   - Definir versión mínima de Magento soportada
   - Si 2.4.4: eliminar typed properties
   - Si 2.4.6+: mantener y documentar

5. **Documentación de código**
   - PHPDoc completo en clases principales
   - Ejemplos de uso en comentarios
   - README técnico

#### Test de validación Fase 3:
```bash
# Performance test: 1000 productos en <5 segundos
time curl "https://example.com/girofeeds/feed/json?store=default&key=ABC123&limit=0,1000"

# Verificar queries con Magento Profiler
bin/magento dev:profiler:enable
# Cargar feed y verificar <500 queries para 100 productos
```

---

## Matriz de Prioridades

| ID | Problema | Severidad | Prioridad | Fase | Esfuerzo |
|----|----------|-----------|-----------|------|----------|
| 1.1 | URL feed incorrecta | CRÍTICO | 🔴 P0 | 1 | 30min |
| 1.2 | Formato respuesta feed | CRÍTICO | 🔴 P0 | 1 | 20min |
| 1.3 | Paginación incorrecta | CRÍTICO | 🔴 P0 | 1 | 45min |
| 2.1 | Config path API key | ALTO | 🔴 P0 | 1 | 1h |
| 2.2 | Falta config limit | MEDIO | 🟡 P1 | 2 | 15min |
| 3.1 | Typed properties | MEDIO | 🟡 P2 | 3 | 15min |
| 3.3 | Double save race | MEDIO | 🟡 P1 | 2 | 45min |
| 4.1 | Timing-safe comparison | ALTO | 🔴 P0 | 2 | 15min |
| 4.2 | API key en URL | ALTO | 🔴 P0 | 1 | (con 1.1) |
| 4.3 | Content-Type validation | BAJO | 🟢 P2 | 2 | 10min |
| 4.4 | Request size limit | MEDIO | 🟡 P1 | 2 | 10min |
| 5.1 | N+1 categorías | ALTO | 🟡 P1 | 3 | 2h |
| 5.2 | SKUs en memoria | BAJO | 🟢 P3 | 3 | - |
| 6.1 | Logger no estándar | MEDIO | 🟡 P2 | 3 | 1h |
| 6.2 | Sin API logging | ALTO | 🟡 P1 | 2 | 1h |
| 6.3 | Sin debug mode | MEDIO | 🟢 P2 | 3 | 30min |
| 7.1 | Copyright headers | BAJO | 🟢 P3 | 3 | 30min |

**Leyenda:**
- 🔴 P0: Bloqueante - debe completarse antes de testing
- 🟡 P1: Alta - debe completarse antes de producción
- 🟢 P2: Media - mejora de calidad
- 🟢 P3: Baja - polish final

---

## Preguntas Pendientes de Aclaración

Antes de implementar ciertas correcciones, necesitamos respuesta a:

### Q1: Versión mínima de Magento soportada
**Contexto:** El código usa typed properties (PHP 7.4+) pero Magento 2.4.4 soporta PHP 7.4
**Pregunta:** ¿Cuál es la versión mínima de Magento 2 que debe soportarse?
- Si 2.4.4-2.4.5 → Eliminar typed properties
- Si 2.4.6+ → Mantener typed properties

### Q2: Metadata del feed en debug mode
**Contexto:** La spec dice que el feed debe devolver array plano, pero el código actual devuelve metadata
**Pregunta:** ¿Deseas mantener la metadata bajo un flag de debug?
```php
if ($debugMode) {
    return ['config' => $metadata, 'products' => $feed];
} else {
    return $feed;
}
```

### Q3: Nivel de logging en producción
**Contexto:** El logging puede tener impacto en rendimiento
**Pregunta:** ¿Qué nivel de detalle se requiere en logs de producción?
- Básico: Solo errores
- Medio: Errores + warnings + cada request API (IP, endpoint, status)
- Completo: Todo lo anterior + debug de cada actualización de producto

---

## Métricas de Éxito

Después de implementar todas las fases, el módulo debe cumplir:

### Funcionalidad
- ✅ Feed devuelve JSON plano con formato correcto
- ✅ Paginación con formato `limit=offset,count` funciona
- ✅ Update endpoint acepta todos los campos de la especificación
- ✅ API key validation con timing-safe comparison
- ✅ Rate limiting funciona correctamente (60 req/min por defecto)

### Rendimiento
- ✅ Feed de 100 productos: < 2 segundos
- ✅ Feed de 1000 productos: < 10 segundos
- ✅ Update de 1 producto: < 500ms
- ✅ Máximo 500 queries DB para feed de 100 productos

### Seguridad
- ✅ API key no aparece en logs web server
- ✅ Todas las comparaciones de secrets usan `hash_equals()`
- ✅ Request body size limitado a 1MB
- ✅ Logs no exponen datos sensibles

### Calidad de Código
- ✅ Sin referencias a "Channable" o "Magmodules"
- ✅ Copyright headers actualizados
- ✅ PHPDoc completo en clases públicas
- ✅ Sin código muerto (dead code)

---

## Notas Finales

Este documento debe actualizarse a medida que se implementan las correcciones. Cada problema debe marcarse como:
- [ ] Pendiente
- [WIP] En progreso
- [✅] Completado
- [❌] No aplicable / Rechazado

**Próximos pasos:**
1. Revisar y aprobar este plan
2. Responder las 3 preguntas pendientes
3. Comenzar Fase 1 (correcciones críticas)
4. Testing de cada fase antes de continuar

**Contacto para preguntas:**
- Documentación técnica: `info/specification.md`
- Plan de implementación: `info/implementation-plan.md`
