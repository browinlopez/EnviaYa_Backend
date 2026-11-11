# Estado del backend frente al estándar

**Medido el 11 de noviembre de 2026.** Laravel 12.72 · 1234 pruebas en verde ·
PHPStan y Pint limpios · 428 clases cargan · `composer audit` en cero.

Doce ramas de trabajo fusionadas, y el backend **arrancado y recorrido entero**:
33 procesos, 1223 peticiones contra MySQL de verdad y la pasarela de verdad.
Los tres hallazgos de ese recorrido están arreglados, con su prueba cada uno.
El recorrido se repite con `python scripts/arnes/correr.py`.

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

### Lo que encontró ARRANCARLO, que ninguna prueba veía — ARREGLADO

Las tres salieron de levantar el backend y recorrer los 33 procesos contra
MySQL de verdad y la pasarela de verdad. **Ninguna es un fallo de lógica**, y
por eso 1176 pruebas en verde no veían ni una: son respuestas equivocadas y
una ausencia.

**Bold no cobraba nada.** `BoldService::peticion()` se llamaba a sí misma
—recursión infinita, `Allowed memory size exhausted`, un error FATAL que ni el
`try/catch` de los controladores atrapa—. Las siete llamadas mataban el
proceso: tarjeta, PSE y QR, imposibles. Lo dejó así el commit que puso los
topes de tiempo, sustituyendo a ciegas los siete
`Http::withHeaders($this->headers())` **incluido el de dentro del método**. No
llegó a producción porque estaba entre los commits sin subir. Lo encontró el
CONTROL de un detector: el guardia de llamadas HTTP sin tope exige encontrar
lo que ya se sabe que está, devolvió cinco llamadas y ninguna de Bold —que
tiene siete—, porque en el fichero ya no quedaba un solo `Http::`. **La
ausencia era el síntoma.**

**El cliente de R2 esperaba para siempre.** El SDK de AWS arranca en modo
`legacy` y ahí no pone ningún tope: el disco `r2` se resolvía sin una sola
opción de `http`, con los valores de Guzzle —esperar indefinidamente—. Y las
subidas ocurren dentro de la petición, cuando el tendero sube su logo. Medido:
63,6 s contra una dirección no enrutable, y **sin fin** contra un servidor que
acepta y se calla. Ese contraejemplo no se puede escribir como prueba, y eso
mismo mide la gravedad.

**Pedir el enlace de contraseña decía qué cuentas existen.** 200 con un correo
registrado, 500 con uno que no: la enumeración de cuentas de §35, preguntable
en bucle. Y `RESET_THROTTLED` caía en la misma rama, así que el usuario que
pulsaba dos veces recibía un 500 estando todo bien.

**Consultar un pago inexistente devolvía 500 con el cuerpo de Bold dentro.** Un
500 le dice al teléfono «reintenta» y esa pantalla sondea cada pocos segundos;
y el código interno de la pasarela acababa en la pantalla del comprador.

**Noventa y cuatro trabajos llevaban dos meses en la cola**, con cero intentos.
Se drenaron y pasaron los noventa y cuatro sin un fallo: el código estaba bien,
lo que no existía era manera de enterarse. Encolar siempre funciona, así que la
petición acaba en 201; lo que deja de pasar es lo de después, en tres pantallas
y sin un solo error. Ahora lo vigila `colas:comprobar --avisar`, a diario.

> **La lección, que vale más que las cinco juntas:** una suite comprueba lo
> que alguien se acordó de comprobar. Arrancar el sistema y recorrerlo
> comprueba lo que contesta. Son dos preguntas distintas y hacen falta las
> dos — `scripts/arnes/` es la segunda, y sale en 0 solo si los 33 procesos
> pasan **y** no hay hallazgos.

---

## 2 · El marcador

| Regla | Antes | Hoy | Estado |
|---|---:|---:|---|
| Validación en `FormRequest` | 2 | **110** | 66 en línea quedan, 0 convertibles |
| Formato (Pint) | sin correr | **limpio** | en el CI |
| `env()` fuera de `config/` | 2 | **0** | hecho |
| Clases que no cargan | sin comprobar | **0 de 428** | comprobador nuevo en el CI |
| Análisis estático | no había | **PHPStan nivel 5** | línea base de 385; el CI exige código nuevo limpio |
| Superficie de la API | sin congelar | **294 rutas** | prueba que falla si cambia verbo, URI, nombre o middleware. Eran 325: las 31 sin llamante se retiraron el 11 de noviembre |
| Duración de la suite | 630 s | **48 s** | las pruebas ya no llaman a un Reverb real |
| Pruebas | 1026 | **1234** | |
| Procesos recorridos de punta a punta | 0 | **33** | `scripts/arnes/`, contra el servidor en marcha |
| Avisos de `composer audit` | 20 | **0** | bloqueante en el CI |

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

