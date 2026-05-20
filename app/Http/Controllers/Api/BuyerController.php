<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerComplex;
use App\Models\ResidentialComplex;
use App\Models\User;
use App\Models\TypeOrganization;
use App\Models\TypeDocumentIdentification;
use App\Traits\ValidateVerificationDigit;
use App\Http\Requests\Api\StoreBuyerRequest;
use App\Http\Requests\Api\UpdateBuyerRequest;
use Illuminate\Http\Request;

class BuyerController extends Controller
{
    use ValidateVerificationDigit;

    public function index()
    {
        $buyers = Buyer::with(['user', 'residentialComplexes', 'typeOrganization'])->paginate(10);
        return view('admin.compradores.index', compact('buyers'));
    }

    public function create()
    {
        $users = User::all();
        $complexes = ResidentialComplex::all();
        $organizations = TypeOrganization::all();
        $documentTypes = TypeDocumentIdentification::all();
        return view('admin.compradores.create', compact('users', 'complexes', 'organizations', 'documentTypes'));
    }

    public function store(StoreBuyerRequest $request)
    {
        $validated = $request->validated();

        // Validación manual del Dígito de Verificación (DV) para NIT
        if ($validated['type_document_identification_id'] == 5 && !empty($validated['verification_digit'])) {
            $expectedDV = $this->ValidateVerificationDigit($validated['identification_number']);
            if ($expectedDV !== false && $validated['verification_digit'] != $expectedDV) {
                return back()->withErrors(['verification_digit' => "El dígito de verificación no es válido. El esperado es: $expectedDV"])->withInput();
            }
        }

        // Crear comprador
        $buyer = Buyer::create([
            'user_id' => $validated['user_id'],
            'qualification' => $validated['qualification'] ?? null,
            'state' => $validated['state'],
            'belongs_to_complex' => $validated['belongs_to_complex'],
            'type_document_identification_id' => $validated['type_document_identification_id'],
            'identification_number' => $validated['identification_number'],
            'verification_digit' => $validated['verification_digit'],
            'municipality_id' => $validated['municipality_id'],
            'type_organization_id' => $validated['type_organization_id'],
        ]);

        // Crear relación con complejo si aplica
        if ($validated['belongs_to_complex'] && !empty($validated['complex_id'])) {
            BuyerComplex::create([
                'buyer_id' => $buyer->id,
                'complex_id' => $validated['complex_id'],
            ]);
        }

        return redirect()->route('admin.compradores.index')->with('success', 'Comprador creado correctamente');
    }

    public function edit(Buyer $compradore)
    {
        $users = User::all();
        $complexes = ResidentialComplex::all();
        $organizations = TypeOrganization::all();
        $documentTypes = TypeDocumentIdentification::all();
        return view('admin.compradores.edit', compact('compradore', 'users', 'complexes', 'organizations', 'documentTypes'));
    }

    public function update(UpdateBuyerRequest $request, $id)
    {
        $validated = $request->validated();

        // Validación manual del Dígito de Verificación (DV) para NIT
        if ($validated['type_document_identification_id'] == 5 && !empty($validated['verification_digit'])) {
            $expectedDV = $this->ValidateVerificationDigit($validated['identification_number']);
            if ($expectedDV !== false && $validated['verification_digit'] != $expectedDV) {
                return back()->withErrors(['verification_digit' => "El dígito de verificación no es válido. El esperado es: $expectedDV"])->withInput();
            }
        }

        $buyer = Buyer::with('user')->findOrFail($id);

        // Actualizar comprador
        $buyer->update([
            'qualification' => $validated['qualification'] ?? null,
            'state' => $validated['state'],
            'belongs_to_complex' => $validated['belongs_to_complex'],
            'type_document_identification_id' => $validated['type_document_identification_id'],
            'identification_number' => $validated['identification_number'],
            'verification_digit' => $validated['verification_digit'],
            'municipality_id' => $validated['municipality_id'],
            'type_organization_id' => $validated['type_organization_id'],
        ]);

        // Actualizar usuario asociado
        if ($buyer->user) {
            $buyer->user->update([
                'name' => $request->user_name,
                'email' => $request->user_email,
                'phone' => $request->user_phone,
                'address' => $request->user_address,
            ]);
        }

        // Actualizar relación con complejo
        BuyerComplex::where('buyer_id', $buyer->id)->delete();

        if ($validated['belongs_to_complex'] && !empty($validated['complex_id'])) {
            BuyerComplex::create([
                'buyer_id' => $buyer->id,
                'complex_id' => $validated['complex_id'],
            ]);
        }

        return redirect()->route('admin.compradores.index')->with('success', 'Comprador actualizado correctamente');
    }

    public function destroy(Buyer $compradore)
    {
        $compradore->delete();
        return redirect()->route('admin.compradores.index')->with('success', 'Comprador eliminado');
    }
}
