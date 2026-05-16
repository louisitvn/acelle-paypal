<?php

namespace Acelle\Paypal\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'acelle_paypal_settings';

    protected $fillable = [
        'name',
    ];
}
