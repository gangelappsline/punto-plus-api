# Tokens de venta (QR del negocio → validación del cliente)

Segunda forma de registrar una compra, además de `POST /api/business/stamps/scan`
(el negocio escanea la tarjeta del cliente). Aquí el flujo se invierte: **el negocio genera un
código temporal** y **el cliente lo valida** desde su app (escaneando el QR o escribiendo el
código).

```
Panel del negocio                        App del cliente
─────────────────                        ───────────────
GET /api/business/new-token
        │  { code, qr.svg, expires_at }
        ▼
   muestra el QR  ──── lo escanea ────▶  POST /api/customer/validate-token { code }
                                              │  suma la venta a su tarjeta
                                              ▼
                                        201 + customer_card.progress
   el token ya no existe (un solo uso)
```

---

## 1. Generar el token — `GET /api/business/new-token`

Requiere rol `negocio` (o `admin`). Todos los parámetros son opcionales y van en la query string:

| Parámetro | Tipo | Notas |
|---|---|---|
| `loyalty_card_id` | uuid | Tarjeta a la que aplica. **Obligatorio si el negocio tiene más de una tarjeta activa** (si tiene una sola se usa esa). |
| `purchase_amount` | decimal | Monto de la venta. Obligatorio en tarjetas por consumo (los puntos dependen del importe); en tarjetas por visitas se ignora. |
| `notes` | string ≤ 255 | Nota interna que se copia al sello. |

```bash
curl -s "$API/api/business/new-token?purchase_amount=250" \
  -H "Authorization: Bearer $TOKEN_NEGOCIO"
```

```json
{
  "message": "Token generado: compártelo con tu cliente antes de 5 minutos.",
  "data": {
    "id": "0193...",
    "business_id": "0193...",
    "loyalty_card_id": "0193...",
    "code": "K7QM2XPD",
    "display_code": "K7QM-2XPD",
    "purchase_amount": "250.00",
    "notes": null,
    "expires_at": "2026-11-01T18:05:00+00:00",
    "expires_in_seconds": 300,
    "ttl_minutes": 5,
    "is_expired": false,
    "qr": {
      "payload": {
        "v": 1,
        "type": "sale_token.redeem",
        "code": "K7QM2XPD",
        "loyalty_card_id": "0193...",
        "business_id": "0193...",
        "business": "Café Aurora",
        "expires_at": "2026-11-01T18:05:00+00:00"
      },
      "json": "{\"v\":1,\"type\":\"sale_token.redeem\",\"code\":\"K7QM2XPD\",...}",
      "svg": "<svg ...>"
    },
    "qr_content": "{\"v\":1,\"type\":\"sale_token.redeem\",\"code\":\"K7QM2XPD\",...}",
    "loyalty_card": { "id": "0193...", "name": "Club del café", "...": "..." },
    "created_at": "2026-11-01T18:00:00+00:00"
  }
}
```

Notas de implementación:

- Responde **`201 Created`** aunque sea un `GET`, porque crea el token.
- `qr.svg` es el SVG listo para pintar; `qr_content`/`qr.json` es el texto que debe llevar el QR si el
  panel lo dibuja por su cuenta (por ejemplo con una librería del front). `display_code` es el código
  agrupado para dictarlo o teclearlo (`K7QM-2XPD`).
- El alfabeto del código excluye caracteres ambiguos (`0/O`, `1/I/L`).
- Errores: `422` si el negocio no está activo, no tiene tarjetas activas, la tarjeta indicada no es
  suya o está inactiva/caducada.

### Tokens vivos y cancelación

| Método | Endpoint | Qué hace |
|---|---|---|
| `GET` | `/api/business/tokens` | Tokens vivos del negocio (para repintar el QR al recargar el panel) |
| `DELETE` | `/api/business/tokens/{token}` | Cancela un token antes de que caduque |

`SALE_TOKEN_MAX_ACTIVE` (10 por defecto) limita cuántos tokens vivos puede acumular un negocio: al
generar uno nuevo se descartan los más antiguos.

---

## 2. Validar el token — `POST /api/customer/validate-token`

También disponible en `/api/client/validate-token` (mismo controlador, alias para la app móvil).
Cualquier usuario autenticado puede validar un token (un dueño de negocio puede acumular compras en
otros comercios, pero **no en el suyo**).

