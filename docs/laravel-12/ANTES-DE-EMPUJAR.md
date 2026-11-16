# Lo que falta y no está en el código

**Actualizado el 15 de noviembre de 2026** (antes: 14 de noviembre). Todo lo
que se podía hacer desde el repositorio está hecho y commiteado. Lo de abajo son acciones en consolas que no controla
este repositorio: Dokploy, Cloudflare, Google Cloud y las tiendas de
aplicaciones. Va aquí, con el valor exacto, para que no haya que reconstruir
el razonamiento cada vez.

El orden importa en un sitio: **primero el empuje, después la OTA.** Una OTA
contra un backend viejo deja a la gente con una app que llama a cosas que el
servidor todavía no sirve.

---

## 1 · Empujar

> **ESTÁ EN GITHUB, EN `refactor-total`, Y NO ESTÁ DESPLEGADO.** Las dos
> cosas a la vez, el 15 de noviembre de 2026. Por petición expresa, el trabajo
> de los cuatro repositorios se subió a una rama `refactor-total` para tenerlo
> en un sitio y poder partir de ahi:
>
> | repositorio | rama remota, en el primer empuje | commits |
> |---|---|---|
> | `EnviaYa_Backend` | `refactor-total` → `c51a071` | 188 sobre `origin/Devs` |
> | `Vecipaya_Aliados` | `refactor-total` → `702c779` | 8 |
> | `EnviaYa_App` | `refactor-total` → `731a6da` | 5 |
> | `Vecipaya_Landing` | `refactor-total` → `e8ca4f0` | 1 |
>
> Esos son los SHA del **primer** empuje: la rama del backend sigue avanzando
> con lo que se commitee despues, asi que no hay que leerlos como su punta.
>
> **Y no despliega nada**, comprobado y no supuesto: `Devs`, `main` y `master`
> siguen exactamente donde estaban --backend `Devs` en `92ecb00`, panel
> `master` en `7e36883`-- y `gh run list --branch refactor-total` no devuelve
> ninguna ejecución en ninguno de los dos repositorios con CI. El workflow
> filtra `branches: [Devs, main, master]`, así que **en `refactor-total` las
> puertas no corren**: lleva las que se corrieron en local, no un verde de
> GitHub. Si se quiere ese verde, hay que añadir la rama a ese filtro.
>
> Lo que sigue abajo --empujar de verdad, o sea desplegar-- **no ha pasado**.
> Esa es la decisión que queda, y los números de abajo se miden contra
> `origin/Devs`, que es la rama que despliega.

Nada está desplegado. Medido el **15 de noviembre de 2026**: son **185 commits**
en `validacion/integracion` sobre `Devs`, y `Devs` a su vez va **22 commits por
delante de `origin/Devs`**: **207** en total. La cuenta **incluye el commit que la escribe**: si no, cada
marcador la deja corta en uno y el verificador avisa para siempre de un
desajuste que es de construccion. Hasta que `Devs` suba, las ramas
de trabajo que cuelgan de ahí no tienen dónde apoyarse.

> **Eran 50 el 11 de noviembre, 137 el 13 y 179 el 14.** La cuenta se mide, no se
> recuerda: este párrafo ya decía 50 cuando eran 137.
>
> Y 179 es mucho para una sola revisión, así que conviene
> decirlo: empujar por partes se revisa mejor que todo de golpe. Las ramas
> están separadas por asunto y cada mensaje de commit cuenta su porqué, así
> que se pueden subir en tandas —contrato y Resources, instrumentos y
> detectores, seguridad, documentación— en vez de en un solo empujón.
>
> **Y dos cosas cambian lo que reciben las apps publicadas**, que no era el
> caso el 11 de noviembre: se retiró `domiciliary_fee` y el correo del
> domiciliario del historial del comprador, y `credit_debt_cap` del login.
> Está comprobado contra el sistema en marcha —33 de 33 procesos del arnés, y
> la forma de las respuestas comparada clave por clave con la de antes— pero
> es lo primero que mirar si algo se ve raro después del despliegue. El
> detalle está en `ESTADO.md`.

**El webhook de Dokploy despliega solo** al llegar a GitHub, así que empujar
*es* desplegar. Lo que sube incluye el arreglo de que **Bold estaba
completamente muerto** —`peticion()` se llamaba a sí misma, de un reemplazo a
ciegas que nunca se desplegó—, así que esto arregla cobros, no los rompe.

Se comprueba que el despliegue entró pidiendo una ruta nueva: por ejemplo
`GET /v1/negocio/domiciliarios/rendimiento`, que antes no existía.

### Antes de empujar, las cuatro puertas y dos mas

```
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pest
python scripts/arnes/correr.py          # con el servidor en marcha
```

Y la quinta, que **no** es una puerta porque tarda 40 minutos y no da verde ni
rojo sino una lista:

```
python scripts/barrido_sabotaje.py --comprobar   # valida la lista, no ejecuta
python scripts/barrido_sabotaje.py               # las 40 minutos
```

Sabotea una por una las constantes que deciden dinero o permisos y dice cuáles
**no** caza ninguna prueba. No se corre en cada cambio: se corre al tocar
dinero, permisos o un estado, y cuando se añade una constante de esas --que
entonces se añade también a `scripts/barrido-mutaciones.json`--. La primera
vez encontró tres agujeros con 1361 pruebas en verde; el por qué está en la
sexta lección de `ESTADO.md`.

**Lo que le falta por barrer está medido, en `scripts/barrido-pendientes.json`.**
De las **137** constantes públicas de `app/`, **100** están barridas y **0 de las
restantes deciden** --eran 131 y 43 por la mañana: subieron porque las seis
listas de estados que las validaciones escribían a mano pasaron a ser
constantes, más dos nuevas, menos una que sobraba-- dinero, un permiso o un estado. Eso corrige algo que este
repositorio vino diciendo —«son etiquetas y plazos que no deciden nada»— y que
con la lista delante era falso: entre ellas están las parejas de cuatro
agujeros reales (`DEVUELTA` junto a `APLICADA`, los dos `AJUSTE` junto a
`RECAUDO`, `APROBADA` junto a `PAGADA`, `KILO` y `GRAMO` junto a `LIBRA`).

