# 42 — CI/CD, validaciones automáticas y reglas de PR

**Estado:** obligatorio si trabaja más de una persona o una herramienta de IA.

## Quality gates mínimos, en este orden

| Gate | Qué evita |
|---|---|
| `composer validate --strict` | Metadatos/dependencias inconsistentes |
| `composer audit` | Dependencias con vulnerabilidades conocidas (requiere acceso a fuentes actualizadas) |
| `pint --test` | Cambios de formato arbitrarios |
| Análisis estático instalado | Tipos/nulos/métodos erróneos |
| Tests Unit + Feature | Regresiones del comportamiento |
| Tests de contratos API | Respuestas incompatibles con consumidores |
| Pruebas de migración | Deploy que rompe datos existentes |
| Análisis de secretos y dependencias | Filtraciones y cadena de suministro |

No imponer todos en día uno si faltan herramientas: habilitar gates de forma gradual y registrar excepciones con responsable y vencimiento.

## PR mínimo

- Qué comportamiento cambia, por qué, qué rutas/archivos se tocan.
- Contrato API antes/después cuando aplique.
- Captura de SQL y `EXPLAIN` para cambios de consultas importantes.
- Resultado de pruebas **realmente ejecutadas** y entorno utilizado.
- Plan de despliegue/rollback para datos, queues, Reverb o configuración.
- Revisión por dueño del módulo y, si hay cambios de acceso, revisión de seguridad.

## Control de alcance

- Un PR por objetivo de negocio. Refactors no relacionados van en cambios distintos.
- Prohibido reformatear todo un módulo por una corrección de una línea.
- Bloquear merge a rama principal si fallan gates acordados y no existe excepción formal.
- Branch protection, checks obligatorios, reviews y permisos de despliegue limitados.
- Preparar despliegues repetibles y trazables: mismo commit/artefacto entre entornos.

## Entornos

- `.env.example` documenta nombres de variables **sin valores secretos**.
- CI usa BD de test desechable, nunca datos reales; Redis/Reverb si los tests lo necesitan.
- No imprimir secretos en logs del runner. En producción aplicar migraciones con plan y backups.

**Fuentes:** https://laravel.com/docs/12.x/testing · https://laravel.com/docs/12.x/deployment · https://www.php-fig.org/psr/psr-12/
