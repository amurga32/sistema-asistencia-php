# Sistema de Control de Empleados y Asistencia (PHP + SQLite)

Sin dependencias: PHP 8.1+ con `pdo_sqlite`. Responsive (celular, tablet y PC).

## Ejecutar

```bash
cd asistencia
php -S localhost:8000 -t public
```

Abrí http://localhost:8000 — la base `data/asistencia.sqlite` se crea sola (y se migra si ya tenías una).

**Primer acceso:** legajo `0001` · clave `admin123` (cambiala desde "Clave").

## Funciones
- Login por legajo o DNI, roles `admin` / `empleado`, CSRF, claves con `password_hash`.
- Empleados: alta, edición, búsqueda, activar/desactivar, horario propio.
- Marcación de entrada y salida (la hora la toma el servidor); estado Puntual / Tardanza.
- Panel admin, reporte por fechas y empleado, carga/corrección manual, exportación CSV.

## Control anti "marcar por otro"
1. **Solo desde el local** (Ajustes → IPs permitidas): se marca únicamente desde la red del local. Acepta IPs y rangos (`190.12.34.0/24`). Vacío = sin restricción.
2. **Foto al marcar**: la cámara frontal saca una foto en cada entrada y salida; el admin las ve en Asistencia y Auditoría. Se puede desactivar en Ajustes.
3. **Auditoría y alertas** (menú Auditoría): cada marcación guarda IP, dispositivo y foto. Genera alerta cuando:
   - alguien abre o intenta marcar desde fuera de la red del local;
   - el mismo dispositivo lo usa otro empleado en menos de 10 minutos (`ALERTA_MISMO_DISPOSITIVO_MIN`).
   También registra los logins fallidos. El tablero muestra las alertas del día.
- **Kiosco compartido** (Ajustes): si usan una sola PC o tablet del local, marcala como kiosco. No genera alertas por "mismo dispositivo" y cierra la sesión después de cada marcación.

## Importante para producción
- **HTTPS es obligatorio para la cámara** en celulares (en `localhost` funciona sin HTTPS).
- **IP del local**: si tu proveedor de internet cambia la IP (IP dinámica), actualizala en Ajustes o pedí una IP fija. Con datos móviles no coincide con la del local, y es lo que se busca.
- Si el sistema queda detrás de un proxy o CDN tuyo, poné `TRUST_PROXY = true` en `app/seguridad.php`; si no, dejalo en `false` (la cabecera `X-Forwarded-For` se puede falsificar).
- Las fotos son datos personales: avisá a los empleados y definí cuánto tiempo las guardás (están en `data/fotos/`, fuera de la web y solo las ve el admin).
- Hacé copia de seguridad de `data/` con regularidad. Apuntá el servidor web a `public/` y dale permiso de escritura a `data/`.
- Una marcación por empleado y día (entrada + salida).

## Estructura
`public/` páginas y CSS (raíz web) · `app/` lógica compartida · `data/` base SQLite y fotos (no exponer).
