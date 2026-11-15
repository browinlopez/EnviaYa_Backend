# Excepciones y decisiones del backend frente al estándar

> Registro del repositorio `EnviaYa_Backend` (§20 del estándar).
> **Medido el 15 de noviembre de 2026** sobre Laravel 12.72, 113 controladores,
> 52 servicios, 70 modelos, 10 objetos de consulta y 1394 pruebas.
> *(El 9 de noviembre: 12.66, 115 controladores, 54 servicios, 70 modelos y 1022
> pruebas. Esta cabecera se quedó seis días diciendo las de entonces, que es
> justo lo que el registro existe para que no pase.)*
>
> Cada fila dice qué regla no se cumple, **por qué**, qué riesgo se acepta y
> cuándo se revisa. Una excepción nueva entra aquí por PR.

Esto existe porque un estándar que declara el 91 % del código en falta el
primer día deja de leerse a la segunda semana. Lo que vale no es el porcentaje
de cumplimiento hoy, es que **la cifra solo pueda bajar**.

---

## 1 · Excepciones a reglas obligatorias

### La estructura no es `app/Modules/`, y se decidió que no lo sea

| | |
|---|---|
| **Regla** | `AGENTS.md` §3 y `02-estructura-de-carpetas.md`: las clases de negocio nuevas van en `app/Modules/<Modulo>/…` |
| **Realidad** | Estructura por capas con subcarpeta de dominio: `app/Http/Controllers/Negocio/`, `app/Services/`, `app/Models/Order/`. 113 controladores en ~20 carpetas de dominio |
| **Motivo** | **Se midió antes de decidir: 70 de los 115 controladores (60 %) usan modelos de otro dominio.** `User`, `Business`, `Domiciliary` y `OrdersSales` los usa casi todo. Con ese acoplamiento, `app/Modules/` no produce módulos: produce un `app/Shared/Models/` con la mayoría de los modelos dentro y unos envoltorios delgados encima — el cajón que §00 llama anti-patrón, pagando antes un diff de ~240 archivos |
| **Decisión** | **9 de noviembre de 2026: no se crea `app/Modules/`.** Se cumplen dentro de la estructura actual las reglas que sí muerden —`FormRequest`, una responsabilidad por archivo, límites de línea—, que es lo que §02 («si una estructura existente ya es consistente, adapta progresivamente») y §20.1 («no big-bang») permiten expresamente |
| **Riesgo aceptado** | La carpeta por tipo técnico sigue siendo el anti-patrón de §00. Se mitiga porque el segundo nivel **sí** es de dominio |
| **Revisión** | Si algún día un área deja de cruzar —hoy las más limpias son Conjunto, Chat y Marketing— puede nacer como módulo. No antes: dos estructuras conviviendo es peor que cualquiera de las dos |

### Validación en línea, no en `FormRequest`

| | |
|---|---|
| **Regla** | `04-capas-http.md`: validar con `FormRequest`, trabajar con `validated()` |
| **Realidad** | Eran **173 llamadas a `->validate(`** contra **2** `FormRequest`. Al **14 de noviembre de 2026**, medido: **39 `$request->validate(` y 126 `FormRequest`** (más 3 `Validator::make(` y 2 `validateWithBag(`, que son otra cosa: salida con bolsa y validación armada a mano). El 10 de noviembre eran 69 y 107: el trinquete aprieta solo, porque cada endpoint tocado estrena el suyo |
| **Motivo** | Convertir 173 validaciones de una vez es exactamente el PR gigante que el estándar desaconseja, y cada una es una regla de negocio que hay que releer, no mover |
| **Riesgo aceptado** | La regla vive pegada al flujo: no se reusa, no se prueba aparte y la misma entidad se valida distinto en dos endpoints |
| **Alternativa adoptada** | **Trinquete**: todo endpoint nuevo o tocado estrena su `FormRequest`. El contador de `->validate(` es la métrica y solo baja |
| **Cómo se convierte lo viejo** | **Prueba primero.** 102 de las 325 rutas (31 %) no las llama ninguna prueba. En esas, extraer un `FormRequest` sin red significa que una regla que pase de `required` a `nullable` por descuido la descubre un tendero, no la suite. Así que por cada ruta sin cobertura que se toque: primero una prueba que fija su respuesta de hoy, después la extracción |
| **Revisión** | Cada PR. Si sube, el PR no entra |

