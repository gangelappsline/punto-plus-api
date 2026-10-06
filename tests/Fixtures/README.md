# Fixtures de pruebas

- `keys/oauth-private.key`, `keys/oauth-public.key`: par RSA **exclusivo para la suite de
  pruebas** (Passport lo carga vía `PASSPORT_KEYS_PATH`). Nunca se usa en producción.
- `images/logo.png`: PNG 1x1 válido para probar la subida de assets sin depender de GD.
