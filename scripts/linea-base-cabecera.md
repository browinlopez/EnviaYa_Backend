# Línea base de latencia del arnés

> **Esto no es latencia de producción.** Léelo antes de usar un número de aquí
> para decidir algo.

Lo genera `python scripts/linea_base_de_latencia.py`, que vacía el registro de
latencia, recorre el arnés **{vueltas} veces** y resume lo que midió
`MedidaDeLaPeticion`.

| | |
|---|---|
| peticiones | **{peticiones}** |
| rutas con muestras | **{rutas}** |
| rutas que llegan a {minimo} muestras y por tanto tienen percentil | **{con_percentil}** |

## Qué es

Una **referencia contra sí misma**. §25 del estándar pide percentiles por
endpoint; la herramienta de medida existe desde la tanda de observabilidad y
lo que falta es tráfico: con un solo recorrido casi ninguna ruta llega a las
{minimo} muestras que hacen honesto un percentil, y el informe las deja en
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

## El informe, tal como salió el {fecha}