#### Las 25 que están sobre rutas que nadie llama

Dos de las 66 además están **decididas** y no son pendientes:
`DocumentosDelDomiciliarioController::subir()` y
`EfectivoDeLaTiendaController::recibir()` buscan antes de validar porque esa
búsqueda **es la autorización**. El motivo está en `EXCEPCIONES.md`.

> **10 de noviembre de 2026 — y «muertas» ya no es una sospecha.** El motivo
> por el que no se podía decidir era «la app publicada es un binario que no
> podemos leer», y eso miraba al sitio equivocado: **el código fuente de ese
> binario está en el repositorio**, en el commit que se publicó.
>
> `python scripts/rutas_sin_llamante.py` busca cada ruta en el código de cada
> cliente —la app en los commits de 1.0.0 a 1.0.12, los tres paneles, el
> propio backend— y después recorre los 202 commits de toda la historia de la
> app con `git log -S`. Resultado: **31 de 31 sin un solo llamante, y 31 de 31
> que nunca aparecieron en esa historia.** No son restos de la capa de datos
> vieja, como se suponía: la app nunca las llamó.
>
> Se le cree porque se comprueba a sí mismo: antes de contestar busca tres
> rutas que **sí** se llaman y, si no las encuentra, sale con código 2 en vez
> de dar las 31 por muertas. Probado apuntándolo a una raíz que no existe.
>
> Para lo que el código no puede ver —una integración de un aliado, un script
> interno— queda `php scripts/rutas_sin_uso.php`, que las cruza contra el
> registro de auditoría. Las nueve GET, sobre las que ese registro no decía
> nada porque filtraba por verbo, se registran desde hoy. Y el informe enseña
> la IP, que es lo que dejó ver que las seis «llamadas reales» de la máquina
> de desarrollo eran pruebas a mano desde `127.0.0.1`.
>
> Sigue siendo decisión de producto. Pero ya no es a ciegas, y el argumento
> para conservar una tiene que nombrar a un llamante concreto.

> **11 de noviembre de 2026 — retiradas las 31.** `/v1` pasó de **325 a 294
> rutas**. Nadie nombró un llamante, que era la condición.
>
> Con ellas se fueron los 31 métodos que las atendían —comprobado antes de
> tocar nada que ninguno servía a otra ruta viva—, dos controladores que se
> quedaron sin un solo método, el grupo `admin` de `categories-business` que
> quedó vacío, y `AuditApiRequest::WATCHED_READS`, que era instrumentación
> para esta misma decisión y caducó con ella.
>
> **Un falso sobresalto que conviene recordar**, porque es la trampa de
> `route:list`: el primer informe decía que nueve de las 31 compartían su
> método con otra ruta, lo que habría obligado a dejar nueve métodos
> huérfanos. Eran las nueve GET, contadas dos veces porque `route:list`
> devuelve `GET|HEAD`. Sin HEAD, los 31 métodos servían solo a rutas muertas.
>
> **Y una que sí tenía algo detrás:** `POST /v1/chats/create` parecía la única
> puerta de entrada al chat. No lo era —`send-message` crea la conversación si
> no existe, con bloqueo de filas para que dos mensajes simultáneos no abran
> dos—, y eso explica por qué ningún cliente la llamaba. No estaba fijado por
> ninguna prueba: el día que alguien «simplificara» `send-message` exigiendo
> un `chat_id`, el chat se quedaba sin forma de empezar y nada se ponía rojo.
> Ahora hay prueba, y probada rompiéndola.
>
> El congelado de superficie se regeneró con `CONGELAR_SUPERFICIE=1`: 313
> líneas menos, **31 URIs fuera y ninguna añadida ni cambiada**. La línea base
> de PHPStan encogió de 256 a 235 entradas, también sin un patrón nuevo —las
> 42 que protestaban apuntaban a código borrado—. El arnés llamaba a cuatro de
> las retiradas y se corrigió; sus 33 procesos siguen con salida 0.
>
> De paso: los `__pycache__` del arnés estaban versionados por descuido y
> cambiaban en cada corrida, así que ensuciaban el estado del repositorio con
> binarios regenerables. Fuera del índice y en `.gitignore`.