**Una validación que NO se convierte, y por qué.**
`AltaDeDomiciliarioController::guardar()` valida **dos veces en el mismo
método**, según la rama: vincular a un domiciliario que ya existe, o crear uno
nuevo. Un `FormRequest` valida una sola vez, antes de entrar al controlador, y
forzarlo con reglas condicionales cambia **cuál** 422 recibe el tendero. §20 lo
permite expresamente —«excepción permitida cuando separar empeora lectura,
coste o testabilidad»—. Se queda en línea.

#### Las dos que buscan ANTES de validar: decisión, no deuda

Un detector sobre `app/Http/Controllers` busca métodos donde un
`findOrFail` / `firstOrFail` / `sole` corre antes de la validación. Al 10 de
noviembre de 2026 quedan **dos**, y las dos son el mismo caso:

| Dónde | Qué busca primero |
|---|---|
| `Negocio/DocumentosDelDomiciliarioController::subir()` | el domiciliario, acotado a la tienda de la sesión |
| `Negocio/EfectivoDeLaTiendaController::recibir()` | el mismo vínculo, para recibir efectivo en el mostrador |

```php
// El vínculo con ESTA tienda es la autorización.
$d = Domiciliary::whereHas('businesses', fn ($q) => $q
        ->where('business.busines_id', $negocioId)
        ->where('business_domiciliary.state', 1))
    ->findOrFail($id);

$datos = $request->validate([...]);
```

**Ese `findOrFail` no es una consulta: es la autorización.** Resuelve al
domiciliario *acotado a la tienda que sale de la sesión*, y si no hay vínculo
devuelve 404. Sin él, cualquier tendero podría escribir en los papeles —o
apuntar entregas de efectivo— de alguien que no reparte para él.

Moverlo a un `FormRequest` invierte el orden: la validación correría primero
y un tendero probando ids ajenos recibiría un **422 hablándole de la forma
del cuerpo** sobre un recurso que no tiene derecho a ver, en vez de un 404.
Hacerlo en `authorize()` del propio `FormRequest` arregla el orden pero
obliga a repetir la consulta o a dejar el modelo pegado al `Request`, que es
peor de leer que las cinco líneas de arriba.

§20 cubre esto —«excepción permitida cuando separar empeora lectura, coste o
testabilidad»—. **Se quedan, y el contador no las cuenta como pendientes.**
Si aparece una tercera, que aparezca con su motivo escrito acá.

### La salida se arma a mano, no con `JsonResource`

| | |
|---|---|
| **Regla** | `04-capas-http.md` y `09-contratos-api-y-errores.md`: `JsonResource` para la salida |
| **Realidad** | **Medido el 14 de noviembre de 2026: `app/Http/Resources/` tiene 56 clases.** Esta fila decia «no existe», que era verdad cuando se escribio y dejo de serlo sin que nadie la actualizara. `scripts/formas_de_entidad.py` mide **0 formas de entidad por migrar** en `app/Http`: lo que se arma a mano a dia de hoy son informes de agregados, donde un Resource seria la ceremonia que §05 prohibe |
| **Motivo** | Mismo argumento que arriba, con un agravante: la forma de la respuesta **es el contrato** que consumen la app, tres paneles y las versiones ya publicadas en las tiendas. Reescribirla a ciegas rompe clientes que no se pueden actualizar |
| **Riesgo aceptado** | No hay un sitio único donde ver qué campos expone un endpoint; esconder un campo nuevo es fácil de olvidar |
| **Alternativa adoptada** | Mismo trinquete. Y las pruebas del contrato público siguen siendo la red: **1394 pruebas**, varias de ellas **de ausencia** (que un campo NO viaje) |
| **Revisión** | Cada PR |

### Los archivos son más grandes que los objetivos

