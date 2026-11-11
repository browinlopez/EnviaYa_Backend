# Línea base de latencia del arnés

> **Esto no es latencia de producción.** Léelo antes de usar un número de aquí
> para decidir algo.

Lo genera `python scripts/linea_base_de_latencia.py`, que vacía el registro de
latencia, recorre el arnés **22 veces** y resume lo que midió
`MedidaDeLaPeticion`.

| | |
|---|---|
| peticiones | **3168** |
| rutas con muestras | **110** |
| rutas que llegan a 20 muestras y por tanto tienen percentil | **110** |

## Qué es

Una **referencia contra sí misma**. §25 del estándar pide percentiles por
endpoint; la herramienta de medida existe desde la tanda de observabilidad y
lo que falta es tráfico: con un solo recorrido casi ninguna ruta llega a las
20 muestras que hacen honesto un percentil, y el informe las deja en
blanco a propósito en vez de llamar «p99» al máximo.

Repetir el recorrido rellena las muestras. Con eso se puede responder una sola
pregunta, y es útil: **¿empeoró algo entre dos tandas en la misma máquina?**
Si el p95 de una ruta se dobla, algo la volvió más lenta.

## Qué no es

- **No es el tiempo que ve un tendero.** Corre contra `php artisan serve`, que
  atiende de una en una y no tiene el opcache afinado del contenedor de
  producción.
- **Los datos son los de desarrollo**, con su tamaño y su distribución. Un
  índice que basta con siete negocios puede no bastar con setecientos; eso lo
  cubre `PlanDeLasConsultasCalientesTest` con `EXPLAIN`, no esto.
- **El reparto de llamadas es el del arnés**, no el del uso real: el arnés
  llama una vez a cada cosa, y la gente llama mil veces a la vitrina y ninguna
  a los informes del panel. Un p50 «en conjunto» de aquí no significa nada.

El número honesto por endpoint sigue necesitando unos días de uso de verdad.
Eso no lo da ninguna herramienta.

## Por qué exige Reverb en marcha

El guion **se niega a medir** si Reverb no contesta, y no es escrúpulo.

Medido: un `broadcast()` contra un Reverb caído cuesta **2049 ms** —el
`connect_timeout` de dos segundos que `config/broadcasting.php` pone a
propósito— y con Reverb en marcha, 372 ms la primera vez y menos después. Seis
de los siete eventos son `ShouldBroadcastNow`, así que esa espera ocurre
**dentro** de la petición.

Sin Reverb, crear un pedido sale en 4,1 s y cancelarlo en 6,2 s: dos y tres
anuncios de dos segundos. No es un hallazgo —es el tope funcionando, y está
ahí para que un Reverb lento no agote los trabajadores de Octane— pero una
línea base recogida así afirma que las rutas que anuncian algo son dos
segundos más lentas de lo que son.

Y eso **parece una respuesta**. Es el mismo error que `rutas_sin_llamante.py`
evita con su grupo de control: un medidor ciego da números con la misma cara
que un medidor que ve. La primera versión de esta línea base se recogió sin
Reverb y las tres rutas más lentas del informe eran las tres que más anuncian.

## Cómo repetirla

```bash
php artisan serve --port=8099                          # en una terminal
php artisan reverb:start --port=8080 --hostname=127.0.0.1   # en otra
python scripts/linea_base_de_latencia.py               # y en otra
```

`LINEA_BASE_VUELTAS` cambia el número de recorridos. Menos vueltas, menos
rutas con percentil.

---

## Qué se vio en la primera recogida

Las 22 vueltas salieron bien y **las 110 rutas llegan a las 20 muestras**, así
que todas tienen percentil. En conjunto: **p50 56,9 ms, p95 542 ms, p99
762,8 ms, cero respuestas 5xx** en 3168 peticiones.

Ese p50 de 57 ms es la mitad buena. Lo interesante está en las cinco rutas de
arriba, y es una sola observación:

**Las escrituras del pedido pasan la mayor parte de su tiempo FUERA de la base
de datos.** La columna `base` del informe es la suma de las consultas, y la
diferencia con `p50` es todo lo demás:

| ruta | p50 | en base de datos | el resto |
|---|---|---|---|
| `PUT /orders/{id}/cancel` | 754 ms | **51 ms** | ~700 ms |
| `PUT /negocio/pedidos/estado` | 528 ms | **29 ms** | ~500 ms |
| `POST /orders/orders` | 556 ms | **48 ms** | ~508 ms |

Esto es exactamente lo contrario de donde se buscaría por instinto: las tres
hacen entre 28 y 40 consultas, que suena a mucho, y las consultas no son el
problema.

**Un anuncio cuesta 204 ms, medido.** Con Reverb en marcha y en caliente,
cinco `broadcast()` seguidos dieron 203, 204, 204, 211 y 216 ms. Seis de los
siete eventos de la plataforma son `ShouldBroadcastNow`, así que esa espera
ocurre dentro de la petición.

