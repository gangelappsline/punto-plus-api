# WhatsApp Cloud API en Punto Plus

Integración para enviar mensajes de WhatsApp desde la API (avisos de sellos, promociones,
recordatorios de canje…) y recibir las respuestas de los clientes por webhook.

Este documento tiene dos partes:

1. **Lo que ya está implementado** (código, endpoints, variables de entorno y pruebas).
2. **Lo que tienes que hacer tú en Meta** para que funcione con tu cuenta.

---

## 1. Lo que ya está implementado

### 1.1 Endpoints

| Método | Ruta | Para qué |
|---|---|---|
| `POST` | `/api/business/whatsapp/messages` | Enviar un mensaje (texto libre o plantilla) |
| `GET` | `/api/business/whatsapp/messages` | Historial: envíos, mensajes entrantes y acuses (`?direction=`, `?status=`, `?search=`) |
| `GET` | `/api/business/whatsapp/templates` | Plantillas de la WABA con su estado de aprobación |
| `GET` | `/api/webhooks/whatsapp` | Handshake de Meta (`hub.mode`, `hub.verify_token`, `hub.challenge`) |
| `POST` | `/api/webhooks/whatsapp` | Mensajes entrantes y acuses de entrega/lectura (firma `X-Hub-Signature-256`) |

Los tres primeros van bajo `auth:api` + `role:business,admin` (un administrador puede ver el
historial global). Los dos de `webhooks` son públicos: Meta no envía token, se autentican con el
verify token (GET) y con el App Secret (POST).

### 1.2 Ejemplos

Texto libre (sólo dentro de la ventana de 24 h):

```bash
curl -X POST https://api.tudominio.com/api/business/whatsapp/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "+5215512345678",
    "type": "text",
    "body": "¡Hoy 2x1 en cafés! Te faltan 2 sellos para tu recompensa."
  }'
```

Plantilla aprobada (obligatoria para iniciar conversación o pasadas 24 h):

```bash
curl -X POST https://api.tudominio.com/api/business/whatsapp/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "5512345678",
    "type": "template",
    "template_name": "promo_sellos",
    "template_language": "es_MX",
    "components": [
      { "type": "body", "parameters": [ { "type": "text", "text": "Ana" } ] }
    ]
  }'
```

Respuesta `201`:

```json
{
  "data": {
    "id": "0199...uuid",
    "direction": "outgoing",
    "status": "sent",
    "wa_message_id": "wamid.HBgNNTIxNTUxMjM0NTY3OBUCABIY...",
    "to_phone": "+525512345678",
    "type": "text",
    "body": "¡Hoy 2x1 en cafés! Te faltan 2 sellos para tu recompensa.",
    "sent_at": "2026-11-01T18:20:31+00:00"
  }
}
```

El `to` se normaliza a E.164: `5512345678` (10 dígitos) se completa con
`WHATSAPP_DEFAULT_COUNTRY_CODE`; cualquier otro formato se envía tal cual, así que ante la duda
manda el número con `+` y lada de país.

### 1.3 Qué guarda la API

Cada envío, cada mensaje entrante y cada acuse quedan en la tabla `whatsapp_messages`
(`direction`, `status`, `wa_message_id`, `body`, `payload` con la respuesta de Meta, `error`,
`error_code`). Así el panel puede mostrar el histórico y el estado real: `queued` → `sent` →
`delivered` → `read`, o `failed` con el código de Meta.

Los acuses llegan por webhook; el estado nunca retrocede (`read` no vuelve a `sent`).

### 1.4 Archivos

```
config/whatsapp.php                                  Configuración (env)
app/Models/WhatsAppMessage.php                       Historial
app/Enums/WhatsApp{Status,Direction}.php             Estados y sentido
app/Services/WhatsApp/WhatsAppClient.php             HTTP contra Graph API
app/Services/WhatsApp/WhatsAppMessenger.php          Envío + parseo del webhook
app/Support/PhoneNumber.php                          Normalización E.164
app/Exceptions/WhatsAppException.php                 Errores de Meta (code/subcode)
app/Http/Controllers/Api/Business/WhatsAppController.php
app/Http/Controllers/Api/Webhooks/WhatsAppWebhookController.php
app/Http/Requests/WhatsApp/SendWhatsAppMessageRequest.php
app/Http/Resources/WhatsAppMessageResource.php
app/Console/Commands/TestWhatsApp.php                php artisan punto-plus:whatsapp-test
tests/Feature/WhatsAppTest.php                       Envío, validación, historial, plantillas
tests/Feature/WhatsAppWebhookTest.php                Handshake, entrantes, acuses, firma
tests/Unit/PhoneNumberTest.php                       Normalización de teléfonos
```

### 1.5 Variables de entorno