| | |
|---|---|
| **Regla** | `03-limites-de-lineas.md`: controlador 15–35 líneas, revisar por encima de 50 |
| **Realidad** | **Medido el 14 de noviembre de 2026:** mediana **138**; **96 de 113** controladores pasan de 50. El mayor es `Order/OrderController.php` con **935**. Ha ido 994 -> 987 -> 935 sin que fuera el objetivo de ningun cambio: baja porque las consultas se fueron a `Queries/` y la autorizacion a `Policies/`, que es como §03 dice que tiene que bajar --«revisar cohesion», no partir metodos por un numero-- |
| **Motivo** | El propio §03 dice que son «umbrales orientativos, no bloqueos automáticos», y §00 pone el tamaño en el **último** lugar de las prioridades |
| **Riesgo aceptado** | Bajo en general —solo 3 controladores pasan de 7 métodos públicos, o sea que no son cajones de sastre— y alto en los tres grandes |
| **Revisión** | `OrderController` (935 líneas) cuando se toque el flujo de pedidos; no antes y no como PR de limpieza suelto |

---

## 2 · Decisiones: reglas que aquí **no** son deuda

### Autorización por middleware, no por Policy — YA NO ES UNA EXCEPCIÓN

> **14 de noviembre de 2026: cerrada.** Las cuatro reglas de autorización por
> registro --que vivían en métodos privados de cuatro controladores-- están en
> `app/Policies/DomiciliaryPolicy` y `app/Policies/OrdersSalesPolicy`, con las
> dos puertas con nombre que hacen falta para las reglas cuyo argumento no es
> el modelo. Su prueba de denegación está en
> `tests/Feature/Seguridad/LasPoliciesEstanRegistradasTest.php`.
>
> **EL ARGUMENTO DE ABAJO SIGUE VALIENDO Y POR ESO EL MIDDLEWARE NO SE
> QUITÓ.** Las Policies son la SEGUNDA capa, que es lo que §35 llama defensa
> en profundidad. El middleware falla cerrado; una Policy olvidada falla
> abierta. Quitarlo porque «ya hay Policy» sería cambiar una garantía por una
> costumbre.
>
> Y lo que el argumento de abajo no vio: una regla en un método PRIVADO no la
> puede usar un segundo sitio, así que el tercero la escribe otra vez. Ya
> pasó --`updateDomiciliary` estaba acotado y `showDomiciliary`, cuarenta
> líneas más abajo, no-- y es lo que este cambio cierra.

#### El razonamiento original, que se conserva

`08-seguridad-y-autorizacion.md` pide Policy o Gate por recurso. Aquí la
autorización vive en middleware (`negocio`, `admin`, `modulo:<área>`) y **el
ámbito sale de la sesión, nunca del cuerpo de la petición** — que es justo lo
que exige §08 en multitenancy.

No se cambia, y no es un atajo: la pregunta de este dominio casi siempre es
«¿este tendero es dueño de este negocio?», no «¿este usuario puede ver este
registro?». Resolverla una vez en el middleware es más seguro que repetirla en
cada Policy, porque **olvidarla deja de ser posible**: el endpoint que no entra
al grupo no recibe `busines_id` y falla, en vez de servir datos de otro.

Donde sí hace falta comprobar el registro concreto, se comprueba en la
consulta, no después: `FichaDelDomiciliarioController` resuelve el domiciliario
con un `whereHas('businesses', …)` contra el negocio de la sesión, de modo que
el de otra tienda sencillamente no existe.

### La API es `/v1`, no `/api/v1`

`09-contratos-api-y-errores.md` recomienda `/api/v1` **y añade «no forzar
cambio en APIs ya publicadas»**. Esta se monta con `apiPrefix: 'v1'`, así que
las rutas son `/v1/…`. Dependen de ella la app móvil publicada en Google Play
y la App Store, tres paneles web y los webhooks de Bold. No se toca.

### Los identificadores están en español

`12-calidad-y-herramientas.md` pide nombres descriptivos, no un idioma. El
código, el dominio y los textos están en español; el panel tomó la misma
decisión. Mezclar idiomas era el problema, no la solución.

