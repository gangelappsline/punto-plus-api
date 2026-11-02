# Punto Plus API

API REST de **fidelidad digital para pequeñas empresas**: tarjetas de sellos, recompensas,
promociones y canjes. Pensada para ser consumida por una app móvil (clientes) y un panel de
negocio (web o app), con autenticación OAuth2 completa.

| | |
|---|---|
| Framework | **Laravel 13.x** |
| PHP | **8.3 – 8.5** |
| Auth | **Laravel Passport 13** (OAuth2: Authorization Code + PKCE, password grant, refresh tokens) |
| Base de datos | **MySQL 8+** o **PostgreSQL 14+** |
| Claves primarias | **UUID** en negocio, tarjetas, sellos y canjes |
| Idioma | Dominio y respuestas **en español** |

---

## 1. Requisitos

- PHP 8.3+ con extensiones `pdo`, `mbstring`, `openssl`, `fileinfo`, `json`, `curl`.
- Composer 2.
- MySQL 8+ / PostgreSQL 14+ (o SQLite para desarrollo rápido).
- Para exportar el QR de negocio como PNG/WebP: extensión PHP `gd`; WebP requiere que GD tenga `imagewebp`. La vista previa SVG no requiere GD.

## 2. Instalación

```bash
git clone <repo> punto-plus-api
cd punto-plus-api

composer install
cp .env.example .env

# Base de datos
mysql -e 'CREATE DATABASE punto_plus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
# (ajusta DB_* en .env)

# Todo en uno: app key + claves/tablas de Passport + migraciones + clientes OAuth2 + demo
composer run setup
```

`composer run setup` ejecuta, en orden:

```bash
php artisan key:generate --ansi
php artisan passport:install --no-interaction   # claves RSA + migraciones oauth_* + cliente personal
php artisan migrate --force
php artisan punto-plus:oauth-clients --write-env # cliente PKCE móvil + cliente password (escribe el secret en .env)
php artisan db:seed --force                      # datos de demostración
```

Pasos manuales equivalentes:

```bash
php artisan key:generate
php artisan passport:install
php artisan migrate
php artisan punto-plus:oauth-clients --write-env
php artisan db:seed
php artisan storage:link          # sirve los assets subidos (logo, fondo, sello)
```

> `passport:install` publica además las migraciones de Passport en `database/migrations`
> (ya incluidas en el repositorio, así que `php artisan migrate` funciona desde el primer minuto).

### Levantar el servidor

```bash
composer run serve        # http://127.0.0.1:8000
```

Documentación de la API en [`public/docs/openapi.yaml`](public/docs/openapi.yaml)
(impórtala en Postman, Insomnia o Swagger UI: `GET /` devuelve la URL).

## 3. Autenticación OAuth2

Todos los tokens los emite Passport 13. Los scopes son **`cliente`**, **`negocio`** y **`admin`**, pero son
**informativos**: no restringen el acceso. La autorización la resuelven el middleware de rol y las Policies.

### 3.1 App móvil de clientes — Authorization Code + PKCE

```text
1. La app abre el navegador:
   GET /oauth/authorize?client_id=<pkce_id>&redirect_uri=puntoplus://oauth/callback
       &response_type=code&scope=cliente&state=<aleatorio>&code_challenge=<S256>&code_challenge_method=S256

2. El usuario inicia sesión y acepta en la pantalla de consentimiento
   (vista propia: resources/views/auth/oauth/authorize.blade.php).

3. Passport redirige a la app con ?code=<authorization_code>&state=...

4. La app canjea el código (sin client_secret, es un cliente público):
   POST /oauth/token
       grant_type=authorization_code&client_id=<pkce_id>&code=<code>
       &redirect_uri=puntoplus://oauth/callback&code_verifier=<verifier>

5. Respuesta: access_token + refresh_token + expires_in + scope
```

El cliente PKCE se crea con `php artisan punto-plus:oauth-clients`
(redirect URIs configurables con `OAUTH_MOBILE_REDIRECT_URIS`).

### 3.2 App propia / integraciones — Password Grant

`POST /api/auth/login` y `POST /api/auth/register` devuelven `access_token` + `refresh_token`
usando internamente el password grant contra `/oauth/token`. Requiere el cliente confidencial
que crea `punto-plus:oauth-clients --write-env`
(`PASSPORT_PASSWORD_CLIENT_ID` / `PASSPORT_PASSWORD_CLIENT_SECRET`).

```bash
curl -s -X POST http://localhost:8000/api/auth/login \
  -H 'Accept: application/json' \
  -d 'email=marta@puntoplus.test' -d 'password=password' -d 'device_name=iPhone'
```

```json
{
  "message": "Sesión iniciada.",
  "user": { "id": 2, "name": "Marta Ruiz", "email": "...", "role": "cliente" },
  "authorization": {
    "access_token": "eyJ0eXAiOiJKV1Qi...",
    "refresh_token": "def50200b1e...",
    "token_type": "Bearer",
    "expires_in": 86400,
    "scope": "cliente"
  }
}
```

Renovación y cierre de sesión:

