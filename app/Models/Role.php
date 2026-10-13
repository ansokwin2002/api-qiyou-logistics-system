<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'code', 'description', 'sort', 'is_enable', 'permissions'];

    protected $casts = [
        'sort' => 'integer',
        'is_enable' => 'boolean',
        'permissions' => 'array',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}