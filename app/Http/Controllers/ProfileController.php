<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        DB::transaction(function () use ($user) {
            /*
             * LA FICHA DE COMPRADOR NO SE VA CON LA CUENTA.
             *
             * `owner` y `domiciliary` tienen su foránea a `user` en CASCADE;
             * `buyer` la tiene en SET NULL. Así que al borrar la cuenta su fila
             * de comprador se quedaba con `user_id = NULL` y sin forma de
             * encontrarla después: no hay por dónde llegar a ella. En la base
             * local quedaron cinco así.
             *
             * No se cambia la foránea a propósito: `orderssales.buyer_id`
             * también es SET NULL, así que conservar la fila —vacía de
             * identidad— es lo que evita que un pedido pasado se quede sin
             * saber a qué comprador fue. Eso importa para los comprobantes.
             *
             * Se retira solo la que NO tiene historial: sin pedidos y sin
             * vínculo a un conjunto. Esa no le sirve a nadie.
             */
            $buyerId = DB::table('buyer')
                ->where('user_id', $user->user_id)
                ->value('buyer_id');

            $tieneHistorial = $buyerId && (
                DB::table('orderssales')->where('buyer_id', $buyerId)->exists()
                || DB::table('buyer_complex')->where('buyer_id', $buyerId)->exists()
            );

            if ($buyerId && !$tieneHistorial) {
                DB::table('buyer')->where('buyer_id', $buyerId)->delete();
            }

            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