```bash
curl -X POST http://localhost:8000/api/auth/refresh -d 'refresh_token=<refresh>'
curl -X POST http://localhost:8000/api/auth/logout     -H 'Authorization: Bearer <access>'
curl -X POST http://localhost:8000/api/auth/logout-all -H 'Authorization: Bearer <access>'
```

### 3.3 Vigencias

| Token | Duración por defecto | Config |
|---|---|---|
| Access token | 1 día | `OAUTH_ACCESS_TOKEN_MINUTES` |
| Refresh token | 30 días | `OAUTH_REFRESH_TOKEN_DAYS` |
| Personal access token | 90 días | `OAUTH_PERSONAL_ACCESS_DAYS` |

### 3.4 Scopes y roles

| Scope | Para quién | Qué permite |
|---|---|---|
| `cliente` | App móvil de clientes | Sus tarjetas, sellos, recompensas, canjes y promociones |
| `negocio` | Panel del negocio | Perfil, tarjetas, recompensas, promociones, escaneos y canjes |
| `admin` | Equipo Punto Plus | Acceso global (soporte y moderación) |

El scope del token sólo describe para qué se emitió: **no se valida en ninguna ruta**. La autorización
la hacen el middleware de rol (`role:business,admin`, `role:admin`) y las Policies: un token `cliente`
nunca puede leer tarjetas de otro cliente ni datos de un negocio.

## 4. Modelo de datos

| Tabla | PK | Descripción | Cascadas |
|---|---|---|---|
| `users` | id | Clientes, negocios y admins (`role`) | — |
| `categories` | id | Catálogo global (`name`, `icon`) administrado por el equipo Punto Plus | — |
| `businesses` | **uuid** | Negocio, branding (`logo_path`, `background_path`, `stamp_icon_path`) y `card_settings` (JSON) | `user_id` → null on delete |
| `business_categories` | id | Categorías globales asociadas al negocio (`business_id`, `category_id`, orden) | `business_id`/`category_id` → cascade |
| `products` | **uuid** | Productos asociados al negocio y a una categoría global, precio base y disponibilidad | `business_id`/`category_id` → cascade |
| `menu_categories` | id | Tabla histórica; los endpoints actuales ya no la usan | legado |
| `product_presentations` | **uuid** | Presentaciones del producto con precio opcional que puede heredar el precio base | `product_id` → cascade |
| `product_images` | id | De 1 a 5 imágenes públicas ordenadas por producto | `product_id` → cascade |
| `loyalty_cards` | **uuid** | Tarjeta del negocio: `type` (`visits`/`spend`), `required_stamps`, configuración de puntos (`spend_amount_per_block`, `points_per_block`, `currency`) y vigencia del ciclo (`period_type`, `period_days`) | `business_id` → **cascade** |
| `rewards` | **uuid** | Recompensa o nivel de la tarjeta: `required_stamps` (visitas) o `points_required` (puntos), `stock`, `redemptions_count` | `loyalty_card_id` → **cascade** |
| `promotions` | **uuid** | Avisos del negocio: `schedule_type` (`indefinite`/`date_range`/`time_range`), `days_of_week` (JSON ISO-8601), `start_time`/`end_time`, `starts_at`/`ends_at`, `image_path` (banner en el disco `public`) | `business_id` → **cascade** |
| `customer_cards` | **uuid** | Tarjeta de un cliente: visitas (`stamps_count`), puntos (`points_count`, `total_points_earned`), `status`, `code` y ciclo (`cycle_started_at`, `cycle_expires_at`) | `user_id`/`loyalty_card_id`/`business_id` → **cascade** |
| `stamps` | **uuid** | Compra escaneada (`source`, `purchase_amount`, `points_awarded`, `stamped_at`) | `customer_card_id` → **cascade** |
| `redemptions` | **uuid** | Canjes (`code`, `status`, `stamps_used` o `points_used`) | `customer_card_id` → **cascade** |
| `sale_tokens` | **uuid** | Token de venta del negocio: `code` (único, 8 caracteres), `expires_at` (5 min), `purchase_amount` opcional; se elimina al validarse | `business_id`/`loyalty_card_id` → **cascade** |
| `whatsapp_messages` | **uuid** | Envíos de WhatsApp, mensajes entrantes y acuses de entrega/lectura (`direction`, `status`, `wa_message_id`) | `business_id` → null on delete |
| `oauth_*` | | Tablas de Passport (clientes, tokens, códigos) | — |

Índices en `business_id`, `user_id` y `loyalty_card_id`, más índices compuestos con fechas
(`stamps.customer_card_id + stamped_at`, `customer_cards.user_id + status`, …).
`businesses`, `loyalty_cards`, `rewards`, `promotions`, `customer_cards` y `redemptions` usan
borrado lógico (`deleted_at`); `customer_cards` conserva el progreso si el cliente vuelve a unirse.

```text
User 1─* Business 1─* LoyaltyCard 1─* Reward
  │                      │              │
  │                      │              │
  └─* CustomerCard *─────┘         Redemption
        │   │
        │   └─* Stamp
        └─* Redemption
```

