# 14 — Protocolo para modificaciones pequeñas y localizadas

## Objetivo

Corregir o añadir comportamiento sin producir diffs ruidosos, regresiones o cambios fuera del alcance.

## Secuencia obligatoria

1. **Localizar entrada:** ruta HTTP/Job/Command y prueba que cubre el flujo.
2. **Seguir el flujo:** Request → Controller → Action/Query → Model/Integration → Resource.
3. **Identificar dueño de la regla:** ver `17-matriz-de-ubicacion.md`.
4. **Cambiar lo mínimo:** una clase cuando sea viable; tocar otras solo por contrato, pruebas, DB o registro explícito.
5. **Prueba roja → verde** cuando sea viable; agregar caso de regresión.
6. **Revisar diff y efectos:** revisar `git diff --stat`, migraciones, breaking changes y autorización.
7. **Verificar:** test focalizado, Pint, suite relevante y route:list si hay rutas.
8. **Comunicar:** archivos tocados, decisiones, riesgos y pruebas.

## Semáforo de modificación

- **Local:** cambiar un mensaje, regla o consulta sin cambiar contrato. Esperado: 1–3 archivos incluyendo tests.
- **Coordinada:** nueva ruta/caso de uso o migración. Esperado: varios archivos dentro de **un** módulo + wiring + tests.
- **Transversal:** seguridad, autentificación, base de datos compartida, contrato API. Requiere plan de compatibilidad y revisión mayor.

*Son orientaciones, nunca objetivos que sacrifiquen calidad.*

## Para agentes de código

Antes de tocar archivos, responder internamente:

- ¿Cuál es la fuente de verdad del comportamiento?
- ¿Hay un helper o Action existente?
- ¿Qué tests indican el contrato actual?
- ¿Puedo implementar sin tocar otro módulo?
- ¿Estoy creando una abstracción necesaria o solo desplazando líneas?

## No permitido

- «Aprovechar» una corrección para renombrar 40 clases.
- Reformatear todo el repositorio cuando solo cambia un endpoint.
- Eliminar pruebas porque fallan tras un refactor.
- Modificar contratos públicos en silencio.