Y la división en tres grupos cambia el trabajo, porque **la mitad de lo que
parecía barrido no lo es**:

| grupo | cuántas | qué pide |
|---|---|---|
| muertas | **0** | **Vaciado el 15 de noviembre, y no borrando constantes: midiéndolas.** Esta fila afirmaba que sabotearlas «no puede fallar ninguna prueba», y era falso para tres de las cuatro —sus propias filas traían las pruebas que las nombran—. Ver abajo |
| sin prueba | **0** | **A cero el 16 de noviembre.** Las usaba `app/` y ninguna prueba las **nombraba**, que no es «nadie las defiende»: 8 de las 10 estaban defendidas por listas derivadas que una prueba afirma con literales |
| con prueba | **0** | **A cero el 16 de noviembre.** Eran las que el barrido no alcanzó a medir antes de que lo mataran; las 8 estaban defendidas |

### La lista de pendientes a cero, y la regla que costó un veredicto

**16 de noviembre de 2026.** Los tres grupos a cero: las 4 `muertas` el día
15, y hoy las 10 `sin_prueba` y las 8 `con_prueba`. **16 estaban defendidas y
2 no.**

| | |
|---|---|
| `Promotion::BORRADOR` | **sin defensa** — prueba nueva en `PromocionesDeLaTiendaTest`, probada rompiéndola |
| `Promotion::TIPOS` | **sin defensa** — la lista blanca del tipo: sin `nxm` no se puede crear una promoción de N×M |

**Y «sin prueba» nunca quiso decir «sin defensa»:** de las 10 clasificadas
así, **8 estaban defendidas**. Siete porque `Pqrs::ESTADOS`,
`SafetyIncident::ESTADOS` y `PushCampaign::ESTADOS_A_MANO` se construyen desde
ellas y una prueba afirma esos arrays **con literales**; la octava porque
`VisitantesTest` manda `'kind' => 'servicio'` escrito contra la lista blanca.
Escribirles pruebas habría sido trabajo redundante.

#### La regla nueva: una prueba que cae solo vale si está verde aislada

Al sabotear las dos de `Promotion`, la suite contestaba **«2 failed»** y
parecía defensa. Las dos que caían eran de **porcentaje a tiempo**, en
`HistorialDePedidosTest`, un fichero que no nombra `Promotion` ni `nxm`.
Aislado, ese fichero fallaba **esas mismas dos pruebas sobre código sano**.

Con eso arreglado, las dos mutaciones **pasan las 1404 enteras**. O sea que el
«2 failed» no era defensa: era el reloj.

> Una mutación que mueve el estado compartido puede tumbar una prueba que no
> la vigila. Si se lee eso como «defendida», la constante se da por cubierta
> y nadie vuelve a mirarla. **FALLA solo cuenta si la prueba que cayó está
> verde aislada.**

#### El defecto de reloj que salió de paso

Tres pruebas de `HistorialDePedidosTest` **se rompían todos los lunes entre
las 00:00 y las 04:00**, y una de ellas estaba verde **por el motivo
equivocado**.

La causa, medida volcando la respuesta y no supuesta: el comprador estaba, los
cuatro pedidos estaban y eran del negocio, y el endpoint devolvía
`pedidos: 0`. El `desde` por omisión del historial es
`now()->startOfWeek()->startOfDay()` y el ayudante insertaba con
`sale_date = now()->subHours(4)`: un lunes a las 00:10, hace cuatro horas es
**domingo**, fuera de la semana en curso.

Arreglado con `sale_date = now()`, que cae siempre dentro de la ventana
`[startOfWeek()->startOfDay(), now()->endOfDay()]` y deja la prueba midiendo
la ventana de verdad. **No se congeló el reloj**, que era lo primero que
apetecía: `travelTo($fecha)` sin callback no lo deshace y el `tearDown` de
Laravel tampoco —comprobado en `vendor/`—, así que se habría filtrado a las
pruebas siguientes, que es el mismo tipo de defecto que se estaba arreglando.

Y la tercera, *«sin pedidos medibles no se inventa un cero»*, pasaba igual con
la ventana vacía: cero pedidos dentro da la misma respuesta que cero pedidos
medibles. Verde y ciega.

#### Un defecto del propio instrumento

El campo `tests` de `barrido-pendientes.json` **solo cuenta las pruebas que
nombran la constante directamente**, así que para una usada a través de una
lista derivada subcuenta. Es «grep no es sabotaje» otra vez, ahora dentro de
los datos: a `PushCampaign::BORRADOR` la lista le daba una prueba, y la que de
verdad la caza es otra —la de la lista blanca—. Queda dicho en la cabecera del
fichero para quien lo vuelva a generar.

### Las cuatro «muertas»: el grupo estaba mal planteado

Cerrado el **15 de noviembre de 2026**. La fila de arriba decía que sabotear
esas cuatro «no puede fallar ninguna prueba», y **sus propios datos la
contradecían**: tres de las cuatro traían el campo `tests` con fichero y
número de veces. El grupo confundía dos cosas que piden trabajo distinto:

| |
|---|
| **no la usa `app/`** — es una pregunta de diseño: se borra, o falta el código que debería usarla |
| **ninguna prueba caza un cambio** — es lo único que produce **hallazgos falsos**, y un instrumento que los da se deja de leer |

Solo una era lo segundo. **Medido, no deducido:**

| constante | veredicto |
|---|---|
| `CashMovement::AJUSTE` | **FALLA** — su prueba la afirma con literal: `toBe('ajuste')` |
| `OrdersSales::ESTADOS` | **FALLA** — `toBe([1, 2, 3, 4, 5])` |
| `OrdersSales::SIN_PESAJE` | **FALLA** — el valor de la columna contra la constante, y el `0` es un literal de la migración |
| `StoreCreditMovement::AJUSTE` | era la **única** con `tests: []`, y la única de verdad inmedible |

Las tres primeras se sabotearon **una por una contra el fichero de pruebas que
las nombra**, no con la suite entera: correr su fichero basta para decir
«defendida» —si ahí la cazan, la respuesta ya es sí—, y la suite completa solo
aporta cuando la respuesta es **no**, porque entonces falta saber si la caza
algo inesperado. Son segundos en vez de 80 por mutación, y la memoria no se
mueve.