## 5. Endpoints

Prefijo `/api`. Respuestas de recurso envueltas en `data` (+ `meta`/`links` al paginar);
los errores devuelven `message` y un campo `error` con código estable.

### Autenticación (público)

| Método | Ruta | Descripción |
|---|---|---|
| `POST` | `/api/auth/register` | Alta de `cliente` o `negocio` (devuelve tokens) |
| `POST` | `/api/auth/login` | Login por password grant (devuelve tokens) |
| `POST` | `/api/auth/refresh` | Renueva el access token con el refresh token |
| `POST` | `/api/auth/logout` | Revoca el token actual (auth) |
| `POST` | `/api/auth/logout-all` | Revoca todos los tokens del usuario (auth) |
| `POST` | `/oauth/token` | Endpoint OAuth2 estándar (todos los grants) |

### Perfil (auth:api)

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/user` | Perfil del usuario autenticado |
| `PUT` | `/api/user` | Actualiza nombre, email, teléfono |
| `PUT` | `/api/user/password` | Cambia la contraseña y cierra las demás sesiones |

### Cliente (cualquier token autenticado)

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/customer/cards` | Mis tarjetas con su progreso |
| `POST` | `/api/customer/cards/join` | Unirse a un programa escaneando el QR del negocio |
| `GET` | `/api/customer/cards/{card}` | Detalle de una tarjeta (con últimos sellos) |
| `DELETE` | `/api/customer/cards/{card}` | Salir del programa (conserva el progreso) |
| `GET` | `/api/customer/cards/{card}/stamps` | Historial de sellos |
| `GET` | `/api/customer/cards/{card}/rewards` | Recompensas y cuáles puedo canjear |
| `GET` | `/api/customer/cards/{card}/qr` | QR que el negocio escanea para sellar |
| `POST` | `/api/customer/validate-token` | **Validar el token de venta del negocio** (`code` escaneado o escrito): suma la compra a mi tarjeta y el token se elimina |
| `POST` | `/api/customer/cards/{card}/redeem` | Solicitar un canje (`reward_id`) |
| `GET` | `/api/customer/redemptions` | Mis canjes (`?status=pending`) |
| `GET` | `/api/customer/redemptions/{redemption}` | Detalle del canje (incluye el código) |
| `POST` | `/api/customer/redemptions/{redemption}/cancel` | Cancelar un canje pendiente |
| `GET` | `/api/client/promotions` | Promociones vigentes de los negocios donde tengo **o he tenido** tarjeta (`?business_id=`, `?search=`, `?discount_type=`) |
| `GET` | `/api/client/near-me` | Negocios a menos de 10 km de `?latitude=..&longitude=..` (radio ajustable con `?radius=`), ordenados por distancia |
| `GET` | `/api/customer/promotions` | Alias histórico de `/api/client/promotions` (misma acción) |

### Negocio (middleware de rol `role:business,admin`)

