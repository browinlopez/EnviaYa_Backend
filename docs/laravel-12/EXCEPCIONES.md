# Excepciones y decisiones del backend frente al estándar

> Registro del repositorio `EnviaYa_Backend` (§20 del estándar).
> **Medido el 9 de noviembre de 2026** sobre Laravel 12.66, 115 controladores,
> 54 servicios, 70 modelos y 1022 pruebas.
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
| **Realidad** | Estructura por capas con subcarpeta de dominio: `app/Http/Controllers/Negocio/`, `app/Services/`, `app/Models/Order/`. 115 controladores en ~20 carpetas de dominio |
| **Motivo** | **Se midió antes de decidir: 70 de los 115 controladores (60 %) usan modelos de otro dominio.** `User`, `Business`, `Domiciliary` y `OrdersSales` los usa casi todo. Con ese acoplamiento, `app/Modules/` no produce módulos: produce un `app/Shared/Models/` con la mayoría de los modelos dentro y unos envoltorios delgados encima — el cajón que §00 llama anti-patrón, pagando antes un diff de ~240 archivos |
| **Decisión** | **9 de noviembre de 2026: no se crea `app/Modules/`.** Se cumplen dentro de la estructura actual las reglas que sí muerden —`FormRequest`, una responsabilidad por archivo, límites de línea—, que es lo que §02 («si una estructura existente ya es consistente, adapta progresivamente») y §20.1 («no big-bang») permiten expresamente |
| **Riesgo aceptado** | La carpeta por tipo técnico sigue siendo el anti-patrón de §00. Se mitiga porque el segundo nivel **sí** es de dominio |
| **Revisión** | Si algún día un área deja de cruzar —hoy las más limpias son Conjunto, Chat y Marketing— puede nacer como módulo. No antes: dos estructuras conviviendo es peor que cualquiera de las dos |

### Validación en línea, no en `FormRequest`

| | |
|---|---|
| **Regla** | `04-capas-http.md`: validar con `FormRequest`, trabajar con `validated()` |
| **Realidad** | Eran **173 llamadas a `->validate(`** contra **2** `FormRequest`. Al 9 de noviembre de 2026: **149 y 25** — convertidas Negocio y Admin/Api |
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

### La salida se arma a mano, no con `JsonResource`

| | |
|---|---|
| **Regla** | `04-capas-http.md` y `09-contratos-api-y-errores.md`: `JsonResource` para la salida |
| **Realidad** | `app/Http/Resources/` **no existe**. La respuesta se construye como array en el controlador; dos modelos tienen `toApi()` |
| **Motivo** | Mismo argumento que arriba, con un agravante: la forma de la respuesta **es el contrato** que consumen la app, tres paneles y las versiones ya publicadas en las tiendas. Reescribirla a ciegas rompe clientes que no se pueden actualizar |
| **Riesgo aceptado** | No hay un sitio único donde ver qué campos expone un endpoint; esconder un campo nuevo es fácil de olvidar |
| **Alternativa adoptada** | Mismo trinquete. Y las pruebas del contrato público siguen siendo la red: 1022 pruebas, varias de ellas **de ausencia** (que un campo NO viaje) |
| **Revisión** | Cada PR |

### Los archivos son más grandes que los objetivos

| | |
|---|---|
| **Regla** | `03-limites-de-lineas.md`: controlador 15–35 líneas, revisar por encima de 50 |
| **Realidad** | Mediana **149**; **105 de 115** controladores pasan de 50. El mayor es `Order/OrderController.php` con **994** |
| **Motivo** | El propio §03 dice que son «umbrales orientativos, no bloqueos automáticos», y §00 pone el tamaño en el **último** lugar de las prioridades |
| **Riesgo aceptado** | Bajo en general —solo 3 controladores pasan de 7 métodos públicos, o sea que no son cajones de sastre— y alto en los tres grandes |
| **Revisión** | `OrderController` (994 líneas) cuando se toque el flujo de pedidos; no antes y no como PR de limpieza suelto |

---

## 2 · Decisiones: reglas que aquí **no** son deuda

### Autorización por middleware, no por Policy

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

## 3 · Lo que conviene adoptar ya

No son excepciones: son reglas del estándar que este repositorio **puede
cumplir hoy** y todavía no cumple.

| Regla | Estado medido | Coste |
|---|---|---|
| ~~`12` — Pint~~ | **Hecho** (9 nov. 2026): 506 archivos formateados, 106 imports sin usar fuera | Falta `pint --test` en el CI |
| ~~`AGENTS.md` — `env()` solo en `config/`~~ | **Hecho**. Eran dos, no tres: `ClaveDeSemilla` ya usaba `config()` y solo lo nombraba en un comentario. `ZonaHorariaDeLaBase` leía `DB_TIMEZONE` directo —con la config en caché habría dicho «sin poner» justo donde sí estaba puesta— y `ReverbClient` resultó **código muerto**: nadie lo nombraba desde 2025. Borrado, y con él `app/Helper/` | — |
| `12` — análisis estático | No hay PHPStan/Larastan | El estándar dice «no asumir que existen ni agregarlos a espaldas del equipo». Decisión pendiente |

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
- **Pruebas por funcionalidad** (§11): 1022 en Pest, con casos de error,
  permisos y límites — incluidas pruebas de que un campo **no** viaja.
- **Nada de `catch (\Throwable)` para devolver 200** con `success: false`.
