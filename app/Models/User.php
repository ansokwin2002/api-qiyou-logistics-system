<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<Database\Factories\UserFactory> */
    use HasFactory, HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'status',
        'customer_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'userName',
        'nickName',
        'realName',
        'gender',
        'mobile',
        'state',
        'addTime',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function getRoleNamesAttribute(): array
    {
        return $this->roles->pluck('slug')->all();
    }

    // Frontend field mappings
    public function getUserNameAttribute()
    {
        return $this->name;
    }

    public function getNickNameAttribute()
    {
        return $this->name;
    }

    public function getRealNameAttribute()
    {
        return $this->name;
    }

    public function getGenderAttribute()
    {
        return 1; // default male
    }

    public function getMobileAttribute()
    {
        return $this->phone;
    }

    public function getStateAttribute()
    {
        return $this->status === 'active' ? 1 : 0;
    }

    public function getAddTimeAttribute()
    {
        return $this->created_at ? $this->created_at->timestamp : 0;
    }
}