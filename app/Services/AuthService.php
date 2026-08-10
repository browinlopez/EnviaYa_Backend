<?php

namespace App\Services;

use App\Models\User;
use App\Models\Buyer;
use App\Models\BuyerComplex;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\VerifyEmailCustomMail;
use App\Traits\ValidateVerificationDigit;

class AuthService
{
    use ValidateVerificationDigit;
    /**
     * Registra un nuevo usuario y su perfil de comprador.
     */
    public function register(array $data): void
    {
        $typeOrg = $data['type_organization_id'] ?? null;
        $nit = $data['identification_number'] ?? null;
        $dv = $data['verification_digit'] ?? null;

        if ($typeOrg == 1) {
            if (is_null($dv) || $dv === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'verification_digit' => ['El dígito de verificación es obligatorio para personas jurídicas.']
                ]);
            }

            $correctDv = $this->ValidateVerificationDigit($nit);
            if ($correctDv !== false && $correctDv != $dv) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'verification_digit' => ["El dígito de verificación es incorrecto. El correcto debe ser: {$correctDv}."]
                ]);
            }
        }

        DB::transaction(function () use ($data, $dv) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'rol_id' => 1,
                'state' => true,
                'email_verification_token' => Str::random(60),
                'email_verification_expires_at' => Carbon::now()->addMinutes(60),
            ]);

            $buyer = Buyer::create([
                'user_id' => $user->id,
                'qualification' => 0.00,
                'state' => true,
                'belongs_to_complex' => $data['belongs_to_complex'] ?? false,
                'type_organization_id' => $data['type_organization_id'],
                'type_document_identification_id' => $data['type_document_identification_id'] ?? null,
                'identification_number' => $data['identification_number'] ?? null,
                'verification_digit' => $dv,
                'municipality_id' => $data['municipality_id'] ?? null,
            ]);

            if (!empty($data['belongs_to_complex']) && !empty($data['complex_id'])) {
                BuyerComplex::create([
                    'buyer_id' => $buyer->id,
                    'complex_id' => $data['complex_id'],
                ]);
            }

            $this->sendVerificationEmail($user);
        });
    }

    /**
     * Verifica el correo electrónico del usuario.
     */
    public function verify(string $token): array
    {
        $user = User::where('email_verification_token', $token)->first();

        if (!$user) {
            return ['success' => false, 'error' => 'invalid'];
        }

        if ($user->email_verified_at) {
            return ['success' => true, 'already_verified' => true];
        }

        if (!$user->email_verification_expires_at || $user->email_verification_expires_at->isPast()) {
            return ['success' => false, 'error' => 'expired'];
        }

        $user->update([
            'email_verified_at' => Carbon::now(),
            'email_verification_token' => null,
            'email_verification_expires_at' => null,
        ]);

        return ['success' => true, 'already_verified' => false];
    }

    /**
     * Reenvía el correo de verificación.
     */
    public function resendVerification(string $email): string
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return 'Si el correo existe, se enviará un enlace de verificación.';
        }

        if ($user->email_verified_at) {
            return 'El correo ya está verificado.';
        }

        if (!$user->email_verification_token || !$user->email_verification_expires_at || $user->email_verification_expires_at->isPast()) {
            $user->update([
                'email_verification_token' => Str::random(60),
                'email_verification_expires_at' => now()->addMinutes(60),
            ]);
        }

        $this->sendVerificationEmail($user);

        return 'Si el correo existe, se ha enviado el enlace de verificación.';
    }

    /**
     * Inicia sesión de un usuario y devuelve el token.
     */
    public function login(array $credentials): array
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new Exception('Credenciales incorrectas', 401);
        }

        if (!$user->hasVerifiedEmail()) {
            throw new Exception('Debes verificar tu correo antes de iniciar sesión', 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        if ($user->rol_id == 1) {
            $user->load('buyer');
        } elseif ($user->rol_id == 2) {
            $user->load(['owner.businesses']);
        } elseif ($user->rol_id == 3) {
            $user->load(['domiciliary.businesses']);
        }

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * Cierra la sesión del usuario.
     */
    public function logout(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Envía enlace de restablecimiento de contraseña.
     */
    public function sendResetLink(string $email): bool
    {
        $status = Password::sendResetLink(['email' => $email]);
        return $status === Password::RESET_LINK_SENT;
    }

    /**
     * Confirma el restablecimiento: valida el token y guarda la nueva contraseña.
     * Devuelve el status de Password::reset para que el controller decida la respuesta.
     */
    public function resetPasswordWithToken(array $credentials): string
    {
        return Password::reset(
            $credentials,
            function (User $user) use ($credentials) {
                $user->forceFill([
                    'password' => Hash::make($credentials['password']),
                ])->save();
            }
        );
    }

    /**
     * Reenvía correo de verificación para Web (Blade flow).
     */
    public function resendVerificationWeb(string $email): array
    {
        $user = User::where('email', $email)->first();
        if (!$user) {
            return ['status' => 'error', 'message' => 'Si el correo existe, se enviará un enlace de verificación.'];
        }

        if ($user->email_verified_at) {
            return ['status' => 'already_verified', 'message' => 'Tu correo ya está verificado.'];
        }

        $user->update([
            'email_verification_token' => Str::random(60),
            'email_verification_expires_at' => now()->addMinutes(60),
        ]);

        $this->sendVerificationEmail($user);

        return ['status' => 'success', 'message' => 'Se ha enviado un nuevo correo de verificación.'];
    }

    /**
     * Envía el correo electrónico de verificación.
     */
    private function sendVerificationEmail(User $user): void
    {
        $actionUrl = config('app.url')
            . '/verify-email?token='
            . $user->email_verification_token;

        Mail::to($user->email)->send(
            new VerifyEmailCustomMail($actionUrl)
        );
    }
}
