<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\CategoryBusiness;
use App\Models\Domiciliary;
use App\Models\Product;
use App\Models\User;
use App\Services\BusinessService;
use App\Http\Requests\Api\StoreBusinessRequest;
use App\Http\Requests\Api\UpdateBusinessRequest;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    protected $businessService;

    public function __construct(BusinessService $businessService)
    {
        $this->businessService = $businessService;
    }

    /**
     * Display a listing of the resource (both API and Web Admin).
     */
    public function index(Request $request)
    {
        // 1. Si es una petición API o espera JSON
        if ($request->expectsJson() || $request->is('api/*')) {
            $userId = $request->input('user_id');
            $affiliatedIds = [];

            if ($userId) {
                $request->validate([
                    'user_id' => 'integer|exists:users,id',
                ]);

                $user = User::with('affiliatedBusinesses')->findOrFail($userId);
                $affiliatedIds = $user->affiliatedBusinesses->pluck('busines_id')->toArray();
            }

            $businesses = Business::with(['owners', 'municipality', 'products', 'reviews', 'category'])
                ->when(count($affiliatedIds) > 0, function ($q) use ($affiliatedIds) {
                    $q->orderByRaw("FIELD(busines_id," . implode(',', $affiliatedIds) . ") DESC");
                })
                ->orderBy('name')
                ->get();

            $formatted = $businesses->map(function ($business) use ($affiliatedIds) {
                return [
                    'business_id' => $business->busines_id,
                    'name' => $business->name,
                    'phone' => $business->phone,
                    'address' => $business->address,
                    'qualification' => (float) $business->qualification,
                    'legal_name' => $business->legal_name,
                    'type_organization_id' => $business->type_organization_id,
                    'identification_number' => $business->identification_number,
                    'verification_digit' => $business->verification_digit,
                    'logo' => $business->logo ?? 'https://example.com/default-logo.png',
                    'state' => (bool) $business->state,
                    'category' => $business->category ? [
                        'id' => $business->category->id,
                        'name' => $business->category->name,
                        'description' => $business->category->description,
                        'image' => $business->category->image,
                    ] : null,
                    'municipality' => $business->municipality ? [
                        'id' => $business->municipality->id,
                        'name' => $business->municipality->name,
                    ] : null,
                    'is_affiliated' => in_array($business->busines_id, $affiliatedIds),
                ];
            });

            return response()->json($formatted);
        }

        // 2. Si es una petición Web Admin (Blade view)
        $businesses = Business::with('category')->paginate(10);
        return view('admin.negocios.index', compact('businesses'));
    }

    /**
     * Show the form for creating a new resource (Web Admin only).
     */
    public function create()
    {
        $categories = CategoryBusiness::all();
        $products = Product::all();
        $domiciliaries = Domiciliary::all();
        $business = null;

        return view('admin.negocios.create', compact('categories', 'products', 'domiciliaries', 'business'));
    }

    /**
     * Store a newly created resource in storage (both API and Web Admin).
     */
    public function store(StoreBusinessRequest $request)
    {
        $data = $request->validated();
        $logoFile = $request->file('logo');

        try {
            $business = $this->businessService->store($data, $logoFile);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Negocio creado correctamente',
                    'business' => $business->load(['municipality', 'category'])
                ], 201);
            }

            return redirect()->route('admin.negocios.index')->with('success', 'Negocio creado');
        } catch (\Exception $e) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Error al crear el negocio',
                    'error' => $e->getMessage()
                ], 500);
            }

            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Display the specified resource (both API and Web Admin).
     */
    public function show(Request $request, $id = null)
    {
        $businessId = $id ?? $request->input('busines_id');

        if (!$businessId) {
            return response()->json(['message' => 'El id del negocio es requerido.'], 400);
        }

        $business = Business::with(['owners', 'products', 'reviews', 'municipality', 'category'])
            ->findOrFail($businessId);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'business_id' => $business->busines_id,
                'name' => $business->name,
                'phone' => $business->phone,
                'address' => $business->address,
                'qualification' => (float) $business->qualification,
                'razon_social' => $business->legal_name,
                'legal_name' => $business->legal_name,
                'type_organization_id' => $business->type_organization_id,
                'identification_number' => $business->identification_number,
                'verification_digit' => $business->verification_digit,
                'logo' => $business->logo ?? 'https://example.com/default-logo.png',
                'category_business_id' => $business->category_business_id,
                'category' => $business->category ? [
                    'id' => $business->category->id,
                    'name' => $business->category->name,
                    'description' => $business->category->description,
                    'image' => $business->category->image,
                ] : null,
                'state' => (bool) $business->state,
                'municipality' => $business->municipality ? [
                    'id' => $business->municipality->id,
                    'name' => $business->municipality->name,
                ] : null,
                'owner_count' => $business->owners->count(),
                'owners' => $business->owners->map(function ($owner) {
                    return [
                        'owner_id' => $owner->owner_id,
                        'user_id' => $owner->user_id,
                        'profile_photo' => $owner->profile_photo ?? 'https://example.com/default-user.png',
                        'document_type' => $owner->document_type,
                        'document_number' => $owner->document_number,
                        'birthdate' => $owner->birthdate,
                        'contact_secondary' => $owner->contact_secondary,
                        'notes' => $owner->notes,
                        'state' => (bool) $owner->state,
                    ];
                }),
                'products' => $business->products->map(function ($product) {
                    return [
                        'product_id' => $product->products_id,
                        'name' => $product->name,
                        'description' => $product->description,
                        'category_id' => $product->category_id,
                        'image' => $product->image,
                        'state' => (bool) $product->state,
                        'price' => $product->pivot->price ?? null,
                    ];
                }),
                'reviews' => $business->reviews->map(function ($review) {
                    return [
                        'review_id' => $review->reviews_id ?? null,
                        'buyer_id' => $review->buyer_id,
                        'rating' => (float) $review->qualification ?? 0,
                        'comment' => $review->comment ?? '',
                        'created_at' => $review->created_at ?? null,
                    ];
                }),
            ]);
        }

        return view('admin.negocios.show', compact('business'));
    }

    /**
     * Show the form for editing the specified resource (Web Admin only).
     */
    public function edit($id)
    {
        $business = Business::findOrFail($id);
        $categories = CategoryBusiness::all();
        $domiciliaries = Domiciliary::all();

        $business->load(['products', 'domiciliaries', 'category']);
        $businessProducts = $business->products;
        $allProducts = Product::all();

        return view('admin.negocios.edit', compact(
            'business',
            'categories',
            'domiciliaries',
            'businessProducts',
            'allProducts'
        ));
    }

    /**
     * Update the specified resource in storage (both API and Web Admin).
     */
    public function update(UpdateBusinessRequest $request, $id = null)
    {
        $data = $request->validated();
        $businessId = $id ?? $request->input('busines_id');

        if (!$businessId) {
            return response()->json(['message' => 'El id del negocio es requerido.'], 400);
        }

        $business = Business::findOrFail($businessId);
        $logoFile = $request->file('logo');

        try {
            $business = $this->businessService->update($business, $data, $logoFile);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Negocio actualizado correctamente',
                    'business' => $business->load(['owners', 'products', 'reviews', 'municipality', 'category'])
                ]);
            }

            return redirect()->route('admin.negocios.index')->with('success', 'Negocio actualizado');
        } catch (\Exception $e) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Error al actualizar el negocio',
                    'error' => $e->getMessage()
                ], 500);
            }

            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Remove the specified resource from storage (both API and Web Admin).
     */
    public function destroy(Request $request, $id = null)
    {
        $businessId = $id ?? $request->input('busines_id');

        if (!$businessId) {
            return response()->json(['message' => 'El id del negocio es requerido.'], 400);
        }

        $business = Business::findOrFail($businessId);

        try {
            $this->businessService->destroy($business);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Negocio eliminado correctamente'
                ]);
            }

            return back()->with('success', 'Negocio eliminado');
        } catch (\Exception $e) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Error al eliminar el negocio',
                    'error' => $e->getMessage()
                ], 500);
            }

            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * API specific: List top businesses sorted by qualification.
     */
    public function indexByQualification(Request $request)
    {
        $businesses = Business::with(['category', 'municipality'])
            ->orderByDesc('qualification')
            ->limit(10)
            ->get();

        $formatted = $businesses->map(function ($business) {
            return [
                'business_id' => $business->busines_id,
                'name' => $business->name,
                'phone' => $business->phone,
                'address' => $business->address,
                'qualification' => (float) $business->qualification,
                'legal_name' => $business->legal_name,
                'type_organization_id' => $business->type_organization_id,
                'identification_number' => $business->identification_number,
                'verification_digit' => $business->verification_digit,
                'logo' => $business->logo ?? 'https://example.com/default-logo.png',
                'state' => (bool) $business->state,
                'category_business_id' => $business->category_business_id,
                'category' => $business->category ? [
                    'id' => $business->category->id,
                    'name' => $business->category->name,
                    'description' => $business->category->description,
                    'image' => $business->category->image,
                ] : null,
                'municipality' => $business->municipality ? [
                    'id' => $business->municipality->id,
                    'name' => $business->municipality->name,
                ] : null,
            ];
        });

        return response()->json($formatted);
    }
}
