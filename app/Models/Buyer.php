<?php

namespace App\Models;

use App\Traits\ValidateVerificationDigit;
use Illuminate\Database\Eloquent\Model;

class Buyer extends Audit
{
    use ValidateVerificationDigit;
    public $timestamps = false;

    protected $fillable = ['user_id', 'qualification', 'state', "belongs_to_complex", 'verification_digit', 'municipality_id', 'type_document_identification_id', 'identification_number', 'type_organization_id'];
    protected $casts = [
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function TypeDocumentIdentification()
    {
        return $this->belongsTo(\App\Models\TypeDocumentIdentification::class, 'type_document_identification_id', 'id');
    }

    public function typeOrganization()
    {
        return $this->belongsTo(TypeOrganization::class, 'type_organization_id', 'id');
    }

    public function municipality()
    {
        return $this->belongsTo(\App\Models\Municipality::class, 'municipality_id', 'id');
    }

    public function businessReviews()
    {
        return $this->hasMany(BusinessReview::class, 'buyer_id', 'id');
    }

    public function domiciliaryReviews()
    {
        return $this->hasMany(DomiciliaryReview::class, 'buyer_id', 'id');
    }

    public function complexes()
    {
        return $this->hasMany(BuyerComplex::class, 'buyer_id', 'id');
    }

    public function residentialComplexes()
    {
        return $this->belongsToMany(
            ResidentialComplex::class,
            'buyer_complexes',     // tabla pivote
            'buyer_id',          // FK en pivote
            'complex_id'         // FK en pivote
        );
    }
}
