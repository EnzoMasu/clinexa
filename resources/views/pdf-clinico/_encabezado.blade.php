{{-- Encabezado común de los PDF clínicos: clínica, paciente, fecha/hora de la consulta y quién lo generó. Todo escapado. --}}
@use('App\Support\Fecha')
<div class="clinica">{{ $clinica }}</div>
<h1>{{ $titulo }}</h1>
<table class="datos">
    <tr><td class="rotulo">Paciente</td><td>{{ $persona->nombre_completo }}</td></tr>
    <tr><td class="rotulo">Documento</td><td>{{ $persona->documentoPrincipal() }} · Ficha {{ $paciente->nro_ficha }}</td></tr>
    <tr><td class="rotulo">Consulta</td><td>{{ $consulta->fechaHoraTexto() }} · {{ $estadoTexto }}</td></tr>
    <tr><td class="rotulo">Profesional</td><td>{{ $profesional }}</td></tr>
    @if ($consulta->turno)
        <tr><td class="rotulo">Turno</td><td>{{ Fecha::mostrar($consulta->turno->fecha) }}, {{ $consulta->turno->horaOTipo() }}</td></tr>
    @endif
    <tr><td class="rotulo">Generado por</td><td>{{ $generadoPor }}</td></tr>
    <tr><td class="rotulo">Generado el</td><td>{{ $generadoEn }}</td></tr>
</table>
