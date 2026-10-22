<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = ['owner_id', 'decided_option_id', 'name', 'category', 'code', 'use_location', 'status', 'decision_reason', 'weights'];

    protected $casts = ['use_location' => 'boolean', 'weights' => 'array'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'room_members')->withPivot(['lat', 'lng', 'budget_max', 'pref_text', 'pref_parsed'])->withTimestamps();
    }

    public function options()
    {
        return $this->belongsToMany(Option::class, 'room_options')->withTimestamps();
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    public function decidedOption()
    {
        return $this->belongsTo(Option::class, 'decided_option_id');
    }

    public function aiLogs()
    {
        return $this->hasMany(AiLog::class);
    }
}
