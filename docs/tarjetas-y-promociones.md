# Tarjetas de negocio y promociones

Guía para el **front**: qué enviar para guardar cada tipo de tarjeta y cada tipo de
promoción. El contrato completo (todos los campos, códigos de error y esquemas de
respuesta) está en [`public/docs/openapi.yaml`](../public/docs/openapi.yaml).

- Todas las peticiones llevan `Authorization: Bearer <token>` y `Accept: application/json`.
- Las respuestas envuelven el recurso en `data`. Los errores de validación devuelven
  `422` con `message`, `error: validation_error` y `errors: { campo: [mensajes] }`.

---

## 1. Tarjetas de negocio (`POST /api/business/cards`)

Una tarjeta **siempre** lleva `name` y `type`. Existen sólo dos tipos.

### 1.1 `type: "visits"` — por cantidad de visitas

El negocio fija cuántas visitas hacen falta y, opcionalmente, en cuántos días.

| Campo | Obligatorio | Notas |
|---|---|---|
| `name` | sí | Hasta 120 caracteres |
| `type` | — | Por defecto `visits` |
| `required_stamps` | recomendado | 1–100; si se omite se usa `CARD_DEFAULT_REQUIRED_STAMPS` (10) |
| `period_type` | — | `unlimited` (por defecto) o `days` |
| `period_days` | si `period_type = days` | 1–3650 |
| `rewards[]` | — | Opcional; `rewards[].required_stamps` (si se omite, el de la tarjeta) |

```json
{
  "name": "Club del café",
  "type": "visits",
  "required_stamps": 6,
  "period_type": "days",
  "period_days": 30,
  "reward_description": "Café de especialidad gratis",
  "rewards": [
    { "name": "Croissant gratis", "required_stamps": 3 },
    { "name": "Café gratis", "required_stamps": 6 }
  ]
}
```

- Cada compra suma **1 visita** (`POST /api/business/stamps/scan`).
- Al llegar a `required_stamps` la tarjeta pasa a `completed` y hay recompensa.
- Si el ciclo caduca (`period_days`), la siguiente compra reinicia el contador.

### 1.2 `type: "spend"` — por consumo (puntos)

Cada bloque de gasto suma puntos y las recompensas son **niveles de puntos**.

| Campo | Obligatorio | Notas |
|---|---|---|
| `name` | sí | — |
| `type` | sí | `spend` |
| `spend_amount_per_block` | sí | Monto de consumo que suma un bloque (ej. `100`) |
| `points_per_block` | sí | Puntos por bloque (ej. `10`) |
| `currency` | — | ISO-4217, por defecto `MXN` |
| `rewards[]` | sí (≥ 1) | Cada recompensa necesita `points_required` |
| `period_type` / `period_days` | — | Igual que en las tarjetas por visitas |

```json
{
  "name": "Puntos Aurora",
  "type": "spend",
  "spend_amount_per_block": 100,
  "points_per_block": 10,
  "currency": "MXN",
  "period_type": "unlimited",
  "rewards": [
    { "name": "Refresco 600 ml", "points_required": 200 },
    { "name": "Hamburguesa con papas", "points_required": 500 }
  ]
}
```

- Los puntos de una compra son `floor(monto / spend_amount_per_block) * points_per_block`:
  $250 con la regla `100 → 10` = **20 puntos**.
- `POST /api/business/stamps/scan` exige `purchase_amount` en estas tarjetas y lo devuelve en
  `data.points_awarded`.
- Al canjear se descuentan **sólo** los puntos del nivel (`200` o `500`) y el resto queda como
  saldo: `POST /api/customer/cards/{card}/redeem` con `{ "reward_id": "..." }`.
- Dos recompensas no pueden tener los mismos puntos.

### 1.3 Campos comunes

`description`, `terms`, `reward_description`, `primary_color`, `secondary_color`,
`text_color`, `is_active`, `is_public`, `expires_at`, `settings` (`stamp_icon`,
`card_shape`, `welcome_message`, `stamps_lifetime_days`), `inherit_business_branding`.

### 1.4 Editar (`PUT /api/business/cards/{loyaltyCard}`)

Enviar sólo lo que cambia. `type` sólo se puede cambiar mientras la tarjeta **no tenga
clientes ni recompensas** (si no, `422`); al pasar a `spend` se limpia `required_stamps`
y al volver a `visits` se limpia la configuración de puntos. Enviar `period_days` sin
`period_type` deja el ciclo en `days`.

### 1.5 Qué devuelve la API

`LoyaltyCardResource` añade a los datos de siempre:

| Campo | Ejemplo | Uso en el front |
|---|---|---|
| `type` / `type_label` | `spend` / `Por consumo (puntos)` | Saber qué pantalla mostrar |
| `progress_unit` | `points` | Unidad para barras de progreso |
| `reward_threshold_field` | `points_required` | Campo del nivel que se muestra |
| `required_stamps` | `null` en `spend` | Visitas del ciclo |
| `spend_amount_per_block` / `points_per_block` | `100` / `10` | Explicar la regla de puntos |
| `period_type` / `period_days` | `days` / `30` | Vigencia del ciclo |

Cada recompensa (`RewardResource`) trae `required_stamps`, `points_required`, `threshold`,
`threshold_unit` y `redemptions_count`.

El progreso del cliente (`CustomerCardResource`) trae `progress_unit`, `points_count`,
`total_points_earned`, `cycle` (`started_at`, `expires_at`, `days`, `expired`) y
`progress` con `unit`, `current`, `required`, `remaining`, `percentage`, `next_reward`
y `available_rewards_count`.

---

## 2. Promociones (`POST /api/business/promotions`)

Una promoción **siempre** lleva `title` y `discount_type`
(`percentage`, `fixed_amount`, `free_item`, `gift`); con `percentage`/`fixed_amount`
hay que enviar `discount_value`. Existen sólo tres formas de programarla.

Los días de la semana usan **ISO-8601: 1 = lunes … 7 = domingo**.

### 2.1 `schedule_type: "indefinite"` — tiempo indefinido

Sin caducidad; se muestra ciertos días de la semana.

```json
{
  "title": "Martes de 2x1 en café",
  "description": "Todos los martes, todo el día",
  "discount_type": "percentage",
  "discount_value": 50,
  "schedule_type": "indefinite",
  "days_of_week": [2]
}
```

### 2.2 `schedule_type: "date_range"` — por rango de fechas

Visible sólo dentro del rango; desaparece al terminar la última fecha.

```json
{
  "title": "Promoción navideña",
  "description": "20% en toda la tienda",
  "discount_type": "percentage",
  "discount_value": 20,
  "code": "NAVIDAD20",
  "schedule_type": "date_range",
  "starts_at": "2026-12-01",
  "ends_at": "2026-12-24"
}
```

`starts_at` se guarda al inicio del día y `ends_at` al final, así que el rango incluye
ambas fechas completas. `ends_at` no puede ser anterior a `starts_at`.

### 2.3 `schedule_type: "time_range"` — por rango de hora

Uno o varios días dentro de una franja horaria (hora feliz). La franja puede cruzar la
medianoche (`20:00` → `02:00`).

```json
{
  "title": "Hora feliz",
  "description": "2x1 en bebidas",
  "discount_type": "free_item",
  "schedule_type": "time_range",
  "days_of_week": [5, 6],
  "start_time": "18:00",
  "end_time": "21:00"
}
```

### 2.4 Resumen de campos por tipo

| Campo | `indefinite` | `date_range` | `time_range` |
|---|---|---|---|
| `days_of_week` | **obligatorio** (1–7 días) | se ignora | **obligatorio** |
| `starts_at` / `ends_at` | se ignoran | **obligatorios** | se ignoran |
| `start_time` / `end_time` | se ignoran | se ignoran | **obligatorios** (`HH:MM`) |

Los campos que no aplican al tipo se guardan en `null`. `schedule_type` omitido equivale a
`date_range`.

### 2.5 Editar (`PUT /api/business/promotions/{promotion}`)

Sólo se actualizan los campos enviados. Si se envía `schedule_type`, hay que enviar
también los campos de ese tipo; si no, se conserva el actual y se normalizan los campos
de programación que sí lleguen.

### 2.6 Qué devuelve la API

`PromotionResource` (negocio) y `Client\PromotionResource` (cliente) incluyen
`schedule_type`, `schedule_type_label`, `days_of_week` (+ `days_of_week_labels`),
`start_time`, `end_time`, `starts_at`, `ends_at`, `is_available_now` y el resumen
`schedule` (`type`, `type_label`, `days_of_week`, `start_time`, `end_time`,
`is_available_now`).

`GET /api/client/promotions?available_now=true` devuelve sólo las que aplican en este
momento (tipo + día + fecha + hora), y `?schedule_type=time_range` filtra la hora feliz.
Desde el panel del negocio el equivalente es `GET /api/business/promotions`.

---

## 3. Registrar la compra (`POST /api/business/stamps/scan`)

```json
{ "code": "AB12CD34EF", "purchase_amount": 250 }
```

- `code` es el contenido del QR del cliente.
- `purchase_amount` es **obligatorio en tarjetas por consumo** y debe alcanzar al menos un
  bloque de gasto (si no, `422` con un mensaje que explica la regla).
- La respuesta incluye `data.points_awarded`, `message` y un resumen
  `customer_card` con `progress` (`unit`, `current`, `required`, `remaining`, …).

Anular la compra (`DELETE /api/business/stamps/{stamp}`) devuelve los sellos **o** los
puntos concedidos.
