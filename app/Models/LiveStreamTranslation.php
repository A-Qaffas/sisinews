<?php

namespace App\Models;

use App\Enums\Locale;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['live_stream_id', 'locale', 'title', 'description'])]
class LiveStreamTranslation extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['locale' => Locale::class];
    }

    public function liveStream(): BelongsTo
    {
        return $this->belongsTo(LiveStream::class);
    }
}
