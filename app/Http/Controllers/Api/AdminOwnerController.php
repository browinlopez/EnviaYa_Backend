<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\TypeDocumentIdentification;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Http\Request;
use App\Traits\ValidateVerificationDigit;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class AdminOwnerController extends Controller
{
    use ValidateVerificationDigit;
    /* =======================
     * INDEX
     * ======================= */
    public function index()
    {
        $owners = Owner::with(['user', 'businesses'])
            ->orderByDesc('owner_id')
            ->get();

        $businesses = Business::orderBy('name')->get();

        return view('admin.owners.index', compact('owners', 'businesses'));
    }
    /* =======================
     * CREATE
     * ======================= */
    public function create()
    {
        return view('admin.owners.create', [
            'users' => User::all(),
            'TypeDocumentIdentifications' => TypeDocumentIdentification::orderBy('name_es')->get()
        ]);
    }
    /* =======================
     * STORE
     * ======================= */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id|unique:owner,user_id',
            'type_document_identification_id' => 'nullable|exists:document_types,id',
            'document_number' => 'required|string|max:50',
            'verification_digit' => 'nullable|string|max:1',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'birthdate' => 'nullable|date',
            'contact_secondary' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'state' => 'required|boolean',
            'profile_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Validación del Dígito de Verificación (DV) para NIT
        if ($validated['type_document_identification_id'] == 5 && !empty($validated['verification_digit'])) {
            $expectedDV = $this->ValidateVerificationDigit($validated['document_number']);
            if ($expectedDV !== false && $validated['verification_digit'] != $expectedDV) {
                return back()->withErrors(['verification_digit' => "El dígito de verificación no es válido. El esperado es: $expectedDV"])->withInput();
            }
        }

        $owner = Owner::create($request->except('profile_photo'));

        if ($request->hasFile('profile_photo')) {
            $fileName = 'owner_' . $owner->owner_id . '_' . Str::slug($owner->user->name) . '.' .
                $request->file('profile_photo')->getClientOriginalExtension();

            $request->file('profile_photo')
                ->storeAs('owner', $fileName, 'public');

            $owner->update(['profile_photo' => $fileName]);
        }

        return redirect()
            ->route('admin.owners.index')
            ->with('success', 'Owner creado correctamente');
    }
    /* =======================
     * EDIT
     * ======================= */
    public function edit(Owner $owner)
    {
        return view('admin.owners.edit', [
            'owner' => $owner,
            'TypeDocumentIdentifications' => TypeDocumentIdentification::orderBy('name_en')->get()
        ]);
    }
    /* =======================
     * UPDATE
     * ======================= */
    public function update(Request $request, Owner $owner)
    {
        $validated = $request->validate([
            'type_document_identification_id' => 'nullable|exists:document_types,id',
            'document_number' => 'required|string|max:50',
            'verification_digit' => 'nullable|string|max:1',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'birthdate' => 'nullable|date',
            'contact_secondary' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'state' => 'required|boolean',
            'profile_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Validación del Dígito de Verificación (DV) para NIT
        if ($validated['type_document_identification_id'] == 5 && !empty($validated['verification_digit'])) {
            $expectedDV = $this->ValidateVerificationDigit($validated['document_number']);
            if ($expectedDV !== false && $validated['verification_digit'] != $expectedDV) {
                return back()->withErrors(['verification_digit' => "El dígito de verificación no es válido. El esperado es: $expectedDV"])->withInput();
            }
        }

        $owner->update($request->except('profile_photo'));

        if ($request->hasFile('profile_photo')) {

            // 🗑️ borrar imagen anterior
            if ($owner->profile_photo) {
                Storage::disk('public')->delete('owner/' . $owner->profile_photo);
            }

            $fileName = 'owner_' . $owner->owner_id . '_' . Str::slug($owner->user->name) . '.' .
                $request->file('profile_photo')->getClientOriginalExtension();

            $request->file('profile_photo')
                ->storeAs('owner', $fileName, 'public');

            $owner->update(['profile_photo' => $fileName]);
        }

        return redirect()
            ->route('admin.owners.index')
            ->with('success', 'Owner actualizado correctamente');
    }

    public function syncBusinesses(Request $request, Owner $owner)
    {
        $request->validate([
            'businesses' => 'array'
        ]);

        $syncData = [];

        foreach ($request->businesses ?? [] as $businessId) {
            $syncData[$businessId] = ['state' => 1];
        }

        $owner->businesses()->sync($syncData);

        return response()->json([
            'success' => true,
            'message' => 'Negocios asignados correctamente'
        ]);
    }
}
