<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingEvent extends Model
{
    protected $fillable = [
        'trackable_type',
        'trackable_id',
        'status',
        'location',
        'note',
        'actor_name',
        'actor_id',
    ];

    public function trackable()
    {
        return $this->morphTo();
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}