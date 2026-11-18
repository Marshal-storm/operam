<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Completa RF-1: referencias de seguridad del emisor (ERP + IntegroPAY).
 * Solo se almacenan nombres/refs del cofre de secretos, nunca llaves en texto plano.
 */
final class AddEmisorSecurityRefs extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('emisor');

        if (!$table->hasColumn('secret_ref')) {
            $table->addColumn('secret_ref', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'api_url',
                'comment' => 'Referencia al secreto ERP (cofre), no la llave',
            ]);
        }

        if (!$table->hasColumn('integropay_merchant_token_ref')) {
            $table->addColumn('integropay_merchant_token_ref', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'secret_ref',
                'comment' => 'Ref merchant token IntegroPAY',
            ]);
        }

        if (!$table->hasColumn('integropay_device_token_ref')) {
            $table->addColumn('integropay_device_token_ref', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'integropay_merchant_token_ref',
                'comment' => 'Ref device token IntegroPAY',
            ]);
        }

        $table->update();
    }
}
