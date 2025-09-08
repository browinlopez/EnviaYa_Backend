<?php

namespace App\Models\User;

use App\Models\User;
use App\Models\Maps\Alias;
use App\Models\Maps\Municipality;
use App\Models\Maps\Department;
use App\Models\Maps\Country;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    use HasFactory;

    protected $table = 'user_address';
    protected $primaryKey = 'address_id';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'address',
        'municipality_id',
        'alias_id',
        'latitude',
        'longitude',
        'state'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class, 'municipality_id', 'id');
    }

    public function alias()
    {
        return $this->belongsTo(Alias::class, 'alias_id', 'alias_id');
    }

    public function department()
    {
        return $this->hasOneThrough(
            Department::class,
            Municipality::class,
            'id',          // FK de municipality en user_address
            'id',          // FK de department en municipality
            'municipality_id', // Local key en user_address
            'department_id'   // Local key en municipality
        );
    }

    public function country()
    {
        return $this->hasOneThrough(
            Country::class,
            Department::class,
            'id',         // FK de department en municipality
            'id',         // FK de country en department
            'department_id', // Local key en department
            'country_id'     // Local key en country
        );
    }

    public function scopeActive($query)
    {
        return $query->where('state', true);
    }
}
