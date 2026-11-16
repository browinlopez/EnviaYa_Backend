# 51 — Límites de tamaño con criterio, no con trucos

El script `scripts/check_file_sizes.py` recorre archivos PHP de `app/` y reporta los que superan los límites de revisión descritos en `03`. No cuenta archivos de vendor, migraciones ni clases generadas.

```bash
python3 scripts/check_file_sizes.py --root .
python3 scripts/check_file_sizes.py --root . --fail
```

El modo normal solo informa; el modo `--fail` puede bloquear cambios y debe activarse tras establecer una política de excepciones y deuda legacy.

Los umbrales **no son una medición de calidad**. La solución correcta puede ser dejar un modelo de 140 líneas porque reúne relaciones muy relacionadas, documentarlo y evitar una abstracción inútil. Nada de escribir múltiples sentencias en una línea, delegar en helpers opacos o dividir sin responsabilidad clara.

Revisar primero el tamaño y después: cohesión, nombres, duplicación, tests, dependencias y número de archivos tocados por cambio.
