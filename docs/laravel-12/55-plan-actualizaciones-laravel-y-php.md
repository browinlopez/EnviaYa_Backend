# 55 — Estrategia de actualizaciones y soporte

Laravel 12 recibe **correcciones de seguridad hasta 24 de febrero de 2027** según la tabla de soporte de Laravel. Su periodo de bug fixes generales terminó el 13 de agosto de 2026. La documentación oficial presenta Laravel 13 como alternativa con soporte posterior.

1. Mantener Laravel 12 actualizado dentro de sus releases de seguridad mientras se prepare migración.
2. Fijar una versión de PHP **compatible con todas las dependencias** y probar upgrades en CI.
3. Antes de Laravel 13: leer upgrade guide, verificar packages, remover APIs obsoletas, ejecutar suites y pruebas de DB/colas/Reverb.
4. Evitar upgrades de versiones mayores mezclados con cambios funcionales.
5. Mantener `composer.lock` en control de versiones y revisar `composer audit` regularmente.

Referencia oficial: https://laravel.com/docs/12.x/releases y https://laravel.com/docs/13.x/releases.