A la cuarta se le escribió la prueba que le faltaba, en
`tests/Feature/Credito/CreditoDeTiendaTest.php`, al lado de la de los cuatro
estados de un corte, que ya tenía esa forma. **Probada rompiéndola.** Lo que
fija es lo que el comentario de la constante ya decía: `reversar()` decide la
idempotencia **por tipo**, así que si `AJUSTE` valiera lo mismo que `REVERSO`,
una fila de ajuste haría creer que el cupo ya se devolvió. **Hoy esa
consecuencia es latente, no viva** —nada escribe `AJUSTE`—, y se dice así en
la prueba: lo que se evita es que el día que haya endpoint para cuadrar un
descuadre estrene el defecto.

> El guion del sabotaje dirigido necesitó la guarda dos veces. La primera
> versión leía «código de salida distinto de 0» como «la prueba cayó» —y la
> ruta de `pest` estaba escrita mal, con `\v` y `\b` interpretados como
> escapes, así que **pest no corrió**: el mandato falló y las tres salieron
> «defendidas». Tres veredictos de una corrida que no ocurrió, que es el fallo
> contra el que avisa el propio barrido. Ahora se **exige** el resumen de Pest
> en la salida: sin resumen es ERROR, no veredicto. Y hay pasada de control
> sobre código sano antes de tocar nada, porque un fichero que ya estaba rojo
> haría que el «falla» de después no dijera nada.

**Y las dos cifras del barrido pasaron a estar instrumentadas.** El registro
afirmaba cuántas constantes están barridas y cuántas quedan, y
`cifras_del_registro.py` no las medía —mientras el comentario de otra medida
dice, de esa misma frase, que es «la que decide si una tercera pasada del
barrido tiene sentido»—. Al medirlas salió un sobreconteo:
`CashMovement::RECAUDO` está **dos veces** en `barrido-mutaciones.json`, como
mutación y como CONTROL, así que las 83 entradas son **82 constantes
distintas** y las «79» de antes eran 78. Uno de más que nadie habría visto.

### La tercera pasada: 28 de 46, y seis sin defensa

Corrida el **15 de noviembre de 2026**. Midió **28 de las 46** y el sistema la
mató por memoria en la 29. **Es la tercera vez que pasa** —también la segunda
pasada—, y un `kill` se salta el `finally`: dejó `Pqrs::ABIERTO = 1` en el
árbol y el cerrojo puesto con el PID de un proceso ya muerto. Detectado,
comprobado que era el único cambio, restaurado.

**Veintidós defendidas y seis sin una sola prueba que las cazara:**

| constante | qué decide |
|---|---|
| `AccesoBiometricoController::NOMBRE_TOKEN` | **La más grave.** `borrarLosBiometricos()` borra por `name`, así que con el nombre cambiado «desactivar el acceso con huella» contesta **éxito** y el token sigue sirviendo: quien apagó la opción porque perdió el teléfono cree que lo cerró |
| `AccesoBiometricoController::HABILIDAD` | La habilidad guardada en `personal_access_tokens.abilities`. **Hoy no decide nada** —nadie llama a `tokenCan()`, se buscó en todo `app/`— y se vigila por el día que alguien la compruebe. Decirlo importa: afirmar que «concede un permiso» sería describir código que no existe |
| `CashDeposit::CONSIGNACION` | El canal choca con un estado: `EfectivoDeLaTienda` busca `'pendiente'` en la columna del canal y **el panel del tendero deja de ver los depósitos de plataforma** |
| `Coupon::TIPO_FIJO` | La lista blanca deja de aceptar `'fixed'`: **no se pueden crear cupones de monto fijo**. Las filas viejas siguen bien, porque `descuentoPara` compara con el valor guardado —la primera lectura fue otra y hubo que trazarla |
| `Coupon::TIPOS` | El envío gratis sale del vocabulario: ese cupón no se puede crear |
| `DocumentosDelDomiciliarioController::TIPOS` | Un papel sale de la lista y **no se puede subir**: el domiciliario no entrega su EPS y en el panel queda como si no la tuviera |

Las seis tienen prueba, en `tests/Feature/Contrato/VocabularioGuardadoEnColumnasTest.php`,
y las seis están **probadas rompiéndolas**: 6 de 6 cazadas. Todas se afirman
con **literales**, porque esos valores viven en columnas de filas que ya
existen —`personal_access_tokens.name`, `cash_deposits.canal`,
`coupons.type`, `domiciliary_documents.type`— y afirmarlos contra su propia
constante sería la tautología de siempre.

**Y el barrido ya puede continuar donde lo dejaron**, que es el arreglo que
faltaba después de tres muertes: apunta cada veredicto en
`scripts/.barrido-resultados.json` **en cuanto lo sabe**, y `--continuar`
salta lo ya medido. Se pierde, como mucho, la mutación que estaba corriendo.
Por omisión **no** continúa: un veredicto viejo sobre código nuevo es peor que
no tener veredicto.

```
python scripts/barrido_sabotaje.py --lista scripts/barrido-mutaciones-3.json --continuar
```

> **«Ninguna prueba la nombra» no es «ninguna prueba la defiende», y este
> registro lo dijo mal.** Iba a escribir 22 pruebas para el grupo `sin_prueba`
> y antes lo comprobé: sabotear `Pqrs::ABIERTO` —que ninguna prueba nombra—
> tumba dos pruebas, porque `Pqrs::ESTADOS` se construye desde ella y la
> prueba afirma el array con literales. **Grep no es sabotaje.** De las 22 que
> clasifiqué así, el barrido confirmó defendidas a varias: `CouponService::DEVUELTO`,
> `FacturaService::EMITIDA`, `LandingRequest::EN_GESTION`,
> `CancelacionDelPedido::COMPRADOR` y `::TIENDA`. Escribirles pruebas habría
> sido trabajo redundante.


Y la sexta, que tampoco es una puerta y tarda dos segundos:

```
python scripts/cifras_del_registro.py --control                 # que no este ciego
python scripts/cifras_del_registro.py --salida-de-pest pest.txt # las 32 cifras
```

