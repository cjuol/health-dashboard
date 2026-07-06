# Brief para agente — App Android "HC Movimiento": adaptación a backend multiusuario

## Contexto

La app Android lee buckets de movimiento de Health Connect (pasos, distancia, plantas)
y los envía a un dashboard de salud autoalojado (Symfony + Postgres + sidecar Python).
El backend va a pasar de mono-usuario a multiusuario.

**Decisión de diseño del backend (ya tomada, no negociable desde la app):**
la identidad del usuario la determina el **token Bearer**. El servidor emitirá un token
por usuario y lo mapeará internamente a su `account_id`. El payload NO lleva ningún
identificador de usuario.

Consecuencia: cada instalación de la app (un teléfono = un usuario) simplemente
configura su propio token. No hay multi-perfil dentro de una misma instalación.

## Contrato HTTP (sin cambios de forma)

```
POST {vps.baseUrl}/api/v1/health/movement
Authorization: Bearer {vps.token}
Content-Type: application/json

{
  "device": "Pixel 8 de Cristóbal",          // opcional, etiqueta libre, máx. 64 chars
  "buckets": [
    {
      "bucket_start": "2026-07-06T10:00:00+02:00",  // ISO-8601 con offset, obligatorio
      "bucket_end":   "2026-07-06T10:15:00+02:00",  // obligatorio
      "origin":       "com.google.android.apps.fitness",  // package HC, obligatorio
      "steps":        412,                    // int, default 0
      "distance_m":   310.5,                  // float, opcional
      "floors":       1                       // int, opcional
    }
  ]
}
```

Respuestas:

| Código | Cuerpo | Significado |
|--------|--------|-------------|
| 200 | `{"upserted": <n>}` | Ingesta correcta |
| 401 | `{"error": "unauthorized"}` | Token ausente, incorrecto o **revocado** |
| 422 | `{"error": "..."}` | Payload inválido |

## Cambios requeridos en la app

1. **Token configurable por instalación.** Verificar que `vps.token` se lee siempre de
   los ajustes del usuario y no está cacheado/hardcodeado en ningún punto del flujo de
   envío. Si ya es así, no tocar nada.

2. **Manejo explícito de 401.** Con tokens por usuario, un 401 pasa a significar
   "token revocado o mal copiado", no un fallo transitorio:
   - No reintentar en bucle ni encolar reintentos automáticos tras un 401.
   - Mostrar una notificación persistente al usuario: "Token rechazado por el
     servidor — revisa los ajustes".
   - Conservar los buckets pendientes localmente para reenviarlos cuando el token
     vuelva a ser válido (no descartar datos).

3. **Validación del token al guardarlo (recomendado).** En la pantalla de ajustes,
   al guardar `vps.baseUrl` + `vps.token`, hacer un POST de prueba con
   `{"buckets": []}` y reflejar el resultado (200 = OK, 401 = token inválido,
   error de red = URL inválida).

4. **Identificador de dispositivo estable (opcional, recomendado).** Rellenar el campo
   `device` con un valor estable y distinguible (p. ej. `Build.MODEL` + sufijo elegido
   por el usuario) en lugar de dejarlo vacío. Con varios usuarios enviando datos,
   sirve para diagnóstico en el servidor. Sigue siendo una etiqueta informativa:
   la identidad la da el token.

## Qué NO hacer

- NO añadir `user_id`, `account`, email ni ningún identificador de usuario al payload.
- NO cambiar la ruta (`/api/v1/health/movement`), el método ni el esquema de buckets.
- NO implementar multi-perfil dentro de la app: una instalación pertenece a un usuario.
- NO tratar 401 como error reintentable.

## Criterios de aceptación

- [ ] Con un token válido, la sincronización funciona exactamente igual que hoy.
- [ ] Con un token inválido/revocado: la app notifica al usuario, deja de reintentar
      y no pierde los buckets acumulados localmente.
- [ ] Al corregir el token en ajustes, los buckets retenidos se reenvían.
- [ ] Guardar ajustes valida el token contra el servidor y muestra el resultado.
- [ ] El campo `device` llega relleno con un valor estable del dispositivo.