---

## 3 · Lo que convenía adoptar, y ya está adoptado

No son excepciones: eran reglas del estándar que este repositorio **podía
cumplir hoy** y no cumplía. **Las tres están hechas**, y la tabla se queda
como historia de la adopción en vez de borrarse: dice cuánto costó cada una
y en qué paso del CI vive ahora, que es lo que hace falta el día que una se
caiga.

| Regla | Estado medido | Coste |
|---|---|---|
| ~~`12` — Pint~~ | **Hecho** (9 nov. 2026): 506 archivos formateados, 106 imports sin usar fuera | **Hecho**: `pint --test` es un paso de `pruebas.yml` |
| ~~`AGENTS.md` — `env()` solo en `config/`~~ | **Hecho**. Eran dos, no tres: `ClaveDeSemilla` ya usaba `config()` y solo lo nombraba en un comentario. `ZonaHorariaDeLaBase` leía `DB_TIMEZONE` directo —con la config en caché habría dicho «sin poner» justo donde sí estaba puesta— y `ReverbClient` resultó **código muerto**: nadie lo nombraba desde 2025. Borrado, y con él `app/Helper/` | — |
| ~~`12` — análisis estático~~ | **Hecho**: PHPStan 2.3.5 + Larastan en **nivel 5** sobre `app/`, `database/`, `routes/` y `tests/`, con una línea base de **44 entradas** que solo se regenera para que baje, nunca para que suba. Esta fila decía «No hay PHPStan/Larastan … Decisión pendiente» y dejó de ser verdad sin que nadie la tocara | **Hecho**: es un paso de `pruebas.yml` |

---

## 4 · Lo que ya se cumple

Conviene decirlo, porque el registro no es solo una lista de culpas:

- **Transacciones y concurrencia** (§07): cupones y promociones se canjean con
  `whereRaw` en el `increment` —`uses_count < max_uses`,
  `(max_usos IS NULL OR usos < max_usos)`— para que dos compradores a la vez no
  se pasen del tope; mover un pedido, pesarlo y escribir en un chat toman
  `lockForUpdate()`.
- **Idempotencia** (§07): el reintento de pago reutiliza el pedido a medias sin
  gastar dos veces el mismo uso de promoción.
- **Webhooks firmados** (§08): el de Bold **rechaza todo en producción** si la
  llave secreta está vacía y lo deja escrito en el registro, en vez de aceptar
  una firma HMAC que cualquiera podría calcular. Es la ruta que marca pedidos
  como pagados.
- **Pruebas por funcionalidad** (§11): 1394 en Pest, con casos de error,
  permisos y límites — incluidas pruebas de que un campo **no** viaja.
- **Nada de `catch (\Throwable)` para devolver 200** con `success: false`.


---

## 5 · Las reglas nuevas (21–31): consultas, índices, caché y Reverb

El paquete creció el 9 de noviembre de 2026 con once documentos sobre
rendimiento y tiempo real. Medido contra este backend, **salimos mejor aquí que
en las reglas viejas**, y conviene decir por qué: estas no piden reescribir
nada, piden no hacer cosas concretas, y este código en general no las hace.

### Lo que ya se cumple, medido

| Regla | Qué se buscó | Resultado |
|---|---|---|
| §22 — nada de `->get()->count()` | todo `app/` | **0** |
| §22 — `paginate()` con `per_page` del cliente | todo `app/` | **0** |
| §22 — `orderBy($input)` sin lista blanca | todo `app/` | **0** |
| §27 — canales privados | los 7 eventos que difunden | **7 de 7** en `PrivateChannel` |
| §26 — caché sin mezclar inquilinos | las 15 llamadas a `Cache::` | **ninguna** guarda datos de un negocio; son el código de acceso de una persona y los tokens de APNs/FCM, que son de la plataforma |
| §11 nuevo — prueba de autorización de canal con los cuatro casos | `tests/Feature/Pedidos/CanalesEnVivoTest` | **ya estaban los cuatro**: la tienda dueña, su domiciliario, un domiciliario de otra tienda y un desconocido |