Remide las **32 cifras** que `ESTADO.md`, `EXCEPCIONES.md` y este documento
afirman, y dice cuáles ya no describen el repositorio. Existe porque esto ha
pasado **tres veces**, y dos están confesadas aquí dentro: «este párrafo ya
decía 50 cuando eran 137» y «esta fila decía "no existe", que era verdad
cuando se escribió». La tercera fue el 15 de noviembre: siete a la vez.

**No va al CI a propósito.** Un paso que no bloquea y que nadie lee es
decoración --es exactamente lo que le pasó al formato de la app-- y bloquear
no sirve, porque una cifra vieja a veces es correcta: «se midió el 9 de
noviembre» fecha una decisión y tiene que seguir diciendo lo de entonces. Lo
decide quien lee. Se corre al tocar estos tres documentos y antes de empujar.

El `--control` no es opcional: mete texto con las cifras cambiadas a propósito
y exige cazarlas todas. Si un patrón deja de encajar porque alguien reescribió
la frase, el informe diría «cuadra» estando ciego, que es el fallo que este
repositorio ya ha visto en un detector. Pasó al escribirlo: el control pasó y
la corrida de verdad encontró un patrón ciego, porque el control traía su
propia redacción. Hacen falta los dos.

---

## 2 · Dokploy: dos variables

### `BACKUP_DISK`

```
BACKUP_DISK=r2_respaldos
```

**`r2_respaldos`, no `r2`.** Esto importa más que parece, y lo escribo con
detalle porque yo mismo recomendé el valor equivocado antes de ir a mirarlo:

- `r2` es el bucket de **medios** —fotos de productos, logos— y lleva `url`
  pública, porque la app tiene que poder enseñar las imágenes.
- `r2_respaldos` es el de **copias** y va deliberadamente sin `url`.

Con `BACKUP_DISK=r2` la copia se sube igual, `backup:monitor` la encuentra
sana, el aviso diario dice que todo va bien, **y el volcado de la base de
datos entera queda descargable por cualquiera que pruebe la URL**: personas,
teléfonos, direcciones, pagos.

De dónde salió mi error: **lo decía el propio `.env.example`** del
repositorio, en su bloque de «qué poner en producción». Ya está corregido, y
hay dos redes nuevas por si vuelve:

- `App\Support\DestinoDelRespaldo` aborta el respaldo si el disco de destino
  tiene `url`, enganchado al evento que spatie lanza **antes** de volcar la
  base. El fallo se convierte en un correo que dice qué pasa.
- una prueba afirma que `.env.example` no vuelve a recomendar `r2`, porque una
  guarda que se dispara todos los días en producción acaba quitada.

Si hacen falta credenciales separadas para ese bucket, son
`R2_BACKUP_ACCESS_KEY_ID` y `R2_BACKUP_SECRET_ACCESS_KEY`; vacías, usa las de
`R2_*`. El bucket por omisión es `vecipaya-respaldos`, que ya es otro que el de
medios.

**Cómo comprobar que quedó bien**, sin esperar a las 02:30:

```bash
php artisan backup:run --only-db
php artisan backup:monitor
```

El primero tiene que terminar sin error y el segundo decir que la copia está
sana. Si el disco está mal puesto, el primero aborta con el mensaje de la
guarda.

### `SENTRY_LARAVEL_DSN`

```
SENTRY_LARAVEL_DSN=<el DSN del proyecto>
```

Sin esto no hay DSN y Sentry no manda nada: los errores de producción solo
quedan en los logs del contenedor, que es donde nadie mira hasta que alguien
se queja. `config/sentry.php` lo lee con `env('SENTRY_LARAVEL_DSN',
env('SENTRY_DSN'))`, así que cualquiera de los dos nombres sirve.

### Y las 26 que la plantilla documenta y el `.env` local no tiene

Todas tienen valor por omisión en `config/`, así que la aplicación arranca sin
ellas; no son un bloqueo. Pero en producción conviene repasar al menos estas
cuatro, que cambian comportamiento:

| | |
|---|---|
| `CORS_ALLOWED_ORIGINS` | si está vacío, el panel desde otro dominio puede quedarse fuera |
| `GOOGLE_MAPS_SERVER_KEY` | la clave del servidor, distinta de la de la app |
| `BACKUP_MAIL_TO` | vacío = `gerencia@vecipaya.com`, que es lo correcto |
| `SANCTUM_EXPIRATION` | vacío = los tokens no caducan nunca |

---

## 3 · Rotar los secretos, y la clave de Maps

Estaban en la lista de bloqueos de producción desde antes de esta tanda y no
se tocan desde el repositorio:

- **Secretos por rotar.** Los que hayan estado en un `.env` compartido o en un
  commit. Rotar en el proveedor y actualizar en Dokploy; el repositorio no
  guarda ninguno.
- **La clave de Google Maps sin restringir.** Una clave sin restricción de
  referente o de IP la puede usar cualquiera que la saque del bundle de la
  app, y se factura a la cuenta. Hay dos claves distintas: la de la app
  —restringir por aplicación Android/iOS— y `GOOGLE_MAPS_SERVER_KEY`
  —restringir por IP del servidor—.

---

## 4 · La OTA, **después** de empujar

La app declara **1.0.12**, Play sirve **1.0.11** e iOS sigue en **1.0.9**.
**Empujado, todo: 4 de 4 con su HEAD exactamente en `origin/refactor-total`.**
Lo que queda es desplegar, y eso se mide sobre la rama que despliega, que es
distinta en cada repositorio: la app **5 commits** sobre `origin/Browin_IOS`
--su rama de verdad; contra `origin/main` saldrían 157, que es un número que
asusta y no significa nada--, el panel **11** sobre `origin/master`, y la
landing **1** sobre `origin/main`. La app no tiene webhook: sale por OTA, aparte.

