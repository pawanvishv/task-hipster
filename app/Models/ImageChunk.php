<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageChunk extends Model
{
    protected $fillable = [
        'upload_session_id',
        'chunk_index',
        'chunk_path',
        'chunk_size',
        'checksum',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'chunk_size' => 'integer',
    ];
}
