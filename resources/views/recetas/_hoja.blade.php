{{--
    La hoja de una receta: A4 vertical en dos mitades iguales separadas por una línea de corte.
    Arriba la RECETA (queda en la farmacia), abajo las INDICACIONES (las lleva el paciente).

    Se dibuja SOLO desde $hoja (App\Support\HojaReceta): el snapshot de una receta emitida o anulada, o
    los datos de hoy en la vista previa de un borrador. Todo escapado con {{ }}. Sin overflow:hidden: si
    algo no entrara, se ve (y la página avisa), nunca se corta en silencio.

    $marca: "VISTA PREVIA - NO VÁLIDA", "ANULADA" o null. Sale también al imprimir.
--}}
@php
    use App\Support\Fecha;
    use App\Support\MediaHoja;

    $paciente = $hoja['paciente'];
    $profesional = $hoja['profesional'];
    $fecha = Fecha::mostrar($hoja['fecha']);
    $numero = $hoja['numero'] ?? null;
    $edad = $paciente['edad'] === null ? null : $paciente['edad'].' '.($paciente['edad'] === 1 ? 'año' : 'años');
    $especialidades = implode(', ', $profesional['especialidades'] ?? []);
@endphp

<div class="hoja">
    @if ($marca)
        <div class="marca" aria-hidden="true"><span>{{ $marca }}</span></div>
    @endif

    {{-- Mitad de arriba: la receta (queda en la farmacia). --}}
    <section class="mitad" data-mitad="receta" aria-label="Receta">
        <header class="encabezado">
            <img src="{{ asset(config('clinexa.clinica.logo')) }}" alt="" class="logo">
            <div>
                <div class="clinica">{{ $hoja['clinica']['nombre'] }}</div>
                @if ($hoja['clinica']['direccion'])
                    <div class="pequeno">{{ $hoja['clinica']['direccion'] }}</div>
                @endif
                @if ($hoja['clinica']['telefono'])
                    <div class="pequeno">Tel. {{ $hoja['clinica']['telefono'] }}</div>
                @endif
            </div>
        </header>

        <div class="titulo">
            <span>Receta N° {{ $numero ?? '(se asigna al emitir)' }}</span>
            <span>Fecha: {{ $fecha }}</span>
        </div>
        @if ($hoja['reemplaza_a'] ?? null)
            <div class="pequeno">Reemplaza a {{ $hoja['reemplaza_a'] }}</div>
        @endif

        <div class="paciente">
            <strong>Paciente:</strong> {{ $paciente['nombre'] }}
            · {{ $paciente['documento'] }} · Ficha {{ $paciente['ficha'] }}@if ($edad) · {{ $edad }}@endif
        </div>

        <ol class="medicamentos">
            @foreach ($hoja['medicamentos'] as $medicamento)
                <li>
                    <strong>{{ $medicamento['medicamento'] }}</strong>@if (filled($medicamento['cantidad'])) — Cantidad: {{ $medicamento['cantidad'] }}@endif
                </li>
            @endforeach
        </ol>

        @if (filled($hoja['observaciones']))
            <p class="texto"><strong>Observaciones:</strong> {{ $hoja['observaciones'] }}</p>
        @endif

        <footer class="firma">
            <div class="linea-firma"></div>
            <div><strong>{{ $profesional['nombre'] }}</strong> · Mat. {{ $profesional['matricula'] }}</div>
            @if ($especialidades !== '')
                <div class="pequeno">{{ $especialidades }}</div>
            @endif
        </footer>
    </section>

    <div class="corte" aria-hidden="true"><span>✂ Cortar por la línea</span></div>

    {{-- Mitad de abajo: las indicaciones (las lleva el paciente). --}}
    <section class="mitad" data-mitad="indicaciones" aria-label="Indicaciones para el paciente">
        <div class="titulo">
            <span>Indicaciones para el paciente</span>
            <span>Receta N° {{ $numero ?? '(se asigna al emitir)' }} · {{ $fecha }}</span>
        </div>
        <div class="paciente"><strong>Paciente:</strong> {{ $paciente['nombre'] }}</div>

        <h2 class="subtitulo">Cómo tomar su medicación</h2>
        @foreach ($hoja['medicamentos'] as $medicamento)
            <div class="toma">
                <div><strong>{{ $medicamento['medicamento'] }}</strong></div>
                {{-- Solo los campos cargados, separados por "·" (sin uno suelto al final). --}}
                @php($toma = array_filter(['Dosis' => $medicamento['dosis'], 'Vía' => $medicamento['via'], 'Frecuencia' => $medicamento['frecuencia'], 'Duración' => $medicamento['duracion']], fn ($valor) => filled($valor)))
                <div>
                    @foreach ($toma as $etiqueta => $valor)
                        <span class="rotulo">{{ $etiqueta }}:</span> {{ $valor }}@unless ($loop->last) <span class="sep">·</span> @endunless
                    @endforeach
                </div>
                @if (filled($medicamento['observaciones']))
                    <div><span class="rotulo">Observaciones:</span> {{ $medicamento['observaciones'] }}</div>
                @endif
            </div>
        @endforeach

        @if (($hoja['indicaciones'] ?? []) !== [])
            <h2 class="subtitulo">Indicaciones generales</h2>
            <ul class="indicaciones">
                @foreach ($hoja['indicaciones'] as $indicacion)
                    <li>@if ($indicacion['tipo'])<span class="rotulo">{{ $indicacion['tipo'] }}:</span> @endif<span class="texto-libre">{{ $indicacion['texto'] }}</span></li>
                @endforeach
            </ul>
        @endif

        <footer class="pie">
            {{ $profesional['nombre'] }} · Mat. {{ $profesional['matricula'] }}@if ($especialidades !== '') · {{ $especialidades }}@endif
        </footer>
    </section>
</div>