> **Aquí decía que el `master` del panel tenía 2 commits por traer, o un merge
> los pisaba. Era falso, las dos mitades.** Al ir a traerlos:
> `git log HEAD..origin/master --no-merges` daba **cero** --eran los dos merges
> de los PR #1 y #2 hechos en GitHub, y sus tres padres reales ya eran
> antepasados de la rama-- y el merge resultó en un árbol **idéntico**, mismo
> objeto, cero ficheros distintos.
>
> El error fue de lectura, y es fácil de repetir: `git diff HEAD origin/master`
> mostraba 507 inserciones y leí eso como «master tiene 507 líneas que no
> tenemos». Son las versiones **viejas** de ficheros que esta rama ya cambió, y
> aparecen como inserciones porque el diff va de aquí hacia allá. En el otro
> sentido había 24.163 borrados: la báscula, el contrato con el backend y sus
> pruebas. **Mover la rama a master habría sido la pérdida; traer master no era
> nada.** Se hizo igual, por la topología: ahora la rama desciende de
> `origin/master` y llevarla allí es un avance limpio.

```bash
node scripts/publicar-ota.mjs      # en EnviaYa_App
```

El runtime de iOS va dos versiones por detrás, así que lo que se publique ahí
tiene que funcionar contra 1.0.9.

---

## 5 · Landing: la instalación estaba rota **en esta máquina**

Arreglado el **15 de noviembre de 2026**, y la redacción anterior de este
apartado era injusta con el repositorio. Decía «la instalación está rota» y
«el sitio no se puede construir ahora mismo»: lo segundo era verdad acá, pero
lo primero daba a entender que el defecto estaba en el código. No estaba.

**La prueba es la bandera que se usó para arreglarlo.** `CI=true pnpm install
--frozen-lockfile` pasó sin una sola queja, y esa bandera es justo la que falla
si el bloqueo no cuadra con `package.json`. Cuadra: 349 paquetes, todos
reutilizados del almacén, 15 s. Lo que faltaba era desplegarlo.

Y una lectura mía que también se cae: conté 19 directorios en `node_modules` y
lo leí como media instalación. No lo era —pnpm pone ahí solo las dependencias
directas y lo demás en `.pnpm`, que tiene 352—. **Y qué faltaba exactamente ya
no se puede comprobar:** la redacción anterior nombraba `rolldown`, e instalar
reescribió justo la prueba que lo diría. Queda como estaba escrito, sin
reconfirmar.

**Medido después de instalar**, cada puerta con su código de salida de verdad y
no con el del `tail` que lo estaba leyendo —el primer bucle midió cuatro puertas
y dio cuatro ceros, `format:check` incluido, porque `$?` detrás de una tubería
es el del último mandato y no el del `npm`:

| puerta | resultado |
|---|---|
| `npm run build` | 18 rutas prerenderizadas, sitemap con 13 urls, 488 ms |
| `npm run lint` | limpio |
| `npm run check:tokens` | ningún color literal fuera de `tokens.css` |
| `npm run check:modulos` | sin cruces entre los 10 módulos |
| `npm run test:unit` | **91** pruebas en 9 ficheros |
| `npm run test` | **51** correctas, 0 fallas |
| `npm run test:a11y` | **175** correctas, 0 fallas |
| `npm run test:responsive` | **80** páginas medidas, 0 desbordamientos |
| `npm run format:check` | **falla, y no por el repositorio: ver abajo** |

Esas nueve cifras **están fechadas, no instrumentadas**, y es a propósito:
viven en otro repositorio y tres de ellas piden un navegador y un servidor
levantado, así que `scripts/cifras_del_registro.py` no las mide —la misma
regla que ya aplica al número de pruebas del backend—. Para rehacerlas:
`npm run preview` en un terminal y las nueve órdenes en otro.

Las tres de navegador pidieron antes `npx playwright install chromium`: el
`pnpm install` trajo Playwright 1.62.1 y el chromium que había era de otra
versión. Necesitan `npm run preview` levantado en el 5175.

Con esto, el cambio de CSS de aquella tanda —llaves balanceadas,
`@starting-style`, el token `--entrada`— ya no está verificado «sin
construir»: **está construido**.

### `format:check` no puede pasar en un clon de Windows

Falla en 93 ficheros y **la única diferencia es el fin de línea**. Medido, no
supuesto: el blob que guarda git tiene **0 CRLF**, el fichero en disco los
tiene en todas sus líneas, y comparar el fichero con lo que Prettier
produciría da **idéntico en cuanto se normaliza el salto**. `core.autocrlf`
vale `true` en la configuración del sistema de Git para Windows, la landing no
tiene `.gitattributes`, y Prettier trae `endOfLine: "lf"` por omisión.

O sea: esa puerta pasaría en Linux y **no puede pasar acá**, así que hoy no
mide nada en esta máquina. Se arregla con una línea —`"endOfLine": "auto"` en
`.prettierrc`, o un `.gitattributes` que fije el salto— pero eso es trabajo del
repositorio de la landing y va en su propia rama, no colado desde acá.

**No lo «arreglé» con `prettier --write`**, que es lo que apetece: reescribir
93 ficheros para tapar un síntoma local habría ensuciado el árbol de otro
repositorio sin corregir la causa.

### Y nada de esto corre solo

`EnviaYa_Landing` **no tiene `.github/workflows`**. Las nueve puertas de arriba
existen y funcionan, pero solo cuando alguien las escribe a mano: nada las
corre al empujar. Es **el único de los cinco** repositorios así —backend
`pruebas.yml` y `pruebas-mysql.yml`, aliados `calidad.yml`, app `ci.yml`,
admin `pruebas.yml`—. Es lo que explica que una instalación a medias pudiera
pasar desapercibida.

---

## 6 · Protección de rama — §50, y la trampa

Lo que §50 pide está hecho **menos la parte que vive en la consola de
GitHub**, que no toco sin pedírtelo:

- `.github/CODEOWNERS` escrito, con las dieciocho zonas de riesgo
  —autenticación, pasarela, efectivo, liquidaciones, migraciones, el contrato
  de la API, el pipeline y la configuración que decide dónde acaban los
  datos—. Comprobado que las dieciocho rutas existen: una regla que apunta a
  una carpeta que no está no protege nada y no avisa.
- Las tres acciones externas **fijadas por SHA** de commit, que es lo que §50
  pide para repositorios sensibles. Los SHA se resolvieron contra GitHub, no
  se escribieron de memoria: `actions/checkout@11d5960…`,
  `shivammathur/setup-php@eb7c497…`, `actions/cache@0057852…`, cada uno con
  su etiqueta al lado en un comentario para saber de qué versión viene.

