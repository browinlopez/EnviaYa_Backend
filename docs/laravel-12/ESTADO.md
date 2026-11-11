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
| ~~§24 índices con `EXPLAIN`~~ | **Hecho el 10 de noviembre de 2026.** Ver «Los índices, medidos» más abajo |
| §25 presupuestos de rendimiento | Ya se puede —el motor está—, pero sigue sin hacerse: un presupuesto es un número por endpoint («este responde en menos de X»), y ponerlo sin medir latencias de verdad sería inventarlo. Hace falta la medición en el servidor primero |
| §34 OpenAPI | Es construir y mantener un contrato nuevo: un proyecto, no un ajuste |
| §41 `declare(strict_types=1)` | 0 de 363 archivos. §12 dice que se adopta gradualmente y que **no** se reescriben todos para ponerlo |
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
