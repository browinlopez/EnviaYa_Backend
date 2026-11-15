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

Nada está desplegado. Medido el **15 de noviembre de 2026**: son **167 commits**
en `validacion/integracion` sobre `Devs`, y `Devs` a su vez va **22 commits por
delante de `origin/Devs`**: **189** en total. La cuenta **incluye el commit que la escribe**: si no, cada
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

Y la sexta, que tampoco es una puerta y tarda dos segundos:

```
python scripts/cifras_del_registro.py --control                 # que no este ciego
python scripts/cifras_del_registro.py --salida-de-pest pest.txt # las 29 cifras
```

Remide las **29 cifras** que `ESTADO.md`, `EXCEPCIONES.md` y este documento
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
Sin empujar, medido el 15 de noviembre: la app va **5 commits** por delante de
`origin/Browin_IOS` --que es su rama de verdad; contra `origin/main` saldrían 157
y ese número no significa nada--, el panel **8** sobre su propia rama ya
empujada, y la landing **1** sobre `origin/main`.

> **Y el `master` del panel tiene 2 commits que la rama local no tiene.** Eso
> no es cosa de empujar sino de traer: hay que integrarlos antes, o el merge
> los pisa. Este párrafo decía «1 commit de la app» cuando eran 5.

```bash
node scripts/publicar-ota.mjs      # en EnviaYa_App
```

El runtime de iOS va dos versiones por detrás, así que lo que se publique ahí
tiene que funcionar contra 1.0.9.

---

## 5 · Landing: la instalación está rota

`npm run build` y `npm run lint` fallan en `EnviaYa_Landing` por una
instalación incompleta de `node_modules`: falta el paquete `rolldown`, que
pide vite 8.2.2. **Fallaba ya antes del cambio de esta tanda** —comprobado
guardando el cambio y construyendo sobre `main` limpio—, así que no es una
regresión, pero sí significa que el sitio no se puede construir ahora mismo.

No lo arreglé porque `pnpm install` quiere purgar `node_modules` entero y eso
no se hace sin pedirlo. Cuando se quiera:

```bash
cd EnviaYa_Landing/EnviaYa_Landing
CI=true pnpm install --frozen-lockfile
npm run build
```

El cambio de CSS de esta tanda se verificó sin construir: llaves balanceadas,
`@starting-style` bien formado, el token `--entrada` existe,
`npm run check:tokens` en verde y Prettier limpio.

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
- **Siete rutas sin llamante**, sin decidir: tres piden criterio —la baja de
  cuenta, el crédito a medias y la contradicción de `bold/status/{ref}`— y
  cuatro son duplicados o reemplazos. Están en `scripts/rutas-candidatas.json`,
  cada una con su motivo.
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
