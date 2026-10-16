{{--
    Enlace a los certificados de reposo de la consulta (docs/reposo-medico.md), solo para quien puede verlos
    (ReposoMedicoPolicy::verDeConsulta). Se abre en otra pestaña: con la consulta abierta, la atención sigue en la suya.
    Requiere $consulta.
--}}
@can('verDeConsulta', [App\Models\ReposoMedico::class, $consulta])
    <a href="{{ route('admin.reposos.consulta', $consulta) }}" target="_blank" rel="noopener" data-reposo-medico
        class="text-sm text-gray-600 hover:underline dark:text-gray-400">Reposo médico</a>
@endcan