### `ShouldDispatchAfterCommit`: cero, y aun así no es deuda

§27 pide esa interfaz y **ninguno de los siete eventos la usa**. Pero lo que la
interfaz consigue —que no se avise de algo que luego se deshace— este código ya
lo consigue por colocación: los cuatro `broadcast()` van **fuera** de su
transacción, en un `try` aparte, y con el porqué escrito al lado:

> *«El aviso va FUERA de la transacción: si el push falla, el pesaje ya está
> guardado y lo peor que pasa es que el cliente se entere al abrir la app. Al
> revés, un fallo del aviso desharía un pesaje que ya movió el inventario.»*

**Aun así conviene añadirla**, y no por cumplir: la colocación la sostiene una
persona que se acuerde, y la interfaz la sostiene el framework. El día que
alguien mueva ese `broadcast()` dos llaves más arriba, hoy no se entera nadie.
Es una línea por evento.

### Lo que falta de verdad

| Regla | Estado | Qué costaría |
|---|---|---|
| §22 — listados acotados | **1 caso**: `CategoryBusinessController` devuelve `CategoryBusiness::all()` | Acotar o paginar. Hoy son pocas filas; crece con el catálogo |
| §24 — índices diseñados desde filtro + orden y comprobados con `EXPLAIN` | **sin registro**. Hay índices, pero nadie anotó contra qué consulta ni con qué plan | Empezar por las dos consultas que más duelen: el tablero de pedidos y `GeneradorDeReportes` |
| §25 — medir antes de optimizar (p50/p95, nº de consultas, memoria) | **no se hace** | Una prueba de benchmark aparte. Ojo con lo que el propio §25 avisa: **SQLite no reemplaza el plan de MySQL**, y la suite corre en SQLite |

### Una advertencia del propio estándar que aquí pesa

§25 dice que SQLite no sirve para juzgar un plan de ejecución. Esta suite corre
en SQLite en memoria, así que **ninguna prueba de rendimiento que se escriba
aquí dirá la verdad sobre producción**. Ya mordió una vez por otro motivo:
`incomeBusiness` usaba `DAYOFWEEK()`, que no existe en SQLite, y esa pantalla
no se pudo probar hasta que se portó la expresión.

Medir rendimiento de verdad exige un entorno con MySQL. Hasta que lo haya, las
reglas §24 y §25 quedan **reconocidas y sin adoptar**, que es distinto de
incumplidas: no se puede cumplir una regla cuyo instrumento no existe todavía.


---

## 6 · Las reglas 32–47: contratos, seguridad, colas y operación

Tercera entrega del paquete (10 de noviembre de 2026): autenticación y tenant,
OpenAPI, OWASP, integraciones HTTP, idempotencia, colas, migraciones sin corte,
archivos, análisis estático, CI/CD, pruebas avanzadas, observabilidad,
topología de Reverb, dependencias y una ruta de madurez.

De todo el estándar, **esta entrega es la que encontró los dos problemas de
verdad**. No son de estilo ni de carpetas.

### 1 · Bold se llamaba sin tiempo de espera — ARREGLADO

`BoldService` hacía **sus siete llamadas HTTP sin `timeout` ni
`connectTimeout`**, y el cliente de Laravel no pone límite por defecto.

Eso no es una mala práctica abstracta: `OrderController` y `PaymentController`
reciben ese servicio **inyectado**, o sea que las llamadas ocurren dentro de la
petición del comprador. Con Octane los trabajadores son un grupo fijo. Basta
que Bold deje de contestar —no que falle: que *tarde*— para que los
trabajadores se agoten esperando y **caiga todo el backend, no solo los
pagos**.

Arreglado: una sola `peticion()` que aplica cabeceras y topes, 15 s de
respuesta y 5 s de conexión, configurables por `BOLD_TIMEOUT` y
`BOLD_CONNECT_TIMEOUT` para poder apretarlos el día de un incidente sin
desplegar. Fallar con un error que el comprador ve es peor que no fallar, y
muchísimo mejor que colgar la tienda entera.

