<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    protected $primaryKey = 'report_id';
    public $timestamps = false;

    protected $fillable = ['generated_by', 'report_type', 'date_range', 'generated_at'];
    protected $casts = ['generated_at' => 'datetime'];

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by', 'user_id');
    }
}
