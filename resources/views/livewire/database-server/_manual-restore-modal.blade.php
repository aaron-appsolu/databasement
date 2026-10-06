@props(['type' => null, 'snapshotsServerId' => null])

@php
    $type = $type instanceof \App\Enums\DatabaseType ? $type : \App\Enums\DatabaseType::tryFrom((string) $type);
    $isNeo4j = $type === \App\Enums\DatabaseType::NEO4J;
@endphp

{{-- Manual restore steps for the backup-only types (see DatabaseType::supportsRestore()). --}}
<x-modal wire:model="showManualRestoreModal" :title="$isNeo4j ? __('Restore Neo4j Snapshot') : __('Restore Redis / Valkey Snapshot')" class="backdrop-blur">
    <div class="space-y-4">
        <x-alert class="alert-info" icon="o-information-circle">
            <div>
                <span class="font-bold">{{ __('Manual Restore Required') }}</span>
                <p class="text-sm mt-1">
                    @if($isNeo4j)
                        {{ __('Automated restore is not supported for Neo4j. neo4j-admin restores into the server’s own store, so it has to run on the Neo4j server.') }}
                    @else
                        {{ __('Automated restore is not supported for Redis/Valkey. RDB snapshots must be restored manually.') }}
                    @endif
                </p>
            </div>
        </x-alert>

        <div class="p-4 border rounded-lg bg-base-200 border-base-300 space-y-3">
            @if($isNeo4j)
                <div class="text-sm font-semibold">{{ __('How to Restore a Neo4j Backup') }}</div>
                <ol class="list-decimal list-inside text-sm space-y-2 opacity-80">
                    <li>{{ __('Download the snapshot archive (.backup.gz) from your storage volume and copy it to the Neo4j server.') }}</li>
                    <li>{{ __('Extract the .backup file from the archive (e.g., gunzip snapshot.backup.gz).') }}</li>
                    <li>{{ __('If the target database exists, stop it: STOP DATABASE mydb;') }}</li>
                    <li>{{ __('Restore it: neo4j-admin database restore --from-path=/path/to/snapshot.backup --overwrite-destination=true mydb') }}</li>
                    <li>{{ __('Start the database again (START DATABASE mydb;), or create it if it is new (CREATE DATABASE mydb;).') }}</li>
                </ol>
                <a href="https://neo4j.com/docs/operations-manual/current/backup-restore/restore-backup/"
                   target="_blank" rel="noopener"
                   class="link link-primary text-xs">{{ __('Neo4j restore documentation') }}</a>
            @else
                <div class="text-sm font-semibold">{{ __('How to Restore an RDB Snapshot') }}</div>
                <ol class="list-decimal list-inside text-sm space-y-2 opacity-80">
                    <li>{{ __('Download the snapshot archive (.rdb.gz) from your storage volume.') }}</li>
                    <li>{{ __('Extract the RDB file from the archive (e.g., gunzip snapshot.rdb.gz).') }}</li>
                    <li>{{ __('Stop the Redis/Valkey server.') }}</li>
                    <li>{{ __('Copy the RDB file to the Redis data directory, replacing dump.rdb.') }}</li>
                    <li>{{ __('Set correct file permissions (e.g., chown redis:redis dump.rdb).') }}</li>
                    <li>{{ __('Restart the Redis/Valkey server.') }}</li>
                </ol>
            @endif
        </div>

        @if($snapshotsServerId)
            <a href="{{ route('snapshots.index', ['serverFilter' => $snapshotsServerId]) }}"
               class="btn btn-sm btn-outline gap-2" wire:navigate>
                <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
                {{ __('View Backup Snapshots') }}
            </a>
        @endif
    </div>

    <x-slot:actions>
        <x-button :label="__('Close')" @click="$wire.showManualRestoreModal = false" />
    </x-slot:actions>
</x-modal>
