# El arnés: los 33 procesos, contra el backend en marcha

```bash
php artisan serve --port=8099          # en otra terminal
python scripts/arnes/correr.py         # los 33
python scripts/arnes/correr.py p_tendero   # solo unos
```

Sale `0` si los 33 pasan **y** no hay hallazgos. Sale `1` en cuanto hay uno de
los dos.

---

## Por qué existe, si ya hay 1190 pruebas

Porque las 1190 estaban en verde mientras **ninguna llamada a Bold funcionaba**
—`peticion()` se llamaba a sí misma y cada pago mataba el proceso por memoria
agotada— y mientras noventa y cuatro trabajos llevaban dos meses en la cola sin
que nadie los sacara.

Ninguna de las dos cosas es un fallo de lógica. Una era una respuesta
equivocada; la otra, una ausencia. Las pruebas comprueban lo que alguien se
acordó de comprobar; esto **recorre el sistema** y mira qué contesta.

Los tres hallazgos de la primera pasada están arreglados y cada uno tiene su
prueba en la suite. Lo que queda aquí es la herramienta.

## Qué hace, exactamente

Levanta un token por rol contra la base de desarrollo —eligiendo a la gente por
lo que **puede** hacer, no por su número de rol: el tendero tiene que ser dueño
del negocio 1— y recorre:

| Archivo | Procesos |
|---|---|
| `p_comprador.py` | cuenta, claves, vitrina, catálogo, favoritos, cotización, pedido en efectivo completo, cancelación, autorización |
| `p_tendero.py` | su negocio, tablero, productos, promociones, efectivo y crédito, domiciliarios, carga de catálogo `.xls` |
| `p_resto.py` | domiciliario, portería, chat, avisos, reseñas, conjunto, panel interno, informes, marketing, pasarela, webhook, comandos del cron, respaldo, mantenimiento |

No se queda en el código de respuesta: comprueba **efectos**. Que el inventario
baje al pedir y vuelva al cancelar, que el comprobante se emita, que queden las
filas de auditoría, que el expediente de carga avance cuando corre el
trabajador.

## Lo que NO toca, a propósito

- **El logo de un negocio.** Subirlo cambiaría lo que ve la gente de verdad.
- **El interruptor de mantenimiento.** Encenderlo a medias deja la API parada;
  eso lo cubre `MantenimientoTest` en la suite.
- **Nada en producción.** Habla con `127.0.0.1` y con la base de desarrollo.

Sí deja rastro en esa base: pedidos de prueba, cuentas `arnes+…@ejemplo.test`,
expedientes de carga. Es deliberado —un pedido creado y cancelado de verdad
prueba más que uno simulado— pero conviene saberlo.

Levántalo con `BROADCAST_CONNECTION=log` si no quieres que los eventos salgan
hacia el Reverb de producción.

## Dos cosas que hay que saber antes de tocarlo

**Un hallazgo no es un fallo del arnés.** Son dos columnas distintas en el
informe y la diferencia importa: en la primera pasada, cinco de los ocho fallos
eran míos —parámetros con otro nombre, verbos equivocados, una tabla que se
llama de otra forma— y tres eran del backend. Mezclarlos habría escondido los
tres. Si algo sale rojo, la primera pregunta es «¿estoy pidiendo bien?».

**Un detector lleva su pasada de control.** El guardia de llamadas HTTP sin
tope de tiempo encontró el fallo más grave de todos por una línea que exige que
encuentre lo que ya se sabe que está: devolvía cinco llamadas y ninguna era de
`BoldService`, que tiene siete. **La ausencia era el síntoma.** Cualquier
comprobación que se añada aquí debería poder fallar por la razón correcta.

## Los tropiezos que ya están pagados

Están anotados en el código donde ocurrieron, para no repetirlos:

- `sql()` envuelve su JSON entre marcas propias. Buscar el primer `[` de la
  salida de `tinker` acaba dentro de un código de color ANSI.
- `queue:work --once --stop-when-empty` **no procesa nada**. Y `--once` solo
  saca el trabajo más viejo, así que con noventa delante el que interesa no
  llega nunca: para vaciar, `--stop-when-empty` a secas.
- Los correos se leen del transporte `array`, no de `Mail::fake()`:
  `Mail::raw` no construye un Mailable y el doble cuenta Mailables.
- `jobs.created_at` es un entero de Unix, no una fecha.
- Los ayudantes con nombre repetido entre archivos son un `Cannot redeclare`:
  Pest carga todos en el mismo proceso.
