<?php

namespace App\Livewire\DatabaseServer\Connection;

use App\Livewire\DatabaseServer\Form;

/**
 * Neo4j Enterprise: host/port/credentials reach Bolt (connection test and
 * database listing), while the backup itself connects to the separate,
 * unauthenticated backup listener on `backup_port`.
 */
class Neo4jConnectionRules extends ClientServerConnectionRules
{
    public function rules(Form $form): array
    {
        return array_merge(parent::rules($form), [
            'backup_port' => 'required|integer|min:1|max:65535',
        ]);
    }

    public function extraConfig(Form $form): array
    {
        return ['backup_port' => $form->backup_port];
    }

    public function dumpPreviewConfig(Form $form): array
    {
        return ['backup_port' => $form->backup_port];
    }
}
