{{--
    Barra de acciones y avisos de la página de una receta (solo en pantalla). $pdf: la dirección del PDF en las recetas
    con indicación de uso ("Abrir el PDF para imprimir"); null en las anteriores, que se imprimen desde el navegador.
--}}
    <nav class="barra">
        <strong>{{ $titulo }} · {{ $receta->etiquetaEstado() }}</strong>
        <div class="acciones">
            @if ($receta->esBorrador())
                @if ($puedeEditar)
                    <a class="boton" href="{{ route('admin.recetas.edit', $receta) }}">Volver a editar</a>
                @endif
                @if ($puedeEmitir && ! $excede && ! ($faltaIndicacion ?? false))
                    <form method="POST" action="{{ route('admin.recetas.emitir', $receta) }}" onsubmit="this.querySelector('button').disabled = true">
                        @csrf
                        <input type="hidden" name="version" value="{{ $receta->version() }}">
                        <button type="submit" class="boton principal">Emitir e imprimir</button>
                    </form>
                @endif
            @elseif ($receta->estaEmitida())
                @if ($pdf)
                    <a class="boton principal" href="{{ $pdf }}" target="_blank" rel="noopener">Abrir el PDF para imprimir</a>
                @else
                    <button type="button" class="boton principal" onclick="window.print()">Imprimir</button>
                @endif
            @endif
            <a class="boton" href="{{ route('admin.consultas.show', $receta->consulta_id) }}">Volver a la consulta</a>
            @if ($puedeAnular)
                <details class="anular">
                    <summary class="boton peligro">Anular</summary>
                    <form method="POST" action="{{ route('admin.recetas.anular', $receta) }}" class="panel">
                        @csrf
                        <label for="motivo">Motivo de la anulación (obligatorio)</label>
                        <textarea id="motivo" name="motivo" rows="3" minlength="5" maxlength="300" required>{{ old('motivo') }}</textarea>
                        @error('motivo')<div class="mensaje error" style="margin:0 0 8px">{{ $message }}</div>@enderror
                        <button type="submit" class="boton peligro">Anular</button>
                        @if ($puedeCorregir ?? false)
                            <button type="submit" class="boton" formaction="{{ route('admin.recetas.corregir', $receta) }}">Anular y corregir</button>
                        @endif
                    </form>
                </details>
            @endif
        </div>
    </nav>

    @foreach (['status' => 'ok', 'aviso' => 'aviso', 'error' => 'error'] as $clave => $clase)
        @if (session($clave))
            <div class="mensaje {{ $clase }}" role="status">{{ session($clave) }}</div>
        @endif
    @endforeach
    @if ($excede)
        <div class="mensaje error" role="alert">{{ \App\Support\MediaHoja::EXCEDE }}</div>
    @endif
    @if ($faltaIndicacion ?? false)
        <div class="mensaje error" role="alert">{{ \App\Http\Controllers\Admin\RecetaController::FALTA_INDICACION }}</div>
    @endif
    @if ($receta->estaAnulada())
        <div class="mensaje aviso" role="status">
            Receta anulada el {{ \App\Support\Fecha::mostrar($receta->anulada_en, conHora: true) }}. Motivo: {{ $receta->motivo_anulacion }}
        </div>
    @endif