### `JsonResource` — la carpeta no existe

Es la regla más grande que queda, y la más peligrosa. La forma de la respuesta
**es el contrato** que consumen la app publicada en las dos tiendas, tres
paneles y las versiones que no se pueden actualizar. Reescribirla a ciegas
rompe clientes.

Hoy la sostienen 1234 pruebas, el congelado de rutas y los 33 procesos del arnés. Hacerlo bien exige
endpoint por endpoint, con una prueba que fije la respuesta **antes** de
tocarla — el mismo método que con las validaciones, multiplicado por 325.

### Lo que hoy NO se puede cumplir

| Regla | Por qué |
|---|---|
| ~~§24 índices con `EXPLAIN`~~ | **Hecho el 10 de noviembre de 2026.** Ver «Los índices, medidos» más abajo |
| §25 presupuestos de rendimiento | **Ya se mide**: `MedidaDeLaPeticion` escribe una línea por petición y `scripts/percentiles_de_latencia.php` las resume. Lo que falta es TRÁFICO: con 1223 peticiones de un recorrido salen p50 61 ms, p95 212 ms y p99 370 ms en conjunto, pero casi ninguna ruta llega a las 20 muestras que hacen falta para un percentil honesto. Poner el número por endpoint pide unos días de uso real, no otra herramienta |
| §34 OpenAPI | Es construir y mantener un contrato nuevo: un proyecto, no un ajuste |
| §41 `declare(strict_types=1)` | 0 de 430 archivos. §12 dice que se adopta gradualmente y que **no** se reescriben todos para ponerlo |
| §51 límites de archivo | 113 por encima del objetivo. §03 los llama «umbrales orientativos, no bloqueos», y §00 pone el tamaño en el ÚLTIMO lugar de las prioridades |

### Los índices, medidos — §24

Esta regla era «imposible, no difícil»: la suite corre en SQLite y §25 avisa
de que SQLite no sirve para juzgar un plan. Lo que la desbloqueó fue el
workflow `pruebas-mysql.yml` —que corre **toda** la suite contra MySQL 8— más
el MySQL 8.0.42 que ya está instalado en la máquina de desarrollo. No hacía
falta el servidor.

**Dos columnas del camino caliente no tenían ningún índice:**

| Dónde | Quién la usa | `EXPLAIN` antes |
|---|---|---|
| `orderssales.sale_date` | `GeneradorDeReportes::pedidosDelPeriodo()`, y de ahí los informes de ventas, operacional y de domiciliarios | `type=ALL`, `possible_keys` **vacío** |
| `payments.provider_payment_id` | `BoldWebhookController::resolvePayment()`, el primer paso de cada aviso de la pasarela | `type=ALL`, `possible_keys` **vacío** |

`possible_keys` vacío es lo que importa: no es que el optimizador prefiriera
el escaneo, es que **no existía ningún índice capaz de servir esa consulta**.
Eso es cierto a cualquier volumen.

Tres índices nuevos, en dos migraciones reversibles —comprobado: puestas,
revertidas y vueltas a poner—. Después, con una ventana de una semana, cada
consulta **examina exactamente las filas que devuelve** (49 de 49), y el
webhook pasa de recorrer 116 filas a encontrar 1.

**El error que costó la primera medición**, porque es el aviso de §24 en carne
propia: se midió primero con un rango de un mes, y octubre tiene 277 de los
549 pedidos de la base de desarrollo — media tabla. Con eso el escaneo
completo es el plan CORRECTO y ningún índice se usa, así que la medición no
hablaba de los índices: hablaba de que la consulta de prueba estaba mal
elegida. «Datos representativos de tamaño **y distribución**», dice §24.

**Y queda vigilado.** `tests/Feature/Rendimiento/PlanDeLasConsultasCalientesTest.php`
comprueba `possible_keys` —no `key`— sobre cinco consultas. La diferencia es
de diseño: `key` es lo que el optimizador eligió y depende del volumen, así
que en una base de pruebas casi vacía fallaría sin que nada estuviera mal;
`possible_keys` responde «¿existe un índice que pueda servir esto?», que es
verdad a cualquier tamaño. Comprobado que MySQL lo rellena incluso sobre
tablas con cero filas, que es como están en CI.

