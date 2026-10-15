<?php

namespace App\Models;

use App\Enums\Locale;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of Egypt's 27 governorates. Reference data, seeded once; not editable in the CMS. */
class Governorate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function liveStreams(): HasMany
    {
        return $this->hasMany(LiveStream::class);
    }

    public function nameIn(Locale $locale): string
    {
        return $locale === Locale::Ar ? $this->name_ar : $this->name_en;
    }
}
