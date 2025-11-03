<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    use HasFactory;

    protected $fillable = ['link', 'title', 'button_id', 'type_params', 'type', 'custom_icon'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($link) {
          if (config('linkstack.disable_random_link_ids') != 'true') {
            $numberOfDigits = config('linkstack.link_id_length') ?? 9;

            $minIdValue = 10**($numberOfDigits - 1);
            $maxIdValue = 10**$numberOfDigits - 1;

            do {
                $randomId = rand($minIdValue, $maxIdValue);
            } while (Link::find($randomId));

            $link->id = $randomId;
          }
        });
    }
    
        /**
     * Links associated to this link (shows as small icons).
     */
    public function associatedLinks()
    {
        return $this->belongsToMany(
            self::class,
            'link_associations',
            'link_id',
            'associated_link_id'
        )->withTimestamps();
    }

    /**
     * Reverse lookup: links that list this one as associated.
     */
    public function primaryFor()
    {
        return $this->belongsToMany(
            self::class,
            'link_associations',
            'associated_link_id',
            'link_id'
        )->withTimestamps();
    }

}