### 2 · Veinte avisos de seguridad en las dependencias — ARREGLADO

**Cerrado el 10 de noviembre de 2026, en la rama
`seguridad/dependencias-al-dia`.** `composer audit` dice ahora «No security
vulnerability advisories found»: de 20 avisos a cero.

`composer.json` **no cambió**. Las restricciones ya permitían estas
versiones, así que lo único que se movió es el lock —60 filas— y revertirlo
es revertir un archivo. **Cero saltos de versión mayor:**

| Paquete | De | A |
|---|---|---|
| `laravel/framework` | v12.66.0 | v12.72.0 |
| `league/commonmark` | 2.10.0 | 2.10.7 |
| `league/flysystem` | 3.35.2 | 3.36.0 |
| `phpoffice/phpspreadsheet` | 1.30.6 | 1.30.8 |
| `symfony/filesystem` | v7.4.15 | v7.4.23 |
| `symfony/http-foundation` | v7.4.16 | v7.4.23 |
| `symfony/http-kernel` | v7.4.16 | v7.4.23 |
| `symfony/mime` | v7.4.16 | v7.4.22 |
| `symfony/routing` | v7.4.15 | v7.4.23 |
| `symfony/yaml` | v7.4.22 | v7.4.23 |

Verificado **después** de actualizar, no antes: 1127 pruebas pasando (4
omitidas), PHPStan sin un solo aviso nuevo contra la línea base, Pint limpio.

> **Un susto por el camino que conviene saber.** PHPStan dio 493 errores
> justo después. No era la actualización: la **línea base estaba vacía** en
> el árbol de trabajo (33 bytes, `ignoreErrors: []`), pisada en algún momento
> del día. Restaurada la versión comiteada —258 entradas—, cero avisos. Que
> el número coincidiera exactamente con el total congelado fue la pista.

Lo que decía antes este apartado, y por qué pesaba:

| Paquete | Gravedad |
|---|---|
| `symfony/yaml` | **alta** (y otro más) |
| `symfony/http-foundation` | **alta**, y cuatro avisos más |
| `phpoffice/phpspreadsheet` | **alta** ×2, media ×1 |
| `symfony/mime`, `league/commonmark` | media |
| `symfony/routing`, `symfony/http-kernel`, `symfony/filesystem`, `league/flysystem` | sin clasificar / baja |

`phpoffice/phpspreadsheet` pesaba aquí más que en otro proyecto: es lo que
procesa **los Excel que suben los tenderos**, o sea entrada de fuera pasando
por un lector con CVE conocido. Esa era la razón de no dejarlo para después.

Y se hizo en su propia rama, aparte de todo lo demás del día, justamente para
que se pudiera revertir solo. `composer audit` ya corre en el CI, así que el
número no puede volver a crecer en silencio.

### Lo que ya se cumple

- **§33 aislamiento por inquilino**: el ámbito sale de la sesión, nunca del
  cuerpo. Es la decisión de la §2 de este registro y aquí se confirma como
  acierto, no como atajo.
- **§36 webhooks entrantes**: el de Bold verifica firma HMAC y **rechaza todo
  en producción si falta la llave**.
- **§37 idempotencia**: el reintento de pago reutiliza el pedido a medias sin
  gastar dos veces el mismo uso de promoción; cupones y promociones se canjean
  con `whereRaw` en el `increment`.
- **§38 colas**: `QUEUE_CONNECTION=database`, cuatro jobs acotados. Nada de
  Horizon, que es justo lo que pide la regla 23 del `AGENTS.md`: no instalar
  herramienta por cumplir un documento.

### Lo que falta, medido

| Regla | Estado |
|---|---|
| §34 — OpenAPI como contrato | **no hay**. El contrato lo sostienen hoy las 1069 pruebas y el congelado de rutas de `tests/Feature/Contrato` |
| §41 — `declare(strict_types=1)` | **0 de 363 archivos**. §12 dice que se adopta gradualmente y que no se reescriben todos para ponerlo |
| §41 — análisis estático | **no hay** PHPStan/Larastan |
| §42 — el CI corre Pint, análisis y `composer audit` | **corre `php -l` y las pruebas**. Faltan los tres |
| §44 — SLO, tracing, runbooks | **no hay** |

