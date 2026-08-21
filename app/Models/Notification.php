<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un aviso dirigido a una persona.
 *
 * La tabla existía desde el principio y no la usaba nadie: los endpoints
 * estaban comentados en `UserController` y no había modelo que la representara.
 */
class Notification extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'notification_id';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'tipo',
        'message',
        'datos',
        'read',
        'date',
        'state',
    ];

    protected $casts = [
        'datos' => 'array',
        'read'  => 'boolean',
        'state' => 'boolean',
        'date'  => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