```dotenv
WHATSAPP_ENABLED=true
WHATSAPP_PHONE_NUMBER_ID=1234567890
WHATSAPP_BUSINESS_ACCOUNT_ID=0987654321
WHATSAPP_ACCESS_TOKEN=EAAG...
WHATSAPP_APP_SECRET=abc123...
WHATSAPP_WEBHOOK_VERIFY_TOKEN=una-cadena-larga-que-tu-eliges
WHATSAPP_API_VERSION=v23.0
WHATSAPP_DEFAULT_COUNTRY_CODE=52
WHATSAPP_TEMPLATE_LANGUAGE=es_MX
```

`WHATSAPP_ENABLED=false` deja los endpoints en `503 whatsapp_disabled` sin borrar credenciales
(útil en local). Si faltan el token o el `phone_number_id`, la respuesta es
`503 whatsapp_not_configured` con la lista de variables que faltan.

---

## 2. Lo que tienes que hacer en Meta

> La interfaz de Meta cambia de vez en cuando; los nombres pueden variar ligeramente. La ruta
> lógica (app → producto WhatsApp → WABA → número → token → webhook) se mantiene.

### 2.1 Antes de empezar

- Cuenta de Facebook y una **cuenta de Meta Business** ([business.facebook.com](https://business.facebook.com)).
- Un **número de teléfono dedicado** para el negocio. No puede estar registrado en la app
  WhatsApp normal ni en WhatsApp Business app: si lo está, hay que eliminar esa cuenta primero
  (se pierde el historial) o usar otro número. Tampoco vale un número virtual que no reciba SMS
  o llamada, porque la verificación es por código.
- Un dominio con **HTTPS válido** y accesible desde internet (Meta no llama a `http://` ni a
  `localhost`; para pruebas locales usa un túnel tipo ngrok/Cloudflare Tunnel).
- Idealmente, la **verificación del negocio** hecha en Business Settings → Security Center.
  Sin ella puedes probar, pero los límites son mínimos.

### 2.2 Crear la app

1. Entra en [developers.facebook.com](https://developers.facebook.com) con tu cuenta.
2. **My Apps → Create App**.
3. Tipo de app: **Business** (si te ofrece "Other", elige Business igualmente).
4. Nombre de la app, email de contacto y selecciona tu **Business portfolio**.
5. En el panel de la app: **Add product → WhatsApp → Set up**.

Al terminar, Meta crea automáticamente una WABA de prueba ("Test WhatsApp Business Account") y
un número de prueba con un token temporal de 24 h. Con eso ya puedes enviar a **hasta 5 números
que tú registres** en "To" (modo desarrollo).

### 2.3 Apuntar los tres identificadores

En **WhatsApp → API Setup** (o "Empezar a usar la API"):

| Dato | Dónde | Variable |
|---|---|---|
| **Phone number ID** (id del número, no el teléfono) | "From" → número → *Phone number ID* | `WHATSAPP_PHONE_NUMBER_ID` |
| **WhatsApp Business Account ID** (WABA ID) | Arriba del panel o en Business Settings → Accounts → WhatsApp accounts | `WHATSAPP_BUSINESS_ACCOUNT_ID` |
| **Token temporal** (24 h, sirve sólo para probar) | "Temporary access token" | `WHATSAPP_ACCESS_TOKEN` (provisional) |

### 2.4 Token permanente (lo importante)

El token de 24 h caduca y no sirve en producción. Hay que crear un **usuario del sistema**:

1. [business.facebook.com](https://business.facebook.com) → **Configuración del negocio**
   (Business Settings).
2. **Usuarios → Usuarios del sistema → Agregar**: nombre (p. ej. `punto-plus-api`) y rol
   *Admin* o *Employee*.
3. **Asignar activos**: la **app** y la **cuenta de WhatsApp (WABA)** con control total /
   administrar.
4. Con el usuario del sistema seleccionado: **Generar token nuevo** → elige tu app → permisos:
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
   - (opcional) `business_management`
5. Copia el token (sólo se muestra una vez) y guárdalo en `WHATSAPP_ACCESS_TOKEN`.

Nota: en modo desarrollo, el token permanente también funciona sólo con los números de prueba
hasta que pongas la app en modo Live y el número de producción.

### 2.5 App Secret (firma del webhook)

- **App settings → Basic → App secret → Show**. Cópialo en `WHATSAPP_APP_SECRET`.

Con ese secreto la API valida la cabecera `X-Hub-Signature-256` de cada webhook; si no coincide,
responde `403 invalid_signature` y no guarda nada.

### 2.6 Configurar el webhook

1. En la app: **WhatsApp → Configuration → Webhooks → Edit**.
2. **Callback URL**: `https://api.tudominio.com/api/webhooks/whatsapp`
3. **Verify token**: exactamente el valor de `WHATSAPP_WEBHOOK_VERIFY_TOKEN`.
4. **Verify and save**. Meta hace un `GET` con `hub.mode=subscribe`; la API devuelve el
   `hub.challenge` y queda verificado.
5. En **Webhook fields**, suscribe **`messages`** (opcional: `message_template_status_update`
   para enterarte de las aprobaciones de plantillas).
6. **Suscribe la app a la WABA**. Si no, no llega ningún webhook aunque la URL esté verificada:

   ```bash
   curl -X POST "https://graph.facebook.com/v23.0/$WHATSAPP_BUSINESS_ACCOUNT_ID/subscribed_apps" \
     -H "Authorization: Bearer $WHATSAPP_ACCESS_TOKEN"
   ```

   Debe devolver `{"success": true}`.

### 2.7 Poner el número de producción

1. **WhatsApp → API Setup → Add phone number** (o Business Settings → Accounts → WhatsApp
   accounts → Numbers).
2. Datos del negocio: nombre visible (el que verá el cliente), categoría y descripción. El nombre
   debe cumplir las [políticas de nombres](https://www.facebook.com/business/help/338047025165344).
3. Verifica el número por SMS o llamada y define el **PIN de verificación en dos pasos** (apúntalo:
   te lo pedirá Meta si algún día hay que volver a registrar el número).
4. Actualiza `WHATSAPP_PHONE_NUMBER_ID` con el id del número definitivo.

Con esto ya envías desde tu número real. Recuerda que hasta poner la app en **Live** y tener el
negocio verificado, los destinatarios siguen limitados a la lista de prueba.

### 2.8 Plantillas (para iniciar conversación)

Fuera de la ventana de 24 h **sólo** se pueden enviar plantillas aprobadas.

1. **WhatsApp Manager → Message templates → Create template**.
2. Categoría:
   - **Marketing** → promociones, novedades, "vuelve a comprarnos".
   - **Utility** → avisos ligados a una acción del cliente (sello registrado, canje aprobado,
     saldo de sellos).
   - **Authentication** → códigos de verificación.
3. Idioma: `es_MX` (o el que uses; cada idioma es una plantilla distinta).
4. Cuerpo con variables `{{1}}`, `{{2}}`… Ejemplos útiles para fidelidad:

   - `promo_sellos` (Marketing):
     `¡Hola {{1}}! En {{2}} tienes una promoción activa: {{3}}. Válida hasta {{4}}.`
   - `sello_registrado` (Utility):
     `¡Hola {{1}}! {{2}} acaba de registrar un sello en tu tarjeta. Llevas {{3}} de {{4}}.`
   - `recompensa_lista` (Utility):
     `¡Hola {{1}}! Ya puedes canjear tu recompensa en {{2}}: {{3}}.`

5. Espera la aprobación (de minutos a unas horas; si la rechazan, Meta indica el motivo).
6. Comprueba desde la API que aparecen:

   ```bash
   curl https://api.tudominio.com/api/business/whatsapp/templates -H "Authorization: Bearer $TOKEN"
   ```

El nombre de la plantilla viaja en `template_name` y los valores de las variables en
`components[].parameters[]`, en el mismo orden que en la plantilla.

> Desde octubre de 2026 cada mensaje entregado se factura según categoría y país; las respuestas
> de servicio dentro de la ventana también se cobran. Verifica la tarifa vigente en el panel de
> Meta antes de lanzar campañas.

### 2.9 Modo Live y facturación

1. **Business Settings → Billing**: añade método de pago a la WABA.
2. Arriba en el panel de la app: cambia **App Mode** de *Development* a **Live**.
3. Si vas a usar permisos avanzados, prepara la **App Review** (no suele ser necesaria si sólo
   usas tu propia WABA con un usuario del sistema).
4. Revisa los límites de mensajería en **WhatsApp Manager → Insights/Limits**:
   250 → 2.000 → 10.000 → 100.000 → ilimitado destinatarios únicos por cada 24 h. Sube según el
   volumen real y la calidad del número.

---

## 3. Verificación de punta a punta

```bash
# 1) Migrar y limpiar configuración
php artisan migrate --force
php artisan config:clear

# 2) Prueba real de envío (texto libre a un número de prueba)
php artisan punto-plus:whatsapp-test +5215512345678

# 3) Si ya tienes plantilla aprobada
php artisan punto-plus:whatsapp-test +5215512345678 --template=hello_world --language=en_US
```

Salida esperada:

```
Mensaje aceptado por WhatsApp Cloud API.
  id: wamid.HBgNNTIx...
  estado: sent · destino: +5215512345678
```

Comprobaciones del webhook:

```bash
# Handshake (debe devolver el challenge en texto plano)
curl "https://api.tudominio.com/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=$WHATSAPP_WEBHOOK_VERIFY_TOKEN&hub.challenge=123"

# Webhook firmado igual que Meta
BODY='{"object":"whatsapp_business_account","entry":[]}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$WHATSAPP_APP_SECRET" | sed 's/^.* //')
curl -X POST https://api.tudominio.com/api/webhooks/whatsapp \
  -H "Content-Type: application/json" \
  -H "X-Hub-Signature-256: sha256=$SIG" \
  -d "$BODY"
```

Respuesta: `{"received":true,"messages":0,"statuses":0}`.

Para ver los acuses en acción: envía con `whatsapp-test`, contesta desde el teléfono y consulta
`GET /api/business/whatsapp/messages`: el mensaje saliente debe pasar a `delivered`/`read` y el
entrante aparecer en `direction: incoming`.

---

## 4. Errores frecuentes

| Código / síntoma | Causa | Solución |
|---|---|---|
| `401` / `code 190` | Token caducado o revocado | Regenerar el token del usuario del sistema y actualizar `WHATSAPP_ACCESS_TOKEN` |
| `code 100` (invalid parameter) | `WHATSAPP_PHONE_NUMBER_ID` incorrecto (suele ser el id de la WABA) | Copiar el *Phone number ID* que aparece junto al número |
| `code 131030` | Destinatario fuera de la lista de prueba (máx. 5) | Añadir el número en API Setup o pasar a número de producción + app en Live |
| `code 131047` | Pasaron más de 24 h desde el último mensaje del cliente | Enviar una **plantilla** aprobada |
| `code 132000` / `132001` | Nº de variables distinto al de la plantilla, o plantilla inexistente/no aprobada en ese idioma | Revisar `components` y `template_language` |
| `code 131026` | El número no puede recibir mensajes (no tiene WhatsApp, bloqueó, número inválido) | Verificar el número con el cliente |
| `code 131056` | Demasiados mensajes al mismo número en poco tiempo | Enviar más despacio / agrupar |
| Webhook no llega | URL sin HTTPS público, verify token distinto, campo `messages` no suscrito **o** app no suscrita a la WABA | Repetir pasos 2.6 y `subscribed_apps` |
| `403 invalid_signature` | `WHATSAPP_APP_SECRET` incorrecto | Copiar de App settings → Basic |
| `503 whatsapp_not_configured` | Faltan variables en el `.env` | Revisar el bloque de 1.5 y `php artisan config:clear` |

Los errores de Meta se devuelven al cliente así (y quedan en `whatsapp_messages.error_code`):

```json
{
  "message": "(#131030) Recipient phone number not in allowed list",
  "error": "whatsapp_error",
  "code": 131030,
  "subcode": 2494073,
  "type": "OAuthException"
}
```

---

## 5. Checklist final

- [ ] `WHATSAPP_ENABLED=true`
- [ ] `WHATSAPP_PHONE_NUMBER_ID` = id del número emisor
- [ ] `WHATSAPP_BUSINESS_ACCOUNT_ID` = WABA ID
- [ ] `WHATSAPP_ACCESS_TOKEN` = token permanente del usuario del sistema
- [ ] `WHATSAPP_APP_SECRET` copiado de la app
- [ ] `WHATSAPP_WEBHOOK_VERIFY_TOKEN` inventado por ti y repetido en Meta
- [ ] Webhook verificado y campo **messages** suscrito
- [ ] App suscrita a la WABA (`subscribed_apps` → `success: true`)
- [ ] Número de producción verificado y PIN de 2FA guardado
- [ ] Al menos una plantilla aprobada
- [ ] Método de pago configurado y app en **Live**
- [ ] `php artisan punto-plus:whatsapp-test` envía y devuelve `wamid...`
- [ ] El historial pasa a `delivered`/`read` cuando el cliente recibe/lee

---

## 6. Operación y seguridad

- **Nunca** subas el token al repositorio: vive en `.env` o en el gestor de secretos del
  despliegue. Los `.env` están fuera de Git.
- Rota el token del usuario del sistema si se filtra (revócalo en Business Settings y genera otro).
- Las credenciales de Meta son por cuenta/WABA: si mañana hay varias WABA (multi-negocio), habrá
  que mover las credenciales a la tabla de negocios; hoy la configuración es global
  (`config/whatsapp.php`).
- La API responde `200` a los webhooks válidos aunque no haya nada que guardar, porque Meta
  reintenta ante cualquier otro código.
- Para envíos masivos (promos a toda la base), encolarlo en un Job y respetar el límite de
  mensajería y la calidad del número; un pico de bloqueos puede bajar el tier.
- Cumplimiento: el cliente debe haber dado su consentimiento para recibir mensajes
  (alta en el programa de fidelidad + casilla de aceptación). Guarda esa evidencia.
