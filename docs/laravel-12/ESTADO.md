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

### Veinte avisos de seguridad en las dependencias — ARREGLADO

`composer audit` —que §46 pide y el CI no corría— devolvía **20 avisos en 10
paquetes**. `symfony/yaml` y `symfony/http-foundation` en alta, y
`phpoffice/phpspreadsheet` con dos altas: es lo que procesa **los Excel que
suben los tenderos**, o sea entrada de fuera.

**Cerrado: cero avisos**, en la rama `seguridad/dependencias-al-dia`. Diez
paquetes al día, 60 filas del lock, **ningún salto de versión mayor** y
`composer.json` sin tocar —las restricciones ya permitían estas versiones—,
así que revertirlo es revertir un archivo. El detalle de versión a versión
está en `EXCEPCIONES.md` §6.2.

---

## 2 · El marcador

| Regla | Antes | Hoy | Estado |
|---|---:|---:|---|
| Validación en `FormRequest` | 2 | **107** | 69 en línea quedan |
| Formato (Pint) | sin correr | **limpio** | en el CI |
| `env()` fuera de `config/` | 2 | **0** | hecho |
| Clases que no cargan | sin comprobar | **0 de 389** | comprobador nuevo en el CI |
| Análisis estático | no había | **PHPStan nivel 5** | línea base de 493; el CI exige código nuevo limpio |
| Superficie de la API | sin congelar | **325 rutas** | prueba que falla si cambia verbo, URI, nombre o middleware |
| Duración de la suite | 630 s | **48 s** | las pruebas ya no llaman a un Reverb real |
| Pruebas | 1026 | **1074** | |

---

## 3 · Lo que falta, por qué y cuánto cuesta

### 69 validaciones en línea

Medidas el 10 de noviembre de 2026: **69** `$request->validate(`, más 3
`Validator::make(` y 2 `validateWithBag(`, que son otra cosa.

El desglose en cuatro grupos que estaba acá —48 sin prueba, 25 sobre rutas
muertas, 16 no mecánicas, 10 en ayudantes— se midió **antes** de las
conversiones de hoy y ya no suma. No se reescribe a ojo: lo que vale es el
contador, y el detector dice caso por caso por qué cada uno sigue en línea.

Dos de las 69 ya están **decididas** y no son pendientes:
`DocumentosDelDomiciliarioController::subir()` y
`EfectivoDeLaTiendaController::recibir()` buscan antes de validar porque esa
búsqueda **es la autorización**. El motivo está escrito en `EXCEPCIONES.md`.

**Las que están sobre rutas muertas: ya hay con qué decidir.** El
repositorio las documentó en `Documentacion/SUPERFICIE-DE-API-SIN-USO.md`,
al lado de este proyecto: el CRUD completo de reseñas, `categories-business`
y varias más. Escribir pruebas para convertirlas es invertir en código
pendiente de retirarse — pero hasta hoy «pendiente de retirarse» era una
sospecha.

Ahora se mide. `php scripts/rutas_sin_uso.php` en el servidor cruza las 31
contra el registro de auditoría y dice, de cada una, cuántas llamadas hubo,
con qué códigos y cuándo fue la última. Las nueve GET —sobre las que el
registro antes no decía nada, porque filtraba por verbo— se registran desde
hoy. **Las 31 están cubiertas: ninguna de la que el informe no pueda
hablar.** Retirarlas o conservarlas sigue siendo decisión de producto, pero
ya es una decisión con números delante.

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