En `PUT /api/business/card`, el paso `information` acepta `logo_url` y `background_url` como imágenes base64/data URI o URLs existentes; una URL se conserva sin procesarla otra vez. `phone_numbers` acepta una lista o teléfonos separados por comas y se normaliza a una lista en la respuesta.

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/business/profile` | Mi negocio |
| `PUT` | `/api/business/profile` | Datos del negocio |
| `PUT` | `/api/business/card` | Guarda en el negocio del usuario autenticado el paso `information`, `categories`, `products` o `design` indicado por `step` |
| `PUT` | `/api/business/card-settings` | Configuración visual por defecto de las tarjetas |
| `GET` / `PUT` / `PATCH` | `/api/business/qr/settings` | Consultar/actualizar diseño y destino del QR público del menú |
| `GET` | `/api/business/qr/preview.svg` | Vista previa SVG del QR con el estilo actual |
| `GET` | `/api/business/qr/download/{format}` | Descargar el QR en `png` o `webp` |
| `POST` | `/api/business/assets` | Subir asset (`type`: `logo`, `background`, `stamp_icon`) |
| `DELETE` | `/api/business/assets/{type}` | Eliminar asset |
| `GET` / `POST` | `/api/business/menu/categories` | Listar/asociar categorías globales existentes al negocio (`category_id`) |
| `GET` / `PUT` / `PATCH` / `DELETE` | `/api/business/menu/categories/{businessCategory}` | Consultar/editar/eliminar la asociación categoría-negocio |
| `GET` / `POST` | `/api/business/menu/categories/{businessCategory}/products` | Listar/crear productos de esa categoría con entre 1 y 5 fotos base64 |
| `GET` / `PUT` / `PATCH` / `DELETE` | `/api/business/menu/products/{product}` | Consultar/editar/eliminar producto; `images` reemplaza todas las fotos si se envía |
| `GET` / `POST` | `/api/business/menu/products/{product}/presentations` | Listar/crear presentaciones y sus precios opcionales |
| `GET` / `PUT` / `PATCH` / `DELETE` | `/api/business/menu/products/{product}/presentations/{presentation}` | Consultar/editar/eliminar presentación |
| `GET` | `/api/business/cards` | Listar tarjetas del negocio (`?type=`, `?period_type=`) |
| `POST` | `/api/business/cards` | Crear tarjeta: `type=visits` (visitas) o `type=spend` (puntos), con `rewards[]` opcional/los niveles de puntos |
| `GET` | `/api/business/cards/{card}` | Detalle de la tarjeta |
| `PUT` | `/api/business/cards/{card}` | Editar tarjeta |
| `DELETE` | `/api/business/cards/{card}` | Archivar tarjeta |
| `POST` | `/api/business/cards/{card}/upload-assets` | Subir logo/fondo/sello de la tarjeta |
| `POST` | `/api/business/cards/{card}/regenerate-join-code` | Nuevo código de alta (+QR) |
| `POST` | `/api/business/cards/{card}/duplicate` | Duplicar tarjeta con sus recompensas |
| `GET` | `/api/business/cards/{card}/qr` | QR de alta (payload JSON + SVG) |
| `GET` | `/api/business/cards/{card}/stats` | Métricas del programa |
| `GET` | `/api/business/cards/{card}/rewards` | Recompensas de la tarjeta |
| `POST` | `/api/business/cards/{card}/rewards` | Crear recompensa |
| `PUT` | `/api/business/rewards/{reward}` | Editar recompensa |
| `DELETE` | `/api/business/rewards/{reward}` | Eliminar recompensa |
| `GET` | `/api/business/promotions` | Listar promociones (`?schedule_type=`, `?available_now=true`) |
| `POST` | `/api/business/promotions` | Crear promoción (`schedule_type`: `indefinite`, `date_range` o `time_range`) |
| `GET` | `/api/business/promotions/{promotion}` | Detalle |
| `PUT` | `/api/business/promotions/{promotion}` | Editar |
| `DELETE` | `/api/business/promotions/{promotion}` | Eliminar |
| `GET` | `/api/business/new-token` | **Generar token de venta** para mostrar como QR (`?loyalty_card_id=`, `?purchase_amount=`, `?notes=`); dura `SALE_TOKEN_TTL_MINUTES` (5 min) |
| `GET` | `/api/business/tokens` | Tokens de venta vivos del negocio |
| `DELETE` | `/api/business/tokens/{token}` | Cancelar un token antes de que caduque |
| `POST` | `/api/business/stamps/scan` | **Registrar compra** escaneando el QR del cliente (sello o puntos; `purchase_amount` obligatorio en tarjetas por consumo) |
| `GET` | `/api/business/stamps/recent` | Últimos escaneos |
| `GET` | `/api/business/stamps` | Historial de sellos con filtros |
| `DELETE` | `/api/business/stamps/{stamp}` | Anular sello (revierte el progreso) |
| `GET` | `/api/business/customers` | Clientes del negocio |
| `GET` | `/api/business/customers/{card}` | Ficha del cliente |
| `GET` | `/api/business/redemptions` | Canjes del negocio (`?status=pending`) |
| `POST` | `/api/business/redemptions/complete` | **Validar canje por código** |
| `POST` | `/api/business/redemptions/{redemption}/approve` | Validar por id |
| `POST` | `/api/business/redemptions/{redemption}/reject` | Rechazar (devuelve sellos y stock) |
| `POST` | `/api/business/whatsapp/messages` | **Enviar WhatsApp** al cliente (texto libre `text` o plantilla `template`) |
| `GET` | `/api/business/whatsapp/messages` | Historial de WhatsApp (`?direction=`, `?status=`, `?search=`) |
| `GET` | `/api/business/whatsapp/templates` | Plantillas de la cuenta de WhatsApp y su estado de aprobación |

`PUT /api/business/card` requiere el selector `step` y guarda únicamente esa sección para persistir el asistente paso a paso:

El cuerpo se envía como `{ "step": "...", "data": { ... } }`:

- `information`: `data` incluye `name`, `description`, `welcome_message`, `logo_url`, `background_url`, `phone`, `phone_numbers[]` y `address`. Las imágenes nuevas JPEG/PNG/WebP en base64 o data URI se guardan en el disco público.
- `categories`: `data.category_ids[]` reemplaza la selección de categorías del negocio.
- `products`: `data.products[]` crea o actualiza productos por UUID (`id`) con `name`, `description`, `price`, `category_id`, `image_url`, `position` y `available`. `image_url` acepta base64/data URI para imágenes nuevas o la URL pública de una imagen ya guardada para ese mismo producto; los productos omitidos no se eliminan.
- `design`: `data` contiene `primary_color`, `secondary_color`, `font_family` y `menu_style`; los campos se combinan con el diseño existente.

Las fotos se envían en `images` como data URIs o cadenas base64 de JPEG, PNG o WEBP; se requieren de 1 a 5. Cada presentación tiene un `price` opcional: si es `null`, hereda el precio base del producto. Los iconos de fotos en respuestas son URLs públicas.

### Administrador (auth:api + middleware `role:admin`)

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/admin/business` | Listado paginado de negocios + estadísticas globales (`?search=`, `?is_active=`, `?category=`, `?category_id=`, `?city=`, `?trashed=` con `with` u `only`, `?sort=`, `?direction=`, `?per_page=`) |
| `POST` | `/api/admin/business` | Crear negocio. El propietario se indica con `user_id` (usuario con rol `business` sin negocio) o con `owner{name,email,password,phone?,is_active?}`; admite `category_ids[]`, `card_settings{}` e imágenes base64 en `logo_url`/`background_url`/`stamp_icon_url` |
| `GET` | `/api/admin/business/{business}` | Detalle del negocio + métricas del negocio en `stats` |
| `PUT` / `PATCH` | `/api/admin/business/{business}` | Actualizar datos, propietario (`user_id`), categorías, `card_settings` e imágenes (`null` las elimina). Si cambia `name` sin enviar `slug`, se regenera el slug |
| `DELETE` | `/api/admin/business/{business}` | Baja lógica del negocio (se conserva el histórico; el alta por QR deja de funcionar) |
| `POST` | `/api/admin/business/{business}/restore` | Restaurar un negocio eliminado |
| `GET` | `/api/admin/categories` | Listar categorías (`?search=`, `?per_page=`) |
| `POST` | `/api/admin/categories` | Crear categoría (`name`, `icon` opcional como cadena base64 o data URI de imagen) |
| `GET` | `/api/admin/categories/{category}` | Consultar una categoría |
| `PUT` / `PATCH` | `/api/admin/categories/{category}` | Actualizar nombre o icono (`icon: null` lo elimina) |
| `DELETE` | `/api/admin/categories/{category}` | Eliminar categoría y su imagen subida |

