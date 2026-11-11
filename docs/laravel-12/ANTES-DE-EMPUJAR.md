# Lo que falta y no está en el código

**11 de noviembre de 2026.** Todo lo que se podía hacer desde el repositorio
está hecho y commiteado. Lo de abajo son acciones en consolas que no controla
este repositorio: Dokploy, Cloudflare, Google Cloud y las tiendas de
aplicaciones. Va aquí, con el valor exacto, para que no haya que reconstruir
el razonamiento cada vez.

El orden importa en un sitio: **primero el empuje, después la OTA.** Una OTA
contra un backend viejo deja a la gente con una app que llama a cosas que el
servidor todavía no sirve.

---

## 1 · Empujar

Nada está empujado. Son **50 commits** en `validacion/integracion` sobre
`Devs`, y `Devs` a su vez va **22 commits por delante de `origin/Devs`**: hasta
que `Devs` suba, las catorce ramas de trabajo que cuelgan de ahí no tienen
dónde apoyarse.

**El webhook de Dokploy despliega solo** al llegar a GitHub, así que empujar
*es* desplegar. Lo que sube incluye el arreglo de que **Bold estaba
completamente muerto** —`peticion()` se llamaba a sí misma, de un reemplazo a
ciegas que nunca se desplegó—, así que esto arregla cobros, no los rompe.

Se comprueba que el despliegue entró pidiendo una ruta nueva: por ejemplo
`GET /v1/negocio/domiciliarios/rendimiento`, que antes no existía.

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
Hay 1 commit de la app sin empujar, en `tendero/paridad-con-el-panel`.

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

## 6 · Lo que sigue abierto y es decisión, no tarea

- **`JsonResource`: quedan ~288 endpoints.** La primera tanda está hecha y el
  método está escrito en `ESTADO.md`. No se migra lo que no se pueda comparar
  byte a byte.
- **Los percentiles por ruta de §25** siguen necesitando días de uso real. La
  línea base del arnés sirve para comparar contra sí misma, no para decir
  cuánto tarda la plataforma.
- **La báscula Bluetooth** sigue fuera: es lo único que obliga a compilar, y
  antes hay que comprobar con una báscula real si los modelos de las tiendas
  hablan BLE o Bluetooth clásico. Si es clásico, la función es solo Android.
- **Protección de rama** en GitHub (§50), sin poner.