### La trampa: NO actives «Require review from Code Owners»

Hoy bloquearía todos los merges. GitHub no permite aprobar tu propio pull
request, y en este repositorio hay 473 commits de una persona y 18 de otra.
Exigir la revisión de un propietario de código con un solo desarrollador
activo es cerrar la puerta por dentro.

`CODEOWNERS` queda igualmente, porque sirve de registro de qué zonas son las
delicadas, y se vuelve una compuerta de verdad el día que haya una segunda
persona con permiso de escritura: ese día basta con marcar la casilla.

### Lo que sí conviene activar, que protege sin bloquear

```bash
gh api -X PUT repos/browinlopez/EnviaYa_Backend/branches/main/protection   --input - <<'JSON'
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["pruebas", "pruebas-mysql"]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": null,
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false
}
JSON
```

Tres cosas de ese cuerpo:

- **`enforce_admins: true`** hace que la regla valga también para quien la
  puso. Sin eso, protege de todo el mundo menos de quien más empuja.
- **`required_pull_request_reviews: null`** es deliberado, por lo de arriba.
- **Los nombres de los checks** son los de los flujos
  (`.github/workflows/pruebas.yml` y `pruebas-mysql.yml`). Si no coinciden
  exactamente, GitHub espera para siempre un check que nunca llega y la rama
  queda sin poder fusionar: conviene comprobarlos con
  `gh api repos/browinlopez/EnviaYa_Backend/commits/main/check-runs --jq '.check_runs[].name'`
  **después** del primer empuje, cuando el CI ya haya corrido una vez.

Y por eso esto va **después** de empujar: hasta que el CI corra una vez, los
nombres de los checks no existen y la regla se escribiría a ciegas.

---

## 6.5 · El CI de dos repositorios está en rojo, y uno es el que despliega

Medido el **16 de noviembre de 2026** con `gh run list`, no supuesto. Hasta
ahora este registro hablaba de las puertas **corriéndolas aquí**; esto es lo
que pasa cuando las corre GitHub.

| repositorio | últimas corridas |
|---|---|
| `EnviaYa_Backend` | **fallo** en las tres últimas, sobre `Devs` |
| `EnviaYa_Admin` | **fallo** en las cinco últimas, desde al menos el 17 de septiembre |
| `EnviaYa_Aliados` | correcto |
| `EnviaYa_App` | correcto |
| `EnviaYa_Landing` | no tiene CI —§5 |

### El backend: 11 pruebas, y 4 tienen causa exacta

La corrida de `Devs` del 6 de noviembre da **11 failed, 4 skipped, 1047
passed**. Son de hace nueve commits y de antes de esta tanda, pero `Devs` es
**la rama que despliega**, así que ese es el estado de lo que hoy está en
producción.

**Cuatro de las once se reprodujeron acá, y la causa es un artefacto que no
está en el repositorio.** `PasswordConfirmationTest`,
`PasswordResetTest` ×2 y `ProfileTest` piden vistas Blade; los dos *layouts*
de Breeze usan `@vite(...)`, que lanza si falta
`public/build/manifest.json`. Ese fichero **está en `.gitignore`**, el CI
**no tiene ningún paso que construya assets** —comprobado leyendo el
workflow— y acá existe con fecha de **septiembre de 2025**.

Comprobado apartándolo y volviendo a correr esos cinco ficheros: **4 failed,
33 passed**, con el error literal
`ViteManifestNotFoundException ... (View: resources/views/layouts/guest.blade.php)`.

> Esas cuatro pruebas pasan acá por una carpeta de hace catorce meses que
> nadie versiona. Es la misma forma que el fallo del lunes de madrugada:
> verde por un motivo que no está en el repositorio.

**Y ata con una decisión que ya estaba abierta:** las cuatro son del andamiaje
de Breeze (§7). Si Breeze se retira, estas cuatro se van con él. Si se queda,
el CI necesita construir los assets.

Las otras siete —`ContratoDesdeLaAppTest` ×5 y `CanalesEnVivoTest` ×2—
**pasan acá sin el manifiesto**, así que su causa es otra y no se puede
reproducir en esta máquina. Puede que esta tanda ya las arregle; **no se
sabrá hasta que `refactor-total` entre en el filtro de ramas del CI**, que es
otra de las decisiones abiertas.

### El admin: rojo desde septiembre, y por eso nadie vio lo demás

Cinco corridas, cinco fallos. El trabajo muere en **«Pruebas de unidad»** con:

```
Cannot find module '@rolldown/binding-wasm32-wasi'
```

**La causa está en el fichero de bloqueo, y se puede leer:** de las cuatro
entradas con `rolldown` que trae `package-lock.json`, el único binario nativo
es `@rolldown/binding-win32-x64-msvc`. **No hay ninguno de Linux.** El bloqueo
se generó en Windows y npm anotó solo la plataforma en la que estaba, así que
`npm ci` en el runner deja `rolldown` sin binding y `vitest` muere cargando su
configuración.

**Y lo que eso tapa importa más que el fallo.** El trabajo se cae antes del
paso «Formato», que por tanto **nunca se ha ejecutado**. Corriendo acá lo que
corre el CI, después de un `npm ci` limpio:

| paso | resultado |
|---|---|
| `npm run lint` | correcto |
| `npm run test:unit` | correcto |
| `npm run build` | correcto |
| `npx prettier --check` | **151 ficheros sin formatear** |

Y ahí hay un segundo problema: **`prettier` no está declarado** en
`package.json` —solo `eslint-config-prettier`—, así que ese `npx` se baja la
versión que haya ese día. Una puerta de formato sin versión fijada puede
cambiar de resultado sin que nadie toque el repositorio.

**También lleva dos ficheros de bloqueo a la vez**: `package-lock.json` del 16
de septiembre y `pnpm-lock.yaml` del 17 de agosto. El CI usa `npm ci`; la
instalación que había acá era de pnpm y le faltaban **las 20 dependencias
directas** aunque `node_modules` tuviera 331 entradas.