`GET /api/admin/business` responde `{ "stats": { ... }, "businesses": { "data": [...], "links": ..., "meta": ... } }`.
`stats` agrupa los totales de la plataforma: `businesses` (total, activos, inactivos, sin tarjetas, altas de los últimos 30 días/mes y eliminados), `owners` (usuarios de negocio con y sin negocio), `loyalty_cards`, `customers` (activos, completados, altas recientes), `stamps` (hoy y últimos 30 días), `redemptions` (pendientes/completados/rechazados/cancelados), `promotions`, `rewards`, productos, `averages` (promedios por negocio), `growth` (altas por mes de los últimos 6 meses) y `top_businesses` (ranking por clientes). El detalle (`GET /api/admin/business/{business}`) devuelve además `stats` con las métricas propias del negocio (clientes, sellos, recompensas y canjes).

### Público

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/categories` | Listar categorías (`?search=`, `?per_page=`; sin token) |
| `GET` | `/api/public/categories` | Alias público para consultar categorías |
| `GET` | `/api/public/business/{slug}/menu` | Menú público de un negocio (categorías, productos disponibles, fotos y presentaciones activas) |
| `GET` | `/api/businesses` | Directorio de negocios (`?search=`, `?city=`, `?category=`) |
| `GET` | `/api/businesses/{business}` | Ficha pública con sus tarjetas |
| `GET` / `POST` | `/api/webhooks/whatsapp` | Webhook de WhatsApp Cloud API (handshake de Meta y eventos firmados) |
| `GET` | `/` | Índice de la API |
| `GET` | `/up` | Health check |

## 6. Ejemplos de uso

```bash
API=http://localhost:8000

# 1) Registro de un cliente (devuelve tokens)
curl -s -X POST $API/api/auth/register -H 'Accept: application/json' \
  -d 'name=Marta' -d 'email=marta@example.com' \
  -d 'password=Password123!' -d 'password_confirmation=Password123!' -d 'role=cliente'

# 2) El cliente se une al programa escaneando el QR del negocio
TOKEN=<access_token_cliente>
curl -s -X POST $API/api/customer/cards/join -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' -d 'code=CAFE2026'

# 3) El negocio registra un sello escaneando el QR del cliente
NEGOCIO_TOKEN=<access_token_negocio>
curl -s -X POST $API/api/business/stamps/scan -H "Authorization: Bearer $NEGOCIO_TOKEN" \
  -H 'Accept: application/json' \
  -d 'code=AB12CD34EF' -d 'purchase_amount=4.50' -d 'notes="Café con leche"'

# 4) El cliente solicita el canje al completar la tarjeta
curl -s -X POST $API/api/customer/cards/<card_id>/redeem -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' -d 'reward_id=<reward_id>'

# 5) El negocio valida el código que muestra la app del cliente
curl -s -X POST $API/api/business/redemptions/complete -H "Authorization: Bearer $NEGOCIO_TOKEN" \
  -H 'Accept: application/json' -d 'code=PP-8F3K2L9Q'
```

## 7. Códigos QR

Dos payloads distintos, ambos autoexplicativos (la app puede regenerar el QR sin conexión):

```json
// QR del negocio (lo escanea el cliente para unirse) — lo genera el negocio
{ "v": 1, "type": "loyalty_card.join", "join_code": "CAFE2026",
  "loyalty_card_id": "9f1...", "business_id": "3ab...", "url": "https://.../join/CAFE2026" }