```bash
curl -s -X POST "$API/api/customer/validate-token" \
  -H "Authorization: Bearer $TOKEN_CLIENTE" \
  -H "Content-Type: application/json" \
  -d '{"code": "k7qm-2xpd"}'
```

| Campo | Obligatorio | Notas |
|---|---|---|
| `code` | sí | Código tecleado (`K7QM-2XPD`, `k7qm2xpd`) o contenido del QR (JSON con `code`, deep link o URL con `?code=`). |
| `purchase_amount` | no | Monto de la compra; si el token ya lo trae, este manda. Obligatorio para tarjetas por consumo sin monto en el token. |

Respuesta (`201`), con la misma forma que `POST /api/business/stamps/scan`:

```json
{
  "message": "Venta registrada: sello añadido a tu tarjeta.",
  "data": {
    "id": "0193...",
    "source": "token",
    "source_label": "Token de venta",
    "points_awarded": 0,
    "stamped_at": "2026-11-01T18:01:12+00:00",
    "customer_card": { "...": "..." },
    "business": { "name": "Café Aurora", "...": "..." }
  },
  "customer_card": {
    "id": "0193...",
    "code": "CLIENTE123",
    "progress_unit": "visits",
    "stamps_count": 1,
    "required_stamps": 10,
    "progress_percentage": 10,
    "status": "active",
    "reward_available": false
  },
  "sale_token": { "code": "K7QM2XPD", "used": true }
}
```

---

## 3. Reglas y errores

| Situación | Respuesta |
|---|---|
| Token inexistente o ya usado | `422` · `code` → «El código no es válido o ya se utilizó…» |
| Token caducado | `422` · `code` → «El código caducó: los tokens de venta duran 5 minutos…» (y se elimina) |
| Negocio o programa inactivo | `422` · `code` |
| Dueño validando su propio token | `422` · `code` → «No puedes validar un token de tu propio negocio.» |
| Programa privado y el cliente no tenía tarjeta | `422` · `code` → «Este programa requiere una invitación del negocio.» |
| Tarjeta por consumo sin monto | `422` · `purchase_amount` |
| Demasiados sellos seguidos / tope diario | `422` · `code` (reglas `CARD_STAMP_THROTTLE_SECONDS` / `CARD_MAX_STAMPS_PER_DAY`) |
| Más de 20 intentos por minuto | `429` (`throttle:sale-token-validate`) |

Detalles:

- **Un solo uso y atómico**: la validación bloquea la fila (`lockForUpdate`), registra la venta y
  elimina el token dentro de la misma transacción. Si el sello no se puede registrar (throttle, tope
  diario, tarjeta llena), la transacción se revierte y **el token sigue vigente** para reintentar.
- **Alta automática**: si el cliente no tenía tarjeta en ese programa se une en ese momento (sólo
  programas públicos, `loyalty_cards.is_public = true`).
- **Trazabilidad**: el sello queda con `source = token`, `registered_by` = usuario del negocio que
  generó el token y `meta.sale_token_code` con el código usado. Los sellos se pueden anular con
  `DELETE /api/business/stamps/{stamp}` como cualquier otro.
- **Limpieza**: los tokens caducados se borran al generar uno nuevo y `model:prune` borra los que
  llevan más de un día caducados (`routes/console.php`).

## 4. Configuración

| Variable | Default | Para qué |
|---|---|---|
| `SALE_TOKEN_TTL_MINUTES` | `5` | Vigencia del token en minutos |
| `SALE_TOKEN_CODE_LENGTH` | `8` | Longitud del código |
| `SALE_TOKEN_MAX_ACTIVE` | `10` | Tokens vivos por negocio (0 = sin límite) |
| `SALE_TOKEN_ALLOW_SELF_REDEMPTION` | `false` | Permitir que el dueño valide sus propios tokens (sólo pruebas) |

```php
// config/punto_plus.php
'sale_tokens' => [
    'ttl_minutes' => (int) env('SALE_TOKEN_TTL_MINUTES', 5),
    'code_length' => (int) env('SALE_TOKEN_CODE_LENGTH', 8),
    'max_active' => (int) env('SALE_TOKEN_MAX_ACTIVE', 10),
    'allow_self_redemption' => (bool) env('SALE_TOKEN_ALLOW_SELF_REDEMPTION', false),
],
```
