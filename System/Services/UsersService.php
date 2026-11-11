<?php

declare(strict_types=1);

namespace System\Services;

use System\Core\DataTypes;
use System\Models\UsersModel;

final class UsersService extends ModelService
{
    protected function modelClass(): string
    {
        return UsersModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('email', DataTypes::Email, true)
            ->setField('nombre', DataTypes::String, true)
            ->setField('password_hash', DataTypes::String, false, null, null, true)
            ->setField('rol', DataTypes::String, true)
            ->setField('segmento_id', DataTypes::Integer, false)
            ->setField('activo', DataTypes::Boolean, false, true)
            ->setField('ultimo_acceso', DataTypes::DateTime, false);
    }
}