// QR del cliente (lo escanea el negocio para sellar) — lo genera el cliente
{ "v": 1, "type": "customer_card.identify", "code": "AB12CD34EF",
  "loyalty_card_id": "9f1...", "stamps": 3, "required_stamps": 6 }
```

El backend normaliza lo que reciba el lector: JSON, URL con `?code=`, deep link o texto plano.

Además, cada negocio configura su QR del menú desde `/api/business/qr/settings`. El destino inicial es
`/api/public/business/{slug}/menu`; también se puede usar el sitio web del negocio o una URL propia.
La configuración admite tamaño (256–2048 px), zona de silencio (4–12 módulos), colores hexadecimales
con contraste legible, módulos cuadrados/punteados/redondeados, localizadores cuadrados/redondeados/
circulares y el logo de marca centrado (escala 10–20%). La corrección de errores se fija en nivel H
para mejorar la lectura y compensar el logo. La vista previa SVG es inmediata; los endpoints de descarga
entregan PNG y WebP (GD con `imagewebp` es requisito para WebP).

Ejemplo de actualización parcial:

```json
{
  "destination_type": "menu",
  "size": 1024,
  "quiet_zone": 4,
  "foreground_color": "#172033",
  "background_color": "#FFFFFF",
  "eye_color": "#2563EB",
  "module_style": "rounded",
  "eye_style": "square",
  "include_logo": true,
  "logo_scale": 16
}
```

## 8. Reglas de negocio

- **Un sello por compra**: `POST /api/business/stamps/scan` valida que la tarjeta pertenece al
  negocio, que está activa y que no se supera el *throttle* (`CARD_STAMP_THROTTLE_SECONDS`, 30 s)
  ni el máximo diario (`CARD_MAX_STAMPS_PER_DAY`, 0 = sin límite). Todo dentro de una transacción
  con `lockForUpdate`, así que dos escaneos simultáneos no pueden descontar el mismo sello.
- **Completar tarjeta**: al alcanzar `required_stamps` la tarjeta pasa a `completed` e incrementa
  `rewards_earned` (se emite el evento `CardCompleted`).
- **Canje**: el cliente solicita la recompensa (se descuentan los sellos y se reserva una unidad
  de stock); el negocio lo valida con el código `PP-XXXXXXXX`. Al **rechazar** o **cancelar**, los
  sellos y el stock vuelven a su sitio. No se admiten dos canjes pendientes en la misma tarjeta.
- **Sellos**: `DELETE /api/business/stamps/{stamp}` anula un sello erróneo y revierte el progreso.
- **Vigencia**: el comando `punto-plus:expire-cards` (programado a diario, 04:00) pasa a `expired`
  las tarjetas cuyo `expires_at` venció o cuyo programa se cerró/desactivó, y pone a cero los sellos
  de las tarjetas inactivas durante más días que `settings.stamps_lifetime_days`
  (`CARD_STAMPS_LIFETIME_DAYS`, sin valor = no caducan). Nunca toca una tarjeta completada pendiente
  de canje. Al volver a escanear el QR del negocio, la tarjeta caducada se reactiva con su progreso.
- **Estados**: `active` y `completed` los gestiona la API; `expired` lo aplica el comando anterior y
  `blocked` está reservado a soporte (bloqueo manual) y ya se respeta en todas las validaciones.
- **Idempotencia**: `join` es idempotente (devuelve `already_joined: true` y la tarjeta con su
  progreso intacto, incluso si el cliente había salido del programa).

### 8.1 Dos tipos de tarjeta (`loyalty_cards.type`)

| | `visits` — por cantidad de visitas | `spend` — por consumo (puntos) |
|---|---|---|
| Progreso | `customer_cards.stamps_count` (+1 por compra) | `customer_cards.points_count` (`floor(monto / spend_amount_per_block) * points_per_block`) |
| Meta | `required_stamps` de la tarjeta | `rewards.points_required` de cada nivel |
| Recompensas | una por ciclo (`rewards.required_stamps`) | varias, una por umbral de puntos (200 pts → refresco, 500 pts → hamburguesa) |
| Vigencia | `period_type` + `period_days` (o `unlimited`) | igual |
| Al canjear | descuenta sellos (y reinicia el ciclo si `CARD_RESET_PROGRESS_ON_REDEEM`) | descuenta exactamente los puntos del nivel y conserva el saldo |

- `GET /api/business/cards?type=spend` filtra por tipo; el detalle expone `type`, `progress_unit`
  y `reward_threshold_field` para que el front sepa qué campo mostrar.
- `rewards.required_stamps` y `redemptions.points_used` quedan en `null`/`0` cuando no aplican.
- `PUT /api/business/cards/{card}` sólo permite cambiar `type` mientras la tarjeta no tenga clientes
  ni recompensas (el progreso depende del tipo); devuelve `422` explicando qué eliminar primero.
- Al caducar el ciclo (`period_days`), la siguiente compra reinicia el contador y abre un ciclo nuevo
  (`customer_cards.cycle_started_at` / `cycle_expires_at`).

### 8.2 Tres formas de programar una promoción (`promotions.schedule_type`)

| `schedule_type` | Campos obligatorios | Ejemplo |
|---|---|---|
| `indefinite` | *(ninguno)* | Aviso permanente los martes (o todos los días) |
| `date_range` | `starts_at`, `ends_at` | Promoción navideña del 1 al 24 de diciembre |
| `time_range` | `start_time`, `end_time` | Hora feliz viernes y sábado de 18:00 a 21:00 |

- `schedule_type` es opcional: se deduce de `starts_at`/`ends_at` (→ `date_range`), de
  `start_time`/`end_time` (→ `time_range`) o, si no hay ninguno, queda en `indefinite`.
  Por eso una promoción **sin fechas** se guarda sin pedirlas (`PromotionSchedule::resolveType()`).
- `days_of_week` es una lista ISO-8601: **1 = lunes … 7 = domingo**; es opcional (vacío = todos los días).
- `start_time`/`end_time` usan `HH:MM` (24 h) y la franja puede cruzar la medianoche (`20:00`–`02:00`).
- `Promotion::isAvailableNow()` evalúa tipo + día + fecha + hora; se expone como `is_available_now`
  y `schedule.is_available_now`. `?available_now=true` filtra el listado.
- Los campos que no aplican al tipo se guardan en `null` (una promoción indefinida no tiene fechas).
- **Banner**: se envía como imagen en base64 (`banner`), el API la guarda en el disco `public`
  (`promotions/{id}/banner/…`) y persiste la ruta en `promotions.image_path`; la respuesta la
  expone como `image_path` + `image_url`. `remove_banner: true` la elimina.

### 8.3 Tokens de venta (`sale_tokens`)

Segunda forma de registrar una compra, pensada para el mostrador **sin escanear la tarjeta del
cliente** (guía completa en [`docs/tokens-venta.md`](docs/tokens-venta.md)):

1. `GET /api/business/new-token` genera un código corto (8 caracteres) y lo devuelve con su QR
   (`qr.svg`, `qr_content`).
2. El panel lo muestra; el cliente lo escanea o escribe `display_code` (`ABCD-EFGH`).
3. `POST /api/customer/validate-token` registra la venta en la tarjeta del cliente
   (`stamps.source = token`) y **elimina** el token.

Reglas:

- Vigencia `SALE_TOKEN_TTL_MINUTES` (**5 minutos** por defecto); al caducar responde `422`.
- Un solo uso: el token se elimina al validarse (y la validación es atómica con bloqueo de fila).
- Si el cliente no tenía tarjeta en ese programa, se une automáticamente (sólo programas públicos).
- En tarjetas por consumo el monto (`purchase_amount`) es obligatorio: se puede fijar al generar el
  token o enviarlo al validar.
- El dueño del negocio no puede validar sus propios tokens (`SALE_TOKEN_ALLOW_SELF_REDEMPTION=false`).
- `SALE_TOKEN_MAX_ACTIVE` limita los tokens vivos por negocio (10 por defecto; los más antiguos se
  descartan al generar uno nuevo).

## 9. Errores

| HTTP | `error` | Cuándo |
|---|---|---|
| 401 | `invalid_credentials` | Email/contraseña incorrectos |
| 401 | `invalid_refresh_token` | Refresh token caducado o revocado |
| 403 | `account_disabled` | Cuenta desactivada |
| 403 | `forbidden` | La Policy deniega el acceso (otro cliente/negocio) |
| 404 | `not_found` | Recurso inexistente (mensaje en español) |
| 422 | `validation_error` (+ `errors`) | Validación de FormRequest |
| 429 | `too_many_requests` | Límite de peticiones |
| 503 | `oauth_not_configured` | Faltan las claves/cliente OAuth2 (`hint` con el detalle) |
| 403 | `invalid_signature` | La firma `X-Hub-Signature-256` del webhook de WhatsApp no es válida |
| 502 | `whatsapp_error` | Meta rechazó el mensaje (`code`, `subcode` y `type` en la respuesta) |
| 503 | `whatsapp_disabled` | Integración de WhatsApp apagada (`WHATSAPP_ENABLED=false`) |
| 503 | `whatsapp_not_configured` | Faltan credenciales de WhatsApp (`missing` con las variables) |

## 10. Pruebas

```bash
composer run test          # php artisan test
php artisan test --filter=StampTest
```

- SQLite `:memory:` + `RefreshDatabase` (ver `phpunit.xml`).
- Claves RSA exclusivas de la suite en `tests/Fixtures/keys` (`PASSPORT_KEYS_PATH`).
- Los clientes OAuth2 se crean al vuelo (`tests/Concerns/CreatesOAuthClients`), de modo que los
  tests de `login`/`refresh` recorren el password grant real de Passport.
- Cobertura: registro/login/refresh/logout, tarjetas (CRUD, assets, QR, stats), alta por QR,
  sellado (throttle, pertenencia, completar), canjes (solicitar/aprobar/rechazar/cancelar),
  flujo completo sello → canje → validación, promociones y negocios cercanos del cliente, y
  WhatsApp (envío con `Http::fake`, historial, plantillas, webhook firmado y acuses).

### Verificación antes de subir cambios

```bash
composer validate --strict            # composer.json (incluidas las restricciones de versión)
php artisan route:list --path=api     # rutas de /api (+ /oauth/* de Passport)
php artisan punto-plus:whatsapp-test +5215512345678 --help   # integración de WhatsApp
php artisan migrate:fresh --seed      # migraciones + datos de demo
composer run test                     # suite completa
php artisan punto-plus:expire-cards --dry-run
```

La especificación está en `public/docs/openapi.yaml` (OpenAPI 3.0.3): impórtala en Swagger UI,
Postman o Insomnia para probar los flujos con los scopes OAuth2 ya declarados.

## 11. Despliegue

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate           # sólo la primera vez
php artisan passport:install       # una vez (o copia las claves RSA al servidor)
php artisan migrate --force
php artisan punto-plus:oauth-clients --write-env
php artisan storage:link
php artisan config:cache && php artisan route:cache
php artisan schedule:work          # o cron: * * * * * php /ruta/artisan schedule:run
```

