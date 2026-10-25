<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    protected $fillable = ['created_by', 'title', 'description', 'category', 'address', 'lat', 'lng', 'price_level', 'tags', 'source', 'external_id'];

    protected $casts = ['tags' => 'array'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'room_options');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
