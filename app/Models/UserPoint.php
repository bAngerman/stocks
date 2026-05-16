<?php

namespace App\Models;

use Database\Factories\UserPointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPoint extends Model
{
    /** @use HasFactory<UserPointFactory> */
    use HasFactory;

    protected $fillable = [
        'discord_user_id',
        'discord_username',
        'total_points',
    ];
}