- `queue:work` si activas notificaciones (`NotifyCardCompleted`, `LogLoyaltyActivity`).
- `CORS_ALLOWED_ORIGINS` para el panel del negocio (las apps móviles no envían `Origin`).
- Configura `PUNTO_PLUS_ASSET_DISK=s3` para servir los assets desde S3.
- El programador purga tokens (`passport:purge`), canjes antiguos (`model:prune`) y tarjetas/sellos
  vencidos (`punto-plus:expire-cards --dry-run` para auditar sin escribir) cada noche.

## 12. WhatsApp Cloud API

La API puede enviar mensajes de WhatsApp (avisos de sellos, promociones, recordatorios de
canje) y recibir las respuestas del cliente por webhook.

```bash
php artisan migrate                                            # crea whatsapp_messages
php artisan punto-plus:whatsapp-test +5215512345678            # prueba real de envío
php artisan punto-plus:whatsapp-test +5215512345678 --template=hello_world --language=en_US
```

- `POST /api/business/whatsapp/messages` envía texto libre (`type: text`, sólo dentro de la
  ventana de 24 h) o una plantilla aprobada (`type: template`). El teléfono se normaliza a E.164
  (`WHATSAPP_DEFAULT_COUNTRY_CODE` completa los números de 10 dígitos).
