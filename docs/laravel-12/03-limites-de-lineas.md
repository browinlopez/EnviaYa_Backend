# 03 — Archivos pequeños: objetivos y señales de extracción

**Meta principal:** un desarrollador debe entender y modificar una responsabilidad sin navegar un archivo enorme. Los números de abajo son umbrales **orientativos, no bloqueos automáticos**; cuentan líneas físicas del archivo (incluyen imports, comentarios y espacios) para simplificar la revisión.

| Tipo de archivo | Objetivo habitual | Revisar diseño si supera |
|---|---:|---:|
| Controller invocable | 15–35 | 50 |
| FormRequest | 15–45 | 75 |
| JsonResource | 15–40 | 65 |
| Action | 20–60 | 90 |
| Query | 20–55 | 80 |
| Policy | 15–55 | 90 |
| Job / Listener | 20–55 | 80 |
| Clase de integración | 25–70 | 110 |
| Modelo Eloquent | 25–80 | 120 |
| Prueba focalizada | 20–80 | 120 |

*Las migraciones, archivos generados y clases que mapean contratos extensos pueden superar estos objetivos justificadamente.*

## Cuándo dividir

1. El archivo tiene **dos motivos independientes de cambio**.
2. Hay una sección cohesiva con un nombre claro y una entrada/salida explícita.
3. Un método incorpora reglas de distintas etapas que conviene probar separadamente.
4. Hay código duplicado significativo que puede centralizarse sin romper las fronteras.

## Cuándo NO dividir

- Para ocultar complejidad detrás de `Helper::run()` o de una cadena de wrappers triviales.
- Si implica abrir seis archivos para seguir una operación de tres instrucciones.
- Si el fragmento extraído tiene nombre vago (`process`, `handleData`, `executeTask`).
- Si se multiplica el número de abstracciones sin mejorar tests, reutilización o navegación.

## Métodos

- Idealmente 5–20 líneas para lógica de negocio **cuando mantenga cohesión**; revisa métodos mayores de ~30.
- Preferir retornos tempranos y nombres descriptivos sobre muchos niveles de indentación.
- No reducir líneas fusionando sentencias, anidando ternarios ni omitiendo validaciones.
- Una clase = una responsabilidad; varios métodos breves pueden ser más claros que muchas clases diminutas.

## Ejemplo conceptual

**Evitar:** `OrderService` con `create`, `update`, `cancel`, `pay`, `notify`, `export`.

**Preferir:** `CreateOrder`, `CancelOrder`, `RegisterOrderPayment`; cada una encapsula un caso de uso y se modifica sin tocar las demás.
