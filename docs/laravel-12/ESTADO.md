# Estado del backend frente al estándar

**Medido el 10 de noviembre de 2026.** Laravel 12.66 · 1074 pruebas en verde ·
14 commits de esta jornada.

Este archivo responde una sola pregunta: **qué falta y por qué**. Las
decisiones razonadas viven en [`EXCEPCIONES.md`](EXCEPCIONES.md); aquí está el
marcador.

---

## 1 · Lo que el estándar encontró, que es lo que justifica todo

Tres fallos, y **ninguno es de estilo ni de carpetas**. Los tres salieron de
medir, no de leer.

### Bold se llamaba sin tiempo de espera — ARREGLADO

Las siete llamadas HTTP de `BoldService` no ponían `timeout` ni
`connectTimeout`, y el cliente de Laravel no pone límite por defecto.
`OrderController` y `PaymentController` reciben ese servicio **inyectado**:
las llamadas ocurren dentro de la petición del comprador. Con Octane los
trabajadores son un grupo fijo, así que bastaba que Bold **tardara** —ni
siquiera que fallara— para agotarlos y tumbar el backend entero, no solo los
pagos.

### Reverb tampoco — ARREGLADO

El mismo fallo en otro sitio. Seis de los siete eventos usan
`ShouldBroadcastNow`, o sea que la llamada a `ws.vecipaya.com` ocurre dentro
de la petición: al aceptar un pedido, al escribir en el chat, al usar un
código de portería. `client_options` estaba vacío. El `try/catch` que envuelve
cada `broadcast()` atrapa excepciones, **no lentitud**: una conexión colgada
no lanza nada, espera.

### Veinte avisos de seguridad en las dependencias — PENDIENTE

`composer audit` —que §46 pide y el CI no corría— devuelve **20 avisos en 10
paquetes**. `symfony/yaml` y `symfony/http-foundation` en alta, y
`phpoffice/phpspreadsheet` con dos altas: es lo que procesa **los Excel que
suben los tenderos**, o sea entrada de fuera.

No se actualizan aquí a propósito: subir diez paquetes es un riesgo con su
propio PR y su propia verificación. El CI ya los cuenta.

---

## 2 · El marcador

| Regla | Antes | Hoy | Estado |
|---|---:|---:|---|
| Validación en `FormRequest` | 2 | **110** | 66 en línea quedan, 0 convertibles |
| Formato (Pint) | sin correr | **limpio** | en el CI |
| `env()` fuera de `config/` | 2 | **0** | hecho |
| Clases que no cargan | sin comprobar | **0 de 389** | comprobador nuevo en el CI |
| Análisis estático | no había | **PHPStan nivel 5** | línea base de 493; el CI exige código nuevo limpio |
| Superficie de la API | sin congelar | **325 rutas** | prueba que falla si cambia verbo, URI, nombre o middleware |
| Duración de la suite | 630 s | **48 s** | las pruebas ya no llaman a un Reverb real |
| Pruebas | 1026 | **1074** | |

---

## 3 · Lo que falta, por qué y cuánto cuesta

### 66 validaciones en línea, y ninguna «pendiente» sin más

> **10 de noviembre de 2026.** Esta tabla decía 99 repartidas en cuatro
> grupos, medidos a mano antes de las conversiones de hoy. Ya no suma, y el
> desglose lo hace ahora un detector:
>
> ```bash
> python scripts/validaciones_en_linea.py
> python scripts/validaciones_en_linea.py --convertibles
> ```

No son iguales y no se pueden tratar igual. **Medido, de las 66 que quedan:**

