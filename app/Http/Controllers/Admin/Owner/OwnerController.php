<?php

namespace App\Http\Controllers\Admin\Owner;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\DocumentType;
use App\Models\Owner\Owner;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class OwnerController extends Controller
{
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
            'documentTypes' => DocumentType::orderBy('name_es')->get()
        ]);
    }
    /* =======================
     * STORE
     * ======================= */
    public function store(Request $request)
    {
        $request->validate([
            'user_id'           => 'required|exists:user,user_id|unique:owner,user_id',
            'document_type'     => 'nullable|string|max:20',
            'document_number'   => 'required|string|max:50',
            'birthdate'         => 'nullable|date',
            'contact_secondary' => 'nullable|string|max:50',
            'notes'             => 'nullable|string',
            'state'             => 'required|boolean',
            'profile_photo'     => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

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
            'documentTypes' => DocumentType::orderBy('name_en')->get()
        ]);
    }
    /* =======================
     * UPDATE
     * ======================= */
    public function update(Request $request, Owner $owner)
    {
        $request->validate([
            'document_type'     => 'nullable|string|max:20',
            'document_number'   => 'required|string|max:50',
            'birthdate'         => 'nullable|date',
            'contact_secondary' => 'nullable|string|max:50',
            'notes'             => 'nullable|string',
            'state'             => 'required|boolean',
            'profile_photo'     => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

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
