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
            ->setField('name', DataTypes::String, true)
            ->setField('password_hash', DataTypes::String, false, null, null, true)
            ->setField('role_id', DataTypes::Integer, false)
            ->setField('segment_id', DataTypes::Integer, false)
            ->setField('is_active', DataTypes::Boolean, false, true)
            ->setField('can_authorize_exceptions', DataTypes::Boolean, false, false)
            ->setField('last_login_at', DataTypes::DateTime, false);
    }
}