| Cuántas | Motivo | Qué significa |
|---:|---|---|
| **0** | **CONVERTIBLE** | nada se ejecuta antes de validar: se extrae y punto |
| 25 | SIN LLAMANTE | la ruta no la llama nadie. Extraer una regla de código pendiente de retirarse es invertir al revés |
| 11 | ORDEN DE LA RESPUESTA | hay una búsqueda o un `abort_if` antes. Un `FormRequest` valida primero, así que quien hoy recibe 404 recibiría 422 hablándole del cuerpo |
| 9 | DOS EN UN MÉTODO | valida dos veces según la rama; forzarlo cambia **cuál** 422 llega. §20 lo permite |
| 8 | AYUDANTE PARAMETRIZADO | las reglas salen de un `validar*($request, $parcial)` que comparten `store` y `update` |
| 4 | HAY CÓDIGO ANTES | algo se ejecuta antes y no se reconoce el patrón: hace falta que lo lea una persona |
| 3 | SOLO A VECES | la validación está dentro de un `if`: a veces no valida nada |
| 2 | EL CUERPO SE TOCA | un `merge()` arregla el cuerpo de una app vieja antes de validarlo |
| 2 | SALIDA TEMPRANA | hay un `return` antes, normalmente para una versión vieja de la app |
| 1 | REGLAS PRESTADAS | `validate($this->algo())` |
| 1 | FUERA DE UNA RUTA | está en un servicio |

**El bucket de convertibles está en cero**, y eso es un estado que significa
algo: no queda ninguna que se mueva sin tomar una decisión. Las tres últimas
se extrajeron hoy —`POST /v1/ayuda` y los dos controladores web de
recuperación de contraseña que trajo Breeze—, las tres con pruebas que ya
existían.

**«Convertible» quiere decir que no se ejecuta NADA antes de validar**, no
«no encontré ninguno de los estorbos que se me ocurrieron». La diferencia
costó tres correcciones al detector, y la última la encontró el propio
código: las tres validaciones de `MediosApiController` salían convertibles y
**no lo son** —el 404 lo lanza `nombreDeEntidad()`, un ayudante, no un
`abort_if` a la vista—, y el archivo ya lo explicaba en un comentario.
Buscando patrones conocidos eso no se caza nunca, porque cualquier llamada
puede abortar.

**Y el trinquete ya no es una promesa.** El acuerdo escrito era «todo endpoint
nuevo o tocado estrena su `FormRequest`; el contador solo baja; si sube, el PR
no entra», y nada lo impedía. Ahora el CI corre
`python3 scripts/validaciones_en_linea.py --trinquete`, que falla si:

- aparece una validación **CONVERTIBLE** —la regla, literal: una validación en
  línea con nada por delante es una que no tiene motivo para estar ahí—.
  Probado: escribiendo un endpoint nuevo a la antigua, el CI se pone rojo y
  dice qué hacer;
- el total pasa del tope escrito en el script. Ese número **solo baja**, y se
  baja a mano en el mismo cambio que convierte una: que haya que tocarlo es lo
  que hace que alguien se entere;
- **el propio detector no pudo medir.** Sin `route:list` todo cae en «fuera de
  una ruta», los convertibles son cero y la compuerta pasaría en verde sin
  haber comprobado una sola validación. Las dos autocomprobaciones están
  probadas rompiéndolas.

Los otros dos scripts que trajo el estándar siguen **fuera** del CI a
propósito: `check_file_sizes.py` señala 112 archivos y §03 llama a esos
umbrales «orientativos, no bloqueos»; `check_module_boundaries.py` mide una
estructura modular que este backend decidió no tener.

**El detector depende de `scripts/rutas-candidatas.json`** y lo dice si falta:
sin esa lista, los convertibles pasan de 0 a 22, porque las que están sobre
rutas que nadie llama parecen perfectamente convertibles. Son 22
extracciones, con sus pruebas, sobre código que la siguiente decisión de
producto puede borrar.

**Las 25 sobre rutas muertas.** El propio repositorio las documentó en
`Documentacion/SUPERFICIE-DE-API-SIN-USO.md`, al lado de este proyecto:
el CRUD completo de reseñas, `categories-business` y varias más. Escribir
pruebas para convertirlas es invertir en código pendiente de retirarse.
**Retirarlas o conservarlas es una decisión de producto, no técnica.**