**No toqué nada del admin.** Está en `master`, no es trabajo de esta tanda, y
el arreglo del bloqueo necesita una vuelta de CI para confirmarse. Lo que hay
acá es la causa, medida, para que arreglarlo sea un rato y no una
investigación.

---

## 7 · Lo que sigue abierto y es decisión, no tarea

- ~~**`JsonResource`: quedan ~288 endpoints.**~~ **HECHO.** Quince tandas, 56
  clases en `app/Http/Resources/`, y `scripts/formas_de_entidad.py` mide
  **cero** formas de entidad por migrar y **cero** modelos pelados en
  `app/Http`. Lo que queda son informes de agregados, donde un Resource sería
  la ceremonia que §05 prohíbe.
- **Los percentiles por ruta de §25** siguen necesitando días de uso real. La
  línea base del arnés sirve para comparar contra sí misma, no para decir
  cuánto tarda la plataforma.
- **La báscula Bluetooth** sigue fuera: es lo único que obliga a compilar, y
  antes hay que comprobar con una báscula real si los modelos de las tiendas
  hablan BLE o Bluetooth clásico. Si es clásico, la función es solo Android.
- **Protección de rama** en GitHub (§50), sin poner. **Comprobado con `gh` el
  15 de noviembre**, no supuesto: `branches/main/protection` y
  `branches/Devs/protection` devuelven las dos *«Branch not protected»*. Y la
  rama por omisión del repositorio es `main`, mientras el trabajo va a `Devs`.
- **Seis rutas sin llamante**, sin decidir —eran siete y la séptima ya se
  retiró, ver el punto 4—. Están en
  `scripts/rutas-candidatas.json` y cada una tiene abajo lo que hace falta
  para decidirla, medido el **16 de noviembre de 2026**. Lo que cambió
  respecto a como estaban clasificadas: **dos de las cuatro «duplicadas» no
  lo son**, y la de negocios no es un duplicado de la de administración sino
  una puerta más ancha.

### Las siete, con la evidencia

**1 · `POST v1/businesses/store` — `BusinessController@store`**

Reemplazo vivo: `POST v1/admin/businesses` (`NegociosApiController`). La
diferencia está en la puerta: la vieja lleva `EnsureAdmin` pero **no**
`EnsureModuleAccess:negocios,gestionar`, que la nueva sí. O sea que una
cuenta interna sin el módulo de negocios puede crear un negocio por la vieja
y no por la nueva. El sistema de módulos existe justo para acotar al equipo
interno; esta ruta lo rodea.

**2 · `PUT v1/businesses/update` — `BusinessController@update`**

**No es un duplicado de la ruta de administración: es la puerta del tendero,
y es más ancha que la moderna.** Lo moderno es `PUT v1/negocio/me`, donde el
middleware `negocio` resuelve el local desde la sesión y el cliente no puede
ni nombrar otro. Comparando los campos que cada una acepta, la vieja permite
**cuatro que la moderna no**:

| campo | qué cambia |
|---|---|
| `logo` | la imagen de la tienda |
| `type` | la categoría del negocio (`in:1,2`), de la que depende el esquema de productos |
| `state` | encender o apagar la tienda — y hay un endpoint propio para eso, hecho aparte **para que la auditoría distinga «apagó la tienda» de «corrigió su dirección»** |
| `owner_ids[]` | **quién es propietario de la tienda.** Se valida solo como `exists:owner,owner_id`, así que admite cualquier propietario existente: un tendero podría atar otra cuenta a su tienda, o quitarse a sí mismo |

**Y no está abierta**, que fue mi primera lectura al ver que le faltan
`EnsureAdmin` y el permiso de módulo: la guarda está en el controlador,
`negarNegocioAjeno($request, $request->busines_id)`, que da 403 a quien no
administra ese negocio y deja pasar al rol 4. El alcance es la tienda propia.
Lo que queda por decidir es si esas cuatro capacidades deben existir por una
ruta que ningún cliente usa.

**3 · `DELETE v1/users/delete` — `UserController@desactivate`**

Primero, el nombre engaña: **desactiva, no borra**. Y el camino que se usa de
verdad **no pasa por aquí**, medido en la landing que está en este mismo
disco: `src/modulos/cuenta/EliminarCuenta.jsx` llama a
`enviarSolicitud("eliminacion", ...)`, que hace `POST /v1/solicitudes-free`.
O sea que darse de baja **radica una solicitud** y alguien la atiende a mano.

Comprobado que esa solicitud no se pierde: `SolicitudDesdeLaWebRequest` acepta
`'tipo' => 'nullable|string|max:30'` sin lista blanca, `LandingRequest` conoce
el tipo `eliminacion`, mapea *«eliminar mi cuenta»* a él, tiene
**`DIAS_ELIMINACION = 15`** y `SolicitudesApiController` trata las vencidas
como bajas de cuenta. El panel que las muestra es `EnviaYa_Admin`; el de
aliados no las nombra.

Retirarla no cambia ningún comportamiento, porque nadie la llama. La decisión
real es otra: **si darse de baja debe seguir siendo manual con 15 días de
plazo, o pasar a ser automática** —y entonces esta ruta es por donde iría.

**4 · ~~`POST v1/email/resend-verification`~~ — RETIRADA el 16 de noviembre de 2026**

`v1/email/resend-verification` y `v1/resend-verification-email` **apuntan al
mismo método**, `VerificacionDeCorreoController@resendVerificationEmail`. Son
dos grafías del mismo endpoint. La app llama la segunda —
`features/auth/data/authApi.ts:165`, y está en la lista de rutas públicas de
`httpClient.ts:137`—, así que retirarla no quitó ninguna capacidad: el mismo
método sigue alcanzable por el otro camino. Era la única de las siete sin
nada que decidir, y es la que se hizo.

**Sus tres llamantes eran PRUEBAS**, no clientes —dos en `RegistroResistenteTest`
y una en `EntradaDeCuentaYResenasTest`—, así que se repuntaron al otro
camino en vez de borrarse: el manejador tiene que seguir cubierto.

Lo que se movió con ella, cada cosa regenerada y no editada a mano:

