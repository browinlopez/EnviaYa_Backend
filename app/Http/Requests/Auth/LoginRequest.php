<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        /*
         * `(string)` Y NO `$this->string(...)` A SECAS.
         *
         * `$this->string('email')` devuelve un `Stringable`, no una cadena, y
         * `Str::lower()` documenta `@param string`. Lo encontró PHPStan al
         * poner `declare(strict_types=1)` en los 433 ficheros de `app/`.
         *
         * Y AQUÍ VA LA PARTE HONESTA, porque la primera versión de este
         * comentario decía que sin el `(string)` la llamada **lanzaba** un
         * TypeError, y eso es falso. Fui a comprobarlo devolviendo el fallo:
         * no lanza. El motivo es que `Str::lower($value)` **no declara tipo
         * nativo** —solo lo dice en su docblock—, así que PHP no tiene nada
         * que comprobar y `mb_strtolower()` convierte el objeto por su
         * `__toString()`. Con una función que SÍ declarara `string` el modo
         * estricto sí lanzaría: comprobado aparte, `strict_types` rechaza un
         * `Stringable` donde se declara `string`.
         *
         * O sea que esto era un desajuste ESTÁTICO, no un fallo en
         * producción. Se arregla igual —pasar el tipo que la función dice
         * esperar cuesta un molde y deja de depender de que su firma siga sin
         * tipar— pero no se vende como un fallo evitado.
         *
         * Lo que sí es un hallazgo: no lo vio ninguna de las 1247 pruebas ni
         * los 33 procesos del arnés, y no por casualidad. Esta clase sirve a
         * `POST /login`, la sesión web que trae Breeze; lo que la plataforma
         * usa es `POST /v1/login`, que atiende `AuthController@login` y no
         * pasa por aquí. El camino existe, está enrutado y nadie lo prueba.
         */
        return Str::transliterate(Str::lower((string) $this->string('email')).'|'.$this->ip());
    }
}
