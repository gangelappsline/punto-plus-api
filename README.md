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
- Extensiones opcionales: `gd` o `imagick` (no son necesarias; los QR se generan en SVG).

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

Todos los tokens los emite Passport 13. Los scopes son **`cliente`**, **`negocio`** y **`admin`**.

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

El scope acompaña al rol, pero **la autorización fina la hacen las Policies**: un token `cliente`
nunca puede leer tarjetas de otro cliente ni datos de un negocio. Las rutas de negocio exigen
además el middleware `role:negocio,admin`.

## 4. Modelo de datos

| Tabla | PK | Descripción | Cascadas |
|---|---|---|---|
| `users` | id | Clientes, negocios y admins (`role`) | — |
| `businesses` | **uuid** | Negocio, branding (`logo_path`, `background_path`, `stamp_icon_path`) y `card_settings` (JSON) | `user_id` → null on delete |
| `loyalty_cards` | **uuid** | Tarjeta de sellos del negocio (`required_stamps`, `join_code`, colores, assets) | `business_id` → **cascade** |
| `rewards` | **uuid** | Premio al completar un ciclo (`required_stamps`, `stock`) | `loyalty_card_id` → **cascade** |
| `promotions` | **uuid** | Promociones del negocio (opcionalmente ligadas a una tarjeta) | `business_id` → **cascade** |
| `customer_cards` | **uuid** | Tarjeta de un cliente (progreso, `status`, `code`) | `user_id`/`loyalty_card_id`/`business_id` → **cascade** |
| `stamps` | **uuid** | Compra escaneada (`source`, `purchase_amount`, `stamped_at`) | `customer_card_id` → **cascade** |
| `redemptions` | **uuid** | Canjes (`code`, `status`, `stamps_used`) | `customer_card_id` → **cascade** |
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

### Cliente (scope `cliente`)

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/customer/cards` | Mis tarjetas con su progreso |
| `POST` | `/api/customer/cards/join` | Unirse a un programa escaneando el QR del negocio |
| `GET` | `/api/customer/cards/{card}` | Detalle de una tarjeta (con últimos sellos) |
| `DELETE` | `/api/customer/cards/{card}` | Salir del programa (conserva el progreso) |
| `GET` | `/api/customer/cards/{card}/stamps` | Historial de sellos |
| `GET` | `/api/customer/cards/{card}/rewards` | Recompensas y cuáles puedo canjear |
| `GET` | `/api/customer/cards/{card}/qr` | QR que el negocio escanea para sellar |
| `POST` | `/api/customer/cards/{card}/redeem` | Solicitar un canje (`reward_id`) |
| `GET` | `/api/customer/redemptions` | Mis canjes (`?status=pending`) |
| `GET` | `/api/customer/redemptions/{redemption}` | Detalle del canje (incluye el código) |
| `POST` | `/api/customer/redemptions/{redemption}/cancel` | Cancelar un canje pendiente |
| `GET` | `/api/customer/promotions` | Promociones de los negocios donde tengo tarjeta |

### Negocio (scope `negocio`, middleware `role:negocio,admin`)

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/business/profile` | Mi negocio |
| `PUT` | `/api/business/profile` | Datos del negocio |
| `PUT` | `/api/business/card-settings` | Configuración visual por defecto de las tarjetas |
| `POST` | `/api/business/assets` | Subir asset (`type`: `logo`, `background`, `stamp_icon`) |
| `DELETE` | `/api/business/assets/{type}` | Eliminar asset |
| `GET` | `/api/business/cards` | Listar tarjetas del negocio |
| `POST` | `/api/business/cards` | Crear tarjeta (opcionalmente con `rewards[]`) |
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
| `GET` | `/api/business/promotions` | Listar promociones |
| `POST` | `/api/business/promotions` | Crear promoción |
| `GET` | `/api/business/promotions/{promotion}` | Detalle |
| `PUT` | `/api/business/promotions/{promotion}` | Editar |
| `DELETE` | `/api/business/promotions/{promotion}` | Eliminar |
| `POST` | `/api/business/stamps/scan` | **Registrar sello** escaneando el QR del cliente |
| `GET` | `/api/business/stamps/recent` | Últimos escaneos |
| `GET` | `/api/business/stamps` | Historial de sellos con filtros |
| `DELETE` | `/api/business/stamps/{stamp}` | Anular sello (revierte el progreso) |
| `GET` | `/api/business/customers` | Clientes del negocio |
| `GET` | `/api/business/customers/{card}` | Ficha del cliente |
| `GET` | `/api/business/redemptions` | Canjes del negocio (`?status=pending`) |
| `POST` | `/api/business/redemptions/complete` | **Validar canje por código** |
| `POST` | `/api/business/redemptions/{redemption}/approve` | Validar por id |
| `POST` | `/api/business/redemptions/{redemption}/reject` | Rechazar (devuelve sellos y stock) |

### Público

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/businesses` | Directorio de negocios (`?search=`, `?city=`, `?category=`) |
| `GET` | `/api/businesses/{business}` | Ficha pública con sus tarjetas |
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

## 9. Errores

| HTTP | `error` | Cuándo |
|---|---|---|
| 401 | `invalid_credentials` | Email/contraseña incorrectos |
| 401 | `invalid_refresh_token` | Refresh token caducado o revocado |
| 403 | `account_disabled` | Cuenta desactivada |
| 403 | `missing_scope` (+ `required_scopes`) | El token no tiene el scope necesario |
| 403 | `forbidden` | La Policy deniega el acceso (otro cliente/negocio) |
| 404 | `not_found` | Recurso inexistente (mensaje en español) |
| 422 | `validation_error` (+ `errors`) | Validación de FormRequest |
| 429 | `too_many_requests` | Límite de peticiones |
| 503 | `oauth_not_configured` | Faltan las claves/cliente OAuth2 (`hint` con el detalle) |

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
  sellado (throttle, pertenencia, completar), canjes (solicitar/aprobar/rechazar/cancelar) y
  flujo completo sello → canje → validación.

### Verificación antes de subir cambios

```bash
composer validate --strict            # composer.json (incluidas las restricciones de versión)
php artisan route:list --path=api     # 56 rutas de /api (+ /oauth/* de Passport)
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

## 12. Estructura

```text
app/
├── Console/Commands/                          # punto-plus:oauth-clients, punto-plus:expire-cards
├── Enums/                                     # UserRole, CardStatus, StampSource, ...
├── Events/ + Listeners/                       # CardCompleted, StampRegistered, ...
├── Exceptions/                                # OAuthConfigurationException, ...
├── Http/
│   ├── Controllers/Api/                       # Auth, User, Business, LoyaltyCard, CustomerCard,
│   │                                          # Stamp, Promotion, Reward, Redemption
│   ├── Controllers/Web/                       # login por sesión para OAuth2
│   ├── Middleware/                            # ForceJsonResponse, EnsureUserHasRole
│   ├── Requests/                              # 19 FormRequests (validación en español)
│   └── Resources/                             # 8 JsonResources
├── Models/                                    # 8 modelos Eloquent (UUID + SoftDeletes)
├── Policies/                                  # 7 Policies
├── Services/
│   ├── AssetService.php  CustomerCardService.php  QrCodeService.php
│   ├── RedemptionService.php  StampService.php
│   └── OAuth/TokenBroker.php                  # password/refresh grant desde la API
└── Support/                                   # Media, OAuth/TokenResponse
```

## 13. Licencia

MIT.
