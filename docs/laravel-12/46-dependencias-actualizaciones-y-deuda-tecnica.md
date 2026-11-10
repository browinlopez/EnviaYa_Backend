# 46 — Dependencias, upgrades y deuda técnica controlada

**Estado:** continuo.

## Política de dependencias

- Usar paquetes externos solo si el valor supera mantenimiento, compatibilidad, seguridad y complejidad.
- Revisar release notes y soporte para Laravel/PHP reales del proyecto antes de instalar.
- Preferir APIs nativas de Laravel para auth, cache, colas, eventos, validación y HTTP cuando sean suficientes.
- Mantener `composer.lock` y aplicar `composer audit` en CI; definir ventana de actualización de seguridad.
- Nunca realizar `composer update` indiscriminado como parte de una corrección funcional pequeña.
- Revisar transitive deps, permisos, licencias y estado de mantenimiento del paquete.

## Gestión de deuda sin destruir modularidad

- Registrar deuda con módulo, impacto, costo estimado y criterio de cierre.
- Refactor orientado a evidencia: bugs, cambios frecuentes, duplicación costosa, N+1 u observabilidad insuficiente.
- No crear interfaces/abstracts solo para «tener arquitectura limpia».
- Unificar código compartido solo cuando existan al menos dos consumidores reales con la **misma semántica**.
- Revisar continuamente archivos gigantes, pero también *sobrefragmentación* (clases triviales, wrappers vacíos, cascadas de tres pasamanos).

## Evolución de Laravel

- Mantener la rama 12 mientras sea requisito del proyecto; consultar guía de actualización antes de cambiar versión mayor.
- Preparar pruebas y pipeline para compatibilidad PHP/framework/driver/Redis/Reverb.
- No trasladar comportamientos documentados únicamente para Laravel 13.x a un proyecto 12.x sin verificar.

**Fuentes:** https://laravel.com/docs/12.x/upgrade · https://laravel.com/docs/12.x/deployment · https://getcomposer.org/doc/03-cli.md#audit