Caza las dos regresiones invisibles: un `dropIndex` de más, y envolver la
columna en una función. La segunda tiene su propia prueba, porque el consejo
de §24 §3 merecía comprobarse y no citarse: `date(sale_date) = ?` deja
`possible_keys` vacío, y la misma pregunta escrita como rango usa el índice.

En local se salta con el motivo escrito; corre en el flujo de MySQL. Que ese
flujo de verdad use MySQL también está comprobado: `phpunit.xml` fija
`DB_CONNECTION=sqlite`, pero un `<env>` de PHPUnit **no** pisa una variable
que ya viene del entorno — medido, no supuesto, porque si lo pisara el flujo
entero llevaría meses corriendo en SQLite sin que nadie lo notara.

### El presupuesto de tiempo en base de datos — §25

**Aquí no había NINGUNA observabilidad de consultas.** Pulse, Telescope y
Nightwatch no están instalados —ni aparecen en `composer.json`—, así que de
un endpoint lento en producción no quedaba ni rastro: lo único que se sabía
era lo que alguien notara a ojo.

`App\Support\PresupuestoDeConsultas` registra el
`DB::whenQueryingForLongerThan` que §25 propone: cuando una petición pasa de
**400 ms de suma de consultas**, queda anotada en el canal `rendimiento`
(propio, 14 días). El número está en `config/database.php` y se apaga con
`DB_PRESUPUESTO_MS=0`, sin desplegar.

**No es un detector de consulta lenta**, y §25 pide no confundirlos: veinte
consultas de 30 ms no disparan ninguna alarma de consulta lenta y son 600 ms
de espera para el tendero. Es justo lo que dejan los N+1.

**Lo que se escribe, y lo que no.** Va el patrón de la ruta
—`v1/negocio/domiciliarios/{id}`, con el marcador y no el número—, el verbo y
dos tiempos. **No van la SQL ni los parámetros.** §25 pide enmascarar los
parámetros sensibles, y la forma segura de enmascararlos es no escribirlos:
en esta API un `binding` es el correo de un tendero, el teléfono de un
comprador o el token de una sesión, en un fichero que nadie vigila. Hay una
prueba de ausencia para eso, y es la que importa: probada rota, escribir la
SQL la pone roja.

**Lo que esto NO puede decir, dicho antes de que alguien lea el log
esperándolo:** cuánto tardó la petición en total. El manejador salta en el
instante en que la suma cruza el presupuesto, no al final, así que el
acumulado en ese momento vale ≈ el presupuesto siempre. Por eso el campo se
llama `ms_al_cruzar` y no «total»: un número que siempre dice 400 no informa
de nada. Contesta **qué rutas se pasan y cuántas veces**, que es con lo que se
decide dónde mirar; para el «cuánto» haría falta medir al terminar la
petición, y eso es otra pieza que no se añade sin que nadie la necesite.

El segundo tiempo, `cruzo_con_ms`, es el que separa los dos casos: si se
parece al presupuesto, fue **una** consulta lenta y falta un índice; si es
mucho menor, fue la suma de muchas rápidas, o sea un N+1.

**Apagado en las pruebas** (`DB_PRESUPUESTO_MS=0` en `phpunit.xml`), y no por
ruido: «por petición» no significa nada en un proceso de pruebas. El contador
no se reinicia entre una prueba y la siguiente —eso lo hace Octane, y en la
suite no hay Octane—, así que la suma crece durante todo el archivo y el aviso
acabaría saltando por el montaje de la prueba número cuarenta, apuntando a una
ruta que no tiene nada que ver. Medido: una sola prueba llega a 248 ms de
acumulado. Las cinco pruebas que lo ejercitan lo encienden ellas, con su
presupuesto y reiniciando el contador antes.

Y queda comprobado que bajo Octane funciona por petición y no una vez por
trabajador: `Octane\Listeners\RefreshQueryDurationHandling` reinicia el
contador **y rearma el manejador** en cada petición. Sin eso el aviso se
dispararía una vez al arrancar el trabajador y nunca más, que es peor que no
tenerlo.

### Las consultas por petición — la mitad de §25 que no necesitaba MySQL

§25 pide, con esas palabras, «mantener tests que detecten un incremento
anormal del número de consultas cuando se aumenta el número de registros
retornados». Eso **no depende del motor**: el conteo de consultas es idéntico
en SQLite y en MySQL. Estaba en la lista de bloqueados por confundirlo con los
presupuestos de latencia, que sí lo están.

