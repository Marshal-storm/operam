<?php

declare(strict_types=1);

namespace System\Models;

use Illuminate\Database\Eloquent\Model;

/** Contactos del cliente RF-1.5 (`client_contacts`). */
final class ClientContactsModel extends Model
{
    protected $table = 'client_contacts';

    /** Alias obligatorio del framework operam/servicios. */
    public $alias = 'cct';

    /** @var list<string> */
    protected $fillable = [
        'client_id',
        'erp_contact_id',
        'name',
        'email',
        'phone',
        'role',
        'receives_portal',
        'active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'client_id' => 'integer',
        'receives_portal' => 'boolean',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