| | |
|---|---|
| `superficie-api.json` | 293 → **292**, con `CONGELAR_SUPERFICIE=1` |
| `openapi.json` | 294 → **292** operaciones, con `php scripts/openapi.php` |
| `rutas-candidatas.json` | de `pendientes` a `grupos`, que es el registro de lo retirado |
| `LasRetiradasNoVuelvenTest` | cuenta las filas de `grupos`: 32 → **33** |

**Y una cifra contada dos veces a propósito se puso roja sola, que es para lo
que estaba.** `ContratoOpenApiTest` afirma `expect($publicas)->toBe(21)` —exacto,
mientras sus otras tres comprobaciones son mínimos— y su comentario dice que es
«el mismo 21 que cuenta por su cuenta el docblock de
`SoloParaCambiarPorSesion`». La ruta retirada no pedía sesión, así que al
irse quedó en 20 y hubo que mover **las dos** cuentas. Medido del contrato
generado: 292 operaciones, 272 con Bearer, 20 públicas.

**5 · `GET v1/bold/status/{ref}` — `PaymentController@checkStatus`**

La contradicción se resuelve a favor de la app: `routes/api.php` tenía escrito
«si es un manejador de verdad y se queda», y la app dice en
`features/payments/data/paymentsApi.ts:7` que `payment/status` reemplaza al
viejo `GET /bold/status/{ref}`. **Son métodos distintos**: el vivo es
`PaymentController@checkStatusByReference`. Retirar la ruta deja `checkStatus`
como código muerto, así que las dos cosas se van juntas o no se van.

Y el comentario de la app dice de más: *«(no existe en el backend
actual)»*. **Sí existe**, está en el router. O sea que la app ya da por
retirada una ruta que sigue ahí, lo cual juega a favor de retirarla de
verdad: nadie la espera.

**6 · `GET v1/creditos` — `MiCreditoController@index`: no es sobra**

Confirmado lo que la lista olía. Devuelve **todos los créditos del comprador
en todas las tiendas** —en qué tiendas tiene cupo y cuánto— mientras la app
solo llama a `@enTienda`, que es el cupo de UNA tienda. El backend está
terminado y lo que falta es la pantalla. **Retirarla sería borrar trabajo
hecho**, no limpiar.

**7 · `GET v1/ads/featured` — `AdsController@featured`**

Sus hermanas están vivas (`ads/banners`, `ads/coupons/validate`,
`ads/banners/{id}/track`); solo `featured` no se llama. Su única aparición en
la app es `core/api/httpClient.test.ts:38`, dentro de la lista de rutas que no
necesitan sesión. Si se retira **hay que borrar esa línea**, o la próxima
medición la volverá a dar por viva.

### Dos hallazgos que salieron de paso, y no son de las rutas

**La respuesta de «reenviar verificación» sí permite enumerar correos.** El
código lleva escrito *«Respuesta genérica (evita enumeración de usuarios)»* y
la rama siguiente lo deshace:

| caso | respuesta |
|---|---|
| el correo no existe | «Si el correo existe, se enviará un enlace de verificación.» |
| existe y ya está verificado | «El correo ya está verificado.» |

Se distinguen, así que se puede averiguar si una dirección está registrada. La
ruta no pide sesión —solo `throttle:10,1`— porque es justo la que usa quien no
puede entrar.

**El comportamiento no se cambió, y el motivo es que no es un defecto con
arreglo obvio.**
Comprobado que la app no se fija en el texto —lo devuelve tal cual— así que
unificar los dos mensajes no la rompería. Pero quien ya tiene el correo
verificado pulsaría «reenviar», leería «se enviará un enlace» y no le
llegaría nada. Es un cambio de texto de autenticación con un precio en
claridad: es decisión, no tarea.

**Lo que sí se arregló son los tres sitios que prometían la garantía que el
código no cumple**, porque un comentario así es peor que ninguno: el
siguiente que lo lea da el problema por resuelto.

| dónde | decía |
|---|---|
| el controlador | «Respuesta genérica (evita enumeración de usuarios)», justo encima de la rama que sí la evita y dos ramas por encima de la que la deshace |
| `routes/api.php` | «manda SIEMPRE al correo de la cuenta y **nunca revela si existe**» |
| `RegistroResistenteTest` | «Respuesta idéntica para el que existe y el que no» — y solo comprueba el caso del que **no** existe |

El nombre de esa prueba sí era exacto desde el principio —*«un correo que no
existe no delata si está registrado»*—; era el comentario el que prometía más
de lo que hay. Comparar los dos mensajes ahí pondría la prueba en rojo, y por
eso no se hace antes de decidir el texto.

**Nueve rutas de verificación de correo, no dos mecanismos.** El apartado de
Breeze dice «dos mecanismos a la vez» y contadas son nueve:
`email/verification-notification`, `laravel-verify-email`,
`laravel-verify-email/{id}/{hash}`, `verify-email`, `verify-email/reenviar`,
`v1/email/resend-verification`, `v1/resend-verification-email`,
`v1/admin/users/{id}/verify-email` y `v1/conjunto/porteria/verificar` —esta
última es de portería y no de correo, pero el nombre la mete en cualquier
búsqueda—. De las ocho de correo, **dos son la misma** (punto 4) y cuatro son
el andamiaje de Breeze.
- **El andamiaje web de Breeze**: 25 rutas vivas —login, perfil, contraseña y
  su propia verificación de correo por URL firmada— en un repositorio que
  `AGENTS.md` describe como API sin Blade ni vistas. Son **dos mecanismos de
  verificación de correo a la vez**. Ya están congeladas en
  `superficie-web.json`, así que no cambian en silencio; lo que falta es
  decidir si sobran.
- ~~**El formato de `EnviaYa_App`**: nada lo ejecuta.~~ **HECHO.**
  `scripts/formato.mjs` con trinquete —base de **285** ficheros heredados, que
  solo puede bajar—, enganchado en `package.json` (`formato`, `verificar`) y
  en `ci.yml`. Corrido hoy: *«0 nuevos sin formatear (285 en la base, de
  285)»*. Lleva control de ceguera: si la base tiene ficheros y la medición
  devuelve cero, sale 2 por sospechoso en vez de verde.
