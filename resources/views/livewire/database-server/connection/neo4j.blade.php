@props(['form', 'isEdit' => false])

<x-alert class="alert-info" icon="o-information-circle">
    {{ __('Neo4j Enterprise Edition only. Backups run neo4j-admin against the backup port, and restores are done manually on the Neo4j server.') }}
</x-alert>

{{-- Host, port and credentials reach Bolt, for the connection test and the database list. --}}
@include('livewire.database-server.connection._client-server-fields', ['form' => $form, 'isEdit' => $isEdit])

<x-input
    wire:model="form.backup_port"
    :label="__('Backup Port')"
    placeholder="6362"
    :hint="__('The server’s backup listener (server.backup.listen_address). It does not check credentials, so allow only this host to reach it.')"
    type="number"
    min="1"
    max="65535"
    required
/>