**Lo que había debajo: tres N+1 en el mismo endpoint.**
`GET /orders/business` —el tablero del tendero— precargaba su propia lista de
relaciones, copiada del otro controlador que usa el mismo presentador. Las dos
listas se habían separado:

| Lo que faltaba | Qué costaba |
|---|---|
| `domiciliary.user` | 2 consultas por pedido con repartidor |
| `details.product.category` | 1 consulta **por línea de pedido** |
| `address.department`, `address.country` | 2 por pedido con dirección |

Ninguna daba error: la respuesta era correcta. A cincuenta pedidos en el
tablero, con tres líneas cada uno, son más de trescientas consultas que nadie
veía.

La lista se movió a `PedidoParaLaApp::relaciones()`, que es quien las lee, y
los dos controladores la usan. Dos copias de la misma lista es tenerla mal en
una de las dos.

**Cómo salieron.** `Model::preventLazyLoading(! app()->isProduction())`, que
§25 propone y que estaba sin poner. Se midió antes de encenderlo, porque §25
avisa de revisar la compatibilidad con el código heredado y en un proyecto con
años encima esto puede romper media suite: de 1127 pruebas fallaron **dos**, y
las dos señalaban el primer N+1. Los otros dos los encontró la prueba nueva.
Apagado en producción, que es lo que dice la regla: una carga perezosa que se
cuele no le tira la pantalla a nadie, solo cuesta consultas. Donde tiene que
doler es en el CI.

**La prueba mide la pendiente, no el número.** No fija un tope de consultas:
ese número cambia legítimamente en cuanto alguien añade un campo, y una prueba
que se rompe cada vez que el código cambia bien se acaba borrando. Lo que no
puede cambiar es que sea **el mismo** con un pedido y con ocho. Hoy: 15 y 15.

Tres detalles del método, que es donde estaba la dificultad:

- **Cada pedido lleva su propio comprador, repartidor, producto y dirección.**
  Compartiéndolos, Eloquent resolvería la relación una vez y el N+1 no
  aparecería ni estando el defecto: la prueba se vería verde sobre el código
  roto.
- **Se mide la segunda petición, no la primera.** La primera del proceso paga
  además el catálogo de ajustes (`platform_settings`), que luego se cachea.
  Medido: 16 la primera y 15 la segunda, con los mismos datos. Sin el
  calentamiento la prueba falla por algo que no tiene que ver con los pedidos.
- **Hay una prueba de contraparte**, porque un endpoint que devolviera una
  lista vacía también costaría lo mismo con uno y con ocho, y pasaría en
  verde.

Probada rota: quitando cualquiera de las tres precargas, se pone roja.

**Un defecto latente que apareció de paso y NO se toca.** `UserAddress::country()`
es un `hasOneThrough` cuya clave local es `department_id`, y `user_address` no
tiene esa columna: el país de una dirección es **siempre null**. Arreglarlo
cambiaría un valor que la app publicada recibe como nulo desde siempre, así
que queda dicho y no hecho.

#### Y se buscó si quedaban más, en vez de darlo por hecho

Barrido de todos los controladores que recorren una colección —la forma que
tiene un N+1—, cruzado con si precargan algo. **No queda ninguno que recorra
modelos de Eloquent sin precargar:** los demás usan consultas crudas
(`DB::table`), donde no hay relación que cargar.

La vitrina —`GET /businesses/index`, la lectura de más tráfico de la
plataforma, la que hace cada comprador al abrir la app— salió señalada por el
barrido y **era un falso positivo mío**: el patrón buscaba `->with(` y ahí
está escrito `Business::with([...])`, con la sintaxis estática. Precargaba
bien desde siempre.

Se midió igual, y se le dejó prueba: **5 consultas con una tienda y 5 con
veinte**, en sus dos formas. Una prueba que hoy no encuentra nada no es una
prueba de más — añadir un campo que venga de una relación sin añadirla al
`with` es un cambio de una línea, y es exactamente lo que había pasado en el
tablero. Probada rota: quitando `municipality` del `with`, se pone roja.

De paso salió que el parámetro `light` costaba **las mismas 5 consultas** que
la respuesta completa, y ahí se quedó anotado que el remedio era hacer
condicional el `with` y no solo la salida.

