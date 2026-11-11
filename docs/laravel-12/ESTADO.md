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
| Validación en `FormRequest` | 2 | **75** | 99 en línea quedan |
| Formato (Pint) | sin correr | **limpio** | en el CI |
| `env()` fuera de `config/` | 2 | **0** | hecho |
| Clases que no cargan | sin comprobar | **0 de 389** | comprobador nuevo en el CI |
| Análisis estático | no había | **PHPStan nivel 5** | línea base de 493; el CI exige código nuevo limpio |
| Superficie de la API | sin congelar | **325 rutas** | prueba que falla si cambia verbo, URI, nombre o middleware |
| Duración de la suite | 630 s | **48 s** | las pruebas ya no llaman a un Reverb real |
| Pruebas | 1026 | **1074** | |

---

## 3 · Lo que falta, por qué y cuánto cuesta

### 99 validaciones en línea

No son iguales y no se pueden tratar igual:

| Cuántas | Dónde | Qué hace falta |
|---:|---|---|
| **48** | endpoints vivos sin ninguna prueba | escribir primero la prueba que fija la respuesta de hoy, después extraer |
| **25** | endpoints que **ningún cliente llama** | nada: ver abajo |
| **16** | con prueba, pero no mecánicas | una por una; el detector dice por qué |
| **10** | en ayudantes privados parametrizados | decidir si `rules()` mira el verbo |

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
| §24 índices con `EXPLAIN` · §25 presupuestos de rendimiento | **El propio §25 avisa de que SQLite no sirve para juzgar un plan de ejecución**, y la suite corre en SQLite en memoria. Cualquier medida mentiría. Hace falta un entorno con MySQL. Ya mordió una vez: `incomeBusiness` usaba `DAYOFWEEK()`, inexistente en SQLite, y esa pantalla no se pudo probar hasta portarla. **Lo de las consultas por petición, que es la otra mitad de §25, sí se podía: ver abajo** |
| §34 OpenAPI | Es construir y mantener un contrato nuevo: un proyecto, no un ajuste |
| §41 `declare(strict_types=1)` | 0 de 363 archivos. §12 dice que se adopta gradualmente y que **no** se reescriben todos para ponerlo |
| §51 límites de archivo | 113 por encima del objetivo. §03 los llama «umbrales orientativos, no bloqueos», y §00 pone el tamaño en el ÚLTIMO lugar de las prioridades |

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
