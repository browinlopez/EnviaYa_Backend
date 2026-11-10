<?php

namespace App\Models\Owner;

use App\Models\Business;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Owner extends Authenticatable implements AuditableContract
{
    use Auditable, HasApiTokens, HasFactory;

    protected $table = 'owner';

    protected $primaryKey = 'owner_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'state',
        'profile_photo',
        'document_type',
        'document_number',
        'birthdate',
        'contact_secondary',
        'notes',
    ];

    protected $hidden = [
        'password',
    ];

    // Relación con negocios
    public function businesses()
    {
        return $this->belongsToMany(
            Business::class,
            'owner_busines',
            'owner_id',
            'busines_id'
        )->withPivot('state');
    }

    // Relación con tabla user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function getProfilePhotoUrlAttribute(): string
    {
        if ($this->profile_photo && Storage::disk('public')->exists('owner/'.$this->profile_photo)) {
            return Storage::url('owner/'.$this->profile_photo);
        }

        return asset('img/default-user.png');
    }
}