**Hecho.** `index()` precargaba `reviews` y `products` siempre, así que con
`light=1` —que es lo que pide la app al arrancar— el servidor cargaba todos
los productos activos y todas las reseñas de cada tienda del catálogo, los
recorría, y los tiraba al serializar, porque la respuesta ligera ya devuelve
`[]` en los dos campos. Medido con seis tiendas: **5 consultas antes, 3
después**; la completa sigue en 5.

Lo que cuesta no son las dos consultas —son dos, no dos por tienda— sino lo
que traen: con cien tiendas de cien productos, `light` hidrataba diez mil
modelos de Eloquent para no enseñar ninguno. Y es la llamada de más tráfico
de la plataforma.

**`indexByQualification()` ya lo hacía así**, y ahí estaba la pista: el mismo
parámetro ahorraba trabajo en un endpoint y no en el otro.

La prueba **no fija el número**, fija la desigualdad: 3 puede subir a 4 el día
que la vitrina necesite otra relación, y eso está bien; lo que no puede volver
es que pedir menos cueste lo mismo. Con su contraparte, que comprueba que el
modo ligero sigue devolviendo `[]` y el completo sigue trayendo contenido —
ahorrar dejando de cargar algo solo vale si eso no se estaba enseñando.
Probada rota: devolviendo el `with` a incondicional, se pone roja.

#### El tablero del panel: 48 consultas que NO eran un N+1

`GET /admin/overview` es, de largo, la ruta que más consultas hace de todo el
backend, y por eso se miró con la sonda: **48**. El resultado útil es el
negativo. Cuarenta de esas 48 salen **una sola vez cada una** y son cuarenta
agregados distintos —una pantalla que enseña cuarenta cifras cuesta cuarenta
cifras—, así que no hay nada que precargar: no es un N+1 y reducir el número
pedía reducir la pantalla, que no es una decisión técnica.

Lo que sí había eran **cuatro consultas repetidas**, y de dos clases
distintas:

| Repetida | Por qué |
|---|---|
| `count(*) business`, `count(*) products`, los pedidos activos sin pago aprobado | la misma cifra con dos nombres: arriba `businesses` / `products` / `pending_payment`, abajo `businesses_total` / `products_total` / `unpaid_orders` |
| la fila de `areas` del usuario | el middleware de permisos y `ResumenDelPanel` la buscaban cada uno por su cuenta |

Las tres primeras se cuentan ahora en `ResumenDelPanel` y los dos lados leen
el conteo; **comprobado antes de tocar nada que los dos nombres daban el mismo
número** (7, 586 y 22), que es lo que autoriza a compartirlo. La cuarta se
resuelve con una relación en el modelo (`User::area()`), porque Eloquent
guarda la relación cargada en la instancia y `$request->user()` devuelve la
misma instancia a todo el que pregunte durante la petición.

**48 → 44, y la respuesta idéntica byte a byte** —18 686 bytes antes y
después—, medido con un guion hermano de la sonda que guarda el cuerpo. Eso es
lo que exige el encargo: cumplir el estándar sin tocar lo que las apps
reciben.

Dos cosas que el cambio obligó a cuidar, y las dos valen como aviso general:

- **`area` entró en el `$hidden` del usuario, y no por secreto.** Eloquent
  serializa las relaciones que estén cargadas, así que el día que alguien
  ponga `modulo:` sobre una ruta que devuelva el usuario entero, le aparecería
  una clave nueva en el JSON sin haberla pedido. Hoy ninguna ruta hace las dos
  cosas —comprobado—, pero una promesa de no cambiar la API se sostiene mejor
  con una línea que con una comprobación de hoy.
- **La memoria de conteos vive en la instancia del servicio**, y eso solo es
  seguro mientras el contenedor no la comparta. Hay prueba de que no la
  comparte: registrado como `singleton` —probado— el panel enseñaría cifras
  congeladas de otra petición y el síntoma sería «creé un negocio y el tablero
  sigue diciendo 7», sin error en ningún log.

**Y un fallo de la propia prueba que conviene recordar**, porque es fácil de
repetir: contar los duplicados con
`str_contains('count(*) as aggregate from "business"')` daba **cinco**, porque
esa cadena es el prefijo de otras cuatro consultas del mismo bloque —las
ocultas, las sin ubicación, las sin tipo, las sin propietario—. Un filtro por
prefijo en una comprobación de duplicados cuenta de más, y la prueba se habría
creído sola.

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
