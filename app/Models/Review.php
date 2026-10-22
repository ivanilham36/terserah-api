<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = ['option_id', 'user_id', 'rating', 'comment'];

    public function option()
    {
        return $this->belongsTo(Option::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
