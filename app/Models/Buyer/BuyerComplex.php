<?php

declare(strict_types=1);

namespace App\Models\Buyer;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerComplex extends Audit
{
    protected $table = 'buyer_complex';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = ['buyer_id', 'complex_id'];

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'buyer_id', 'buyer_id');
    }

    /** @return BelongsTo<ResidentialComplex, $this> */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(ResidentialComplex::class, 'complex_id', 'complex_id');
    }

    /** @return BelongsTo<ResidentialComplex, $this> */
    public function residentialComplex(): BelongsTo
    {
        return $this->belongsTo(ResidentialComplex::class, 'complex_id', 'complex_id');
    }
}
