<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * LO QUE CONTESTÓ BOLD CUANDO NO SALIÓ BIEN.
 *
 * Antes, las siete llamadas de `BoldService` hacían
 * `throw new \Exception($response->body())`: una excepción genérica cuyo
 * mensaje era el JSON crudo de la pasarela, y nada más. Quien la atrapaba no
 * podía distinguir «esa referencia no existe» —culpa del cliente, y Bold lo
 * dice con un 404 limpio, medido— de «la pasarela se cayó»: todo acababa en
 * el mismo 500, con el cuerpo de Bold viajando hasta el teléfono dentro de
 * `message`.
 *
 * EL MENSAJE SIGUE SIENDO EL CUERPO, Y ESO NO ES DESCUIDO.
 *
 * Es lo primero que se pensó quitar, y habría roto algo: `OrderController`
 * decide qué se le dice al comprador buscando `MCFG_004` y `Not Available`
 * DENTRO del mensaje de la excepción (líneas 522-523). Cambiar el mensaje
 * hace que esa detección deje de encontrar nada —sin error, sin aviso— y el
 * comprador reciba un texto genérico donde antes recibía el que explicaba que
 * la pasarela no estaba disponible.
 *
 * Así que esta clase es ADITIVA: el mensaje queda EXACTAMENTE como estaba,
 * cada sitio con la forma que ya tenía, y encima aparecen el estado y el
 * código para que quien quiera decidir mejor, pueda. Mover ese `str_contains`
 * a `codigo()` es un cambio que vale la pena y es otro, con su propia
 * comprobación de qué ve la app publicada.
 */
class FalloDeLaPasarela extends RuntimeException
{
    private function __construct(
        private readonly int $estado,
        private readonly ?string $codigo,
        string $mensaje,
    ) {
        parent::__construct($mensaje);
    }

    /**
     * Con el cuerpo crudo como mensaje, que es lo que hacían seis de los
     * siete sitios.
     */
    public static function desde(Response $respuesta): self
    {
        return new self(
            $respuesta->status(),
            self::codigoDe($respuesta),
            (string) $respuesta->body(),
        );
    }

    /**
     * Con el JSON vuelto a codificar, que es lo que hacía `makePayment`.
     *
     * Se conserva la diferencia aunque parezca gratuita: `body()` devuelve los
     * bytes tal como llegaron y `json_encode(json())` los normaliza —espacios,
     * orden, escapes—. Son dos cadenas distintas para la misma respuesta, y
     * una de ellas es la que el registro de pagos lleva guardando.
     */
    public static function desdeJson(Response $respuesta): self
    {
        return new self(
            $respuesta->status(),
            self::codigoDe($respuesta),
            (string) json_encode($respuesta->json()),
        );
    }

    private static function codigoDe(Response $respuesta): ?string
    {
        $cuerpo = $respuesta->json();

        if (! is_array($cuerpo)) {
            return null;
        }

        return $cuerpo['payload']['status_code'] ?? ($cuerpo['status_code'] ?? null);
    }

    /** El estado que devolvió BOLD, no el que vamos a devolver nosotros. */
    public function estado(): int
    {
        return $this->estado;
    }

    /** El código propio de Bold: `PA_004` para una referencia que no existe. */
    public function codigo(): ?string
    {
        return $this->codigo;
    }

    /**
     * Si Bold dice que eso no existe, no es un fallo nuestro.
     *
     * Se mira el estado HTTP y no el texto: Bold contesta un 404 limpio
     * —comprobado contra la pasarela de pruebas— y emparejar por el `PA_004`
     * o por «not found» ataría esto a una cadena que ellos pueden cambiar.
     */
    public function noExiste(): bool
    {
        return $this->estado === 404;
    }
}