**Y eso explica un tercio, no el total.** Fui a contar los anuncios del camino
de cancelar esperando encontrar tres, y hay **uno**: 204 de los 700 ms. Los
avisos push van en cola —`EnviarAvisoPush implements ShouldQueue`— así que
despacharlos es un INSERT, no una espera. **Los otros 500 ms no están
explicados.**

No los invento aquí. Lo que se puede afirmar con esta medida es:

- no es la base de datos (51 ms de 754);
- no es el arranque de PHP sin opcache, porque entonces una lectura barata
  también costaría medio segundo y `GET /negocio/pedidos` sale en 127 ms;
- no son los avisos push, que están en cola;
- un anuncio son 204 ms de los 700.

Averiguar el resto pide un perfilado del camino de cancelar, que es otro
trabajo y no este: esta línea base mide por ruta, no por dentro de una ruta.
**Queda como la pregunta que la línea base dejó abierta**, que es para lo que
sirve una línea base.

**La más lenta de todas no es nuestra.** `GET /bold/status/{ref}` sale a
1528 ms de p50 con **3 consultas**: es la llamada a la pasarela por la red. El
tope está puesto —`BOLD_TIMEOUT`, de la tanda de resiliencia— y 1,5 s es lo
que tarda Bold en contestar desde esta máquina, no algo que se pueda arreglar
desde aquí.

---

## El informe, tal como salió el 11 de noviembre de 2026

```
================================================================================
LATENCIA POR RUTA
================================================================================

Entorno: local
Medida encendida: si
Muestreo: todas las peticiones
Ficheros leidos: 1 de 7 dias

EN CONJUNTO  (3168 peticiones, 110 rutas)
--------------------------------------------------------------------------------
  p50 56.9 ms   p95 542.0 ms   p99 762.8 ms   max 1944.7 ms
  respuestas 5xx: 0

POR RUTA  (las 25 peores por p95)
--------------------------------------------------------------------------------
       n      p50      p95      p99      max   cons   base  ruta
      22   1528.2   1626.3   1944.7   1944.7      3   21.7  GET v1/bold/status/{ref}
      66    754.5    775.7    789.5    789.5     39   50.9  PUT v1/orders/{id}/cancel
      66    528.5    747.6    762.8    762.8     28   29.5  PUT v1/negocio/pedidos/estado
      66    556.2    589.3    642.1    642.1     40   48.4  POST v1/orders/orders
      44     71.8    558.5    626.6    626.6     31   30.1  PUT v1/orders/update
      44     65.3    542.5    543.0    543.0     19   25.1  POST v1/chats/send-message
      22    319.4    369.5    396.4    396.4     27   39.0  GET v1/domiciliaries/pedidos
      22    315.1    368.4    390.2    390.2      5   20.5  POST v1/register
      44     14.3    346.7    381.4    381.4     19    0.0  POST v1/orders/user
      66    213.0    238.4    250.0    250.0      4   22.4  POST v1/login
      22    200.3    225.1    265.9    265.9      8   24.9  GET v1/negocio/productos/cargas/plantilla
      22    132.1    161.2    165.6    165.6     20   30.9  GET v1/negocio/domiciliarios/{id}
      22    127.8    151.3    152.8    152.8     21   31.3  GET v1/negocio/pedidos
      22    100.4    121.3    135.6    135.6      6   36.4  POST v1/negocio/productos/cargas
      22     90.7    112.1    129.8    129.8     12   23.9  POST v1/businesses/show
      22     59.4    106.3    173.1    173.1      6   29.6  DELETE v1/admin/marketing/coupons/{id}
      22     47.9    105.5    127.8    127.8      5   23.9  GET v1/conjunto/porteria/entradas
      22     80.9    101.7    106.7    106.7     11   36.8  POST v1/negocio/productos
      22     83.3     94.9    118.3    118.3      6   24.1  GET v1/top-businesses-free
      66     47.2     94.4    134.1    134.1     44   22.6  GET v1/admin/overview
      44     64.2     88.6    101.8    101.8      8   27.0  GET v1/businesses/index
      44     79.2     87.6     92.0     92.0      9   39.8  PUT v1/negocio/me/recibiendo
      66     71.8     87.0     91.9     91.9     10   26.3  POST v1/favorites/index
      22     75.5     86.4     91.2     91.2     10   24.5  PUT v1/negocio/productos/{id}
      22     71.5     86.1    119.9    119.9      4   22.5  POST v1/conjunto/porteria/verificar

COMO LEER LAS COLUMNAS
--------------------------------------------------------------------------------
  cons  consultas de la peticion que mas hizo. Si crece con el tamanio de
        la respuesta, es un N+1.
  base  mediana de milisegundos EN LA BASE. Si se parece al p50, la ruta se
        arregla con un indice o con una precarga; si es una fraccion, el
        tiempo se va en otra cosa (serializar, una llamada HTTP, imagenes).
```
