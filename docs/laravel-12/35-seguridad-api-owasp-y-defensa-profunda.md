# 35 — Seguridad de API basada en OWASP

**Estado:** obligatorio; aplicar por riesgo, no como colección de librerías.

## Mapa de amenazas → defensa

| Riesgo OWASP API Top 10 (2023) | Regla verificable |
|---|---|
| BOLA / acceso a objeto ajeno | Buscar por tenant autorizado + Policy por recurso |
| Autenticación débil | Ciclo de vida, expiración, revocación, almacenamiento seguro |
| Propiedades no autorizadas | Validación allowlist, `$fillable` seguro, Resource de salida |
| Consumo sin límites | Límites de tamaño, rate limit, paginación y tiempos máximos |
| Funciones sin permiso | Policies/Gates por operación, incluidos endpoints internos |
| Flujos sensibles abusables | Anti-automatización/rate limits por operación de negocio |
| SSRF | No solicitar URLs arbitrarias; allowlist de hosts y resolver redirecciones/IPs con cuidado |
| Mala configuración | `APP_DEBUG=false`, HTTPS, CORS mínimo, acceso restringido a dashboards |
| Inventario API inadecuado | Documentar y retirar rutas obsoletas; inventario con `route:list` |
| APIs externas poco confiables | Timeouts, validación de respuestas, firmas, errores y fallas controladas |

## Alcances concretos

- `FormRequest` para entradas: nunca validar solo en frontend.
- `Policy` para objeto y operación: no confundir permiso de token con permiso sobre el recurso.
- `JsonResource` para campo de salida: negar por defecto secretos, hashes, llaves y PII no requerida.
- `RateLimiter` o throttle por identidad + tenant y/o IP según endpoint: evitar una única política global.
- Archivos: límites de peso, tipo real, extensión, permisos, nombres no confiables, antivirus si la amenaza lo amerita.
- Webhooks: verificación de firma con cuerpo bruto y timestamp, anti-replay y deduplicación.
- URLs solicitadas por usuario: riesgo SSRF; no permitir HTTP hacia redes internas o metadata cloud.
- Secrets: usar gestores de secretos/variables de entorno, con control de acceso y rotación.

## Evidencia por PR

Cada endpoint nuevo debe incluir pruebas de entradas inválidas, usuario anónimo, usuario sin permiso, usuario de otro tenant y consumo excesivo cuando aplique.

**Fuente:** https://api-security.owasp.org/editions/2023/en/0x11-t10/ · https://laravel.com/docs/12.x/validation · https://laravel.com/docs/12.x/authorization
