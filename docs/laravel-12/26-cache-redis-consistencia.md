# 26 — Redis, caché y consistencia de respuestas

> **Cachear es opcional y posterior a medir.** Un mal índice no se arregla permanentemente con caché; una caché sin aislamiento o invalidación puede empeorar el sistema.

## Política de admisión de caché

Cachear solo si: lectura frecuente y costosa; cambios relativamente infrecuentes; TTL razonable; datos no sensibles o claves bien aisladas; invalidación definida; estrategia ante caída de Redis. Mantener respuestas correctas sin caché como fuente de verdad.

| Información | Política sugerida |
|---|---|
| Catálogos de referencia | TTL moderado + invalidación al actualizar |
| Reporte agregado de varios segundos | Cache de resultado por tenant + filtros + rango |
| Estado de una orden en tiempo real | Evitar caché de larga vida; preferir lectura DB actualizada |
| Autorización / permisos | Resolver en cada acceso según contexto, no usar caché compartida sin claves completas e invalidación |
| Listados cambiantes | Evaluar TTL corto, invalidación y paginación antes de cachear |

## Query Object y caché separadas

La `Query` debe seguir siendo correcta sin Redis. Cuando el costo lo justifique, una capa de aplicación pequeña coordina `Cache::remember`; no meter Redis en un modelo Eloquent ni acoplar todas las queries a un repositorio genérico.

```php
$key = sprintf('v1:tenant:%d:report:%s', $tenantId, $filterFingerprint);

$report = Cache::remember(
    $key,
    now()->addSeconds(60),
    fn () => $reportQuery->handle($tenantId, $filters),
);
```

`$filterFingerprint` debe ser un digest **canónico** de filtros validados y ordenados (sin secretos). Incluir tenant, idioma/roles/capacidades si alteran el resultado, permisos y versión de formato cuando aplique. Evitar cachear objetos `Request` y serializaciones inestables; preferir arrays/DTO de lectura simples cuando sea apropiado.

## Invalidación y stampede

- Definir invalidación tras una escritura **confirmada**. No invalidar antes de commit si la nueva lectura puede obtener el estado anterior.
- Usar TTL acotado como defensa secundaria; recordar que un TTL es compromiso de frescura, no garantía de coherencia.
- Para hotspots, considerar `Cache::lock()` y doble verificación, o estrategias de `stale-while-revalidate` disponibles en Laravel (`Cache::flexible`) **cuando la tolerancia a datos obsoletos sea explícita**.
- Evitar claves de alta cardinalidad no controlada, especialmente con combinaciones libres de filtros.
- Evitar cachear en memoria local de workers de larga vida sin manejo de rehidratación/caducidad.

## Aislamiento y pruebas

1. Dos tenants nunca comparten una clave de datos de negocio.
2. Un cambio de rol revoca el acceso sin depender de caché vieja.
3. Cambios concurrentes no publican/guardan estados inconsistentes.
4. Un fallo de Redis no debe romper innecesariamente una lectura crítica (decisión de tolerancia a fallos documentada).
5. Medir tasa de aciertos, latencia caché/DB y costo de invalidación.

**Fuentes:** https://laravel.com/docs/12.x/cache , https://laravel.com/docs/12.x/redis , https://laravel.com/docs/12.x/queues .