### `JsonResource` — la carpeta no existe

Es la regla más grande que queda, y la más peligrosa. La forma de la respuesta
**es el contrato** que consumen la app publicada en las dos tiendas, tres
paneles y las versiones que no se pueden actualizar. Reescribirla a ciegas
rompe clientes.

Hoy la sostienen 1074 pruebas y el congelado de rutas. Hacerlo bien exige
endpoint por endpoint, con una prueba que fije la respuesta **antes** de
tocarla — el mismo método que con las validaciones, multiplicado por 325.

### Lo que hoy NO se puede cumplir

| Regla | Por qué |
|---|---|
| §24 índices con `EXPLAIN` · §25 presupuestos de rendimiento | **El propio §25 avisa de que SQLite no sirve para juzgar un plan de ejecución**, y la suite corre en SQLite en memoria. Cualquier medida mentiría. Hace falta un entorno con MySQL. Ya mordió una vez: `incomeBusiness` usaba `DAYOFWEEK()`, inexistente en SQLite, y esa pantalla no se pudo probar hasta portarla |
| §34 OpenAPI | Es construir y mantener un contrato nuevo: un proyecto, no un ajuste |
| §41 `declare(strict_types=1)` | 0 de 363 archivos. §12 dice que se adopta gradualmente y que **no** se reescriben todos para ponerlo |
| §51 límites de archivo | 113 por encima del objetivo. §03 los llama «umbrales orientativos, no bloqueos», y §00 pone el tamaño en el ÚLTIMO lugar de las prioridades |

---

## 4 · Sobre PHPStan, para que nadie persiga 503 avisos

Nivel 5 da **503 hallazgos**. De ellos, **440 son ruido**
(`property.notFound`, `larastan.relationExistence`) sobre modelos de Eloquent
sin anotar.

De los nueve de más señal —comparaciones siempre ciertas, métodos
inexistentes— se comprobaron a mano los principales y **los nueve eran
artefactos de inferencia**:

- *«`isPast()` sobre un string»* en `Business` — `accepting_until` **sí** está
  casteado a `datetime`; Larastan no lee los `casts()` en forma de método.
- *«`vigente()` no existe»* en `Banner` — **sí** existe, es un scope de
  `AdCampaign`; Larastan no lo resuelve a través de `whereHas`.
- *«comparar `'pqrs'` con `'pqrs'`»* — `tipoDesde()` devuelve claramente otros
  valores.

Por eso la línea base. Lo que aporta la herramienta hoy **no es encontrar
fallos**, es el trinquete: el código nuevo tiene que salir limpio. Anotar los
modelos con `@property` quitaría la mayoría del ruido y es trabajo de otro día.

---

## 5 · Cuatro cosas que aprendimos convirtiendo

No están en ningún documento del estándar y son lo que hay que saber para
seguir:

1. **Un extractor que copia texto copia también sus dependencias.** Las reglas
   nombraban clases cuyo `use` se quedó en el controlador.
2. **Un `validate()` puede llevar sufijo.** `…validate([...])['segment'] ?? []`
   devuelve el contenido, no el sobre.
3. **Lo que corre ANTES del `validate` deja de correr antes.** Es el criterio
   que más conversiones ha vetado: un `merge()` que traduce los nombres que
   manda la app publicada, un `return` temprano para compilaciones viejas, un
   ayudante que aborta con 404. Un `FormRequest` valida antes de entrar al
   método e **invierte esas respuestas**.
4. **Deshacer también se hace mal.** Un script que sustituye «el primer
   `validated()` del archivo» acierta solo si el archivo tiene un método
   convertido; con dos, intercambia las reglas y puede dejar un endpoint **sin
   ninguna validación**. Lo cazó la suite, no la revisión.

Y una quinta, de esta jornada: **`php -l` no basta.** Un `implements` a una
interfaz sin importar pasa el lint y hace la clase imposible de cargar. Por
eso existe `scripts/check_classes_load.php`.