- `GET /api/business/whatsapp/messages` devuelve el historial: envíos, mensajes entrantes y
  acuses (`queued → sent → delivered → read`, o `failed` con el `code` de Meta).
- `GET /api/business/whatsapp/templates` lista las plantillas de la WABA y su estado.
- `GET|POST /api/webhooks/whatsapp` es el webhook: el `GET` responde el `hub.challenge` y el
  `POST` valida `X-Hub-Signature-256` con el App Secret antes de guardar nada.

Variables en `.env` (`WHATSAPP_*`) y **guía completa de lo que hay que configurar en Meta** en
[`docs/whatsapp.md`](docs/whatsapp.md): crear la app, WABA, número, token permanente, webhook,
plantillas, modo Live y errores frecuentes.

## 13. Estructura

```text
app/
├── Console/Commands/                          # punto-plus:oauth-clients, expire-cards, whatsapp-test
├── Enums/                                     # UserRole, CardStatus, LoyaltyCardType, CardPeriodType,
│                                              # PromotionScheduleType, WhatsAppStatus, ...
├── Events/ + Listeners/                       # CardCompleted, StampRegistered, ...
├── Exceptions/                                # OAuthConfigurationException, WhatsAppException
├── Http/
│   ├── Controllers/Api/                       # Auth, User, Business, LoyaltyCard, CustomerCard,
│   │   ├── Admin/                             # Stamp, Promotion, Reward, Redemption (+ admin CRUD)
│   │   ├── Business/                          # Configuration, WhatsApp
│   │   ├── Client/                            # Promotion, NearMe
│   │   ├── Public/                            # catálogo y menú públicos
│   │   └── Webhooks/                          # WhatsApp
│   ├── Middleware/                            # ForceJsonResponse, EnsureUserHasRole
│   ├── Requests/                              # FormRequests (validación en español)
│   └── Resources/                             # JsonResources (Admin/, Client/, raíz)
├── Models/                                    # Eloquent (UUID; WhatsAppMessage)
├── Policies/                                  # Policies del dominio
├── Services/
│   ├── AssetService.php  CustomerCardService.php  QrCodeService.php
│   ├── RedemptionService.php  StampService.php
│   ├── OAuth/TokenBroker.php                  # password/refresh grant desde la API
│   └── WhatsApp/{WhatsAppClient,WhatsAppMessenger}.php
└── Support/                                   # Media, PhoneNumber, PromotionSchedule, OAuth/TokenResponse
docs/whatsapp.md                               # guía de configuración en Meta
docs/tarjetas-y-promociones.md                 # qué enviar para cada tipo de tarjeta y promoción
docs/tokens-venta.md                           # tokens de venta: QR del negocio que valida el cliente
public/docs/openapi.yaml                       # contrato OpenAPI 3.0 (Swagger)
```

## 14. Licencia

MIT.