De todo eso, **lo que conviene hacer ya es el CI**: `pint --test` y
`composer audit` son dos líneas y convierten en automático lo que hoy depende
de que alguien se acuerde. El resto son decisiones de producto, no deuda.


---

## 7 · El estándar final (48–56) y sus herramientas

Cuarta y última entrega: automatización de gates, PHPStan/Larastan con
baseline, CI y protección de ramas, límites de archivo como alertas, fronteras
entre módulos, presupuestos de rendimiento, operación y respaldos, plan de
actualizaciones y una definición de «terminado» auditable. **58 documentos.**

Lo nuevo de verdad no son los documentos: es que trae **herramientas**.

| Qué | Dónde | Resultado al correrlo aquí |
|---|---|---|
| `check_module_boundaries.py` | `scripts/` | *«Sin `app/Modules/`: no aplica»*. Degrada bien, que es lo correcto para la decisión de §1 de este registro |
| `check_file_sizes.py` | `scripts/` | **148 archivos** por encima del objetivo (decía 113: es la cifra que `cifras_del_registro.py` cazó el 15 de noviembre). Coincide con lo medido en §1 |
| `barrido-pendientes.json` | `scripts/` | **Nuestro.** Las las constantes que deciden dinero, permiso o estado y que el barrido todavía no toca, en tres grupos que piden tres cosas distintas: **4 muertas** (nadie en `app/` las nombra — sabotearlas daría hallazgos falsos), **22 sin ninguna prueba** (ahí no hace falta medir, la respuesta ya se sabe) y **24 con prueba** (ahí sí, porque tener prueba no es que la cace). Eran 10, 21 y 26 el 15 de noviembre por la mañana; cambiaron al actuar sobre los tres grupos. `barrido_sabotaje.py --comprobar` la valida, y recuenta en `tests/` en vez de creerse el campo del JSON |
| `cifras_del_registro.py` | `scripts/` | **Nuestro, no del estándar.** Remide las 30 cifras que estos tres documentos afirman y dice cuáles ya no describen el repositorio. No es una puerta: una cifra vieja a veces fecha una decisión a propósito. Trae `--control`, porque un patrón que deja de encajar diría «cuadra» estando ciego |
| `phpstan.neon.dist`, `pint.json`, CI, CODEOWNERS | `tooling/` | **Plantillas, no configuración.** Su propio README dice «NO se instalan automáticamente» |

### Cuatro cosas que aprendimos convirtiendo, y que valen más que el contador

Esto no está en ningún documento del estándar y es lo que de verdad hay que
saber para seguir:

1. **Un extractor que copia texto copia también sus dependencias.** Las reglas
   nombraban `UnidadDeVenta::` y `LandingRequest::`; el `use` se quedó en el
   controlador. Hay un comprobador para esto.
2. **Un `validate()` puede tener sufijo.** `…validate([...])['segment'] ?? []`
   devuelve el contenido, no el sobre. Sustituir la sentencia entera se lo
   come.
3. **Lo que corre ANTES del validate deja de correr antes.** Es el criterio
   que más conversiones ha vetado: un `merge()` que traduce los nombres que
   manda la app publicada, un `return` temprano para compilaciones viejas, un
   `abort_if` que contesta 404. Un `FormRequest` valida antes de entrar al
   método, así que invierte esas respuestas.
4. **Deshacer también se hace mal.** Un script que sustituye «el primer
   `validated()` del archivo» acierta solo si el archivo tiene un método
   convertido. Con dos, intercambia las reglas y puede dejar un endpoint
   **sin ninguna validación**. Lo cazó la suite, no la revisión.

### Lo que queda

**101 validaciones en línea.** De ellas, unas 75 viven en endpoints que
ninguna prueba llama: ahí toca escribir primero la prueba que fija la
respuesta de hoy. El resto son las que el detector veta por los motivos de
arriba, y van una por una.
