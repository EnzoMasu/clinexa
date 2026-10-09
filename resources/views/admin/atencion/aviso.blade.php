{{-- Pantalla "Consulta" para quien no es un profesional activo: solo el aviso, sin datos. --}}
<x-admin.page title="Consulta">
    <div class="p-6">
        <p role="status" class="rounded-md bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            {{ $mensaje }}
            @if (\App\Support\Permisos::url('admin.historias-clinicas.index'))
                <a href="{{ route('admin.historias-clinicas.index') }}" class="ms-1 font-medium underline">Ir a Historias clínicas</a>
            @endif
        </p>
    </div>
</x-admin.page>
