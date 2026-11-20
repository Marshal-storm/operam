<?php

declare(strict_types=1);

namespace System\Services;

use System\Core\DataTypes;
use System\Models\ActiveSessionsModel;

final class ActiveSessionsService extends ModelService
{
    /** Recurso interno: no expuesto en rutas públicas. */
    protected bool $private = true;

    protected function modelClass(): string
    {
        return ActiveSessionsModel::class;
    }

    protected function configureFields(): void
    {
        $this->validator
            ->setField('session_uid', DataTypes::String, true)
            ->setField('user_id', DataTypes::Integer, true)
            ->setField('ip_address', DataTypes::String, false)
            ->setField('user_agent', DataTypes::String, false)
            ->setField('expires_at', DataTypes::DateTime, true)
            ->setField('is_revoked', DataTypes::Boolean, false, false)
            ->setField('created_at', DataTypes::DateTime, false);
    }
}
