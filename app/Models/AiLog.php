<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiLog extends Model
{
    protected $fillable = ['room_id', 'type', 'prompt', 'response', 'status', 'latency_ms'];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
