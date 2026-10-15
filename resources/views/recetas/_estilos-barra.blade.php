{{-- Estilos de la barra de acciones y los avisos de la página de una receta (solo en pantalla). --}}
        .barra { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 10px 16px; background: #fff; border-bottom: 1px solid #d1d5db; font-size: 14px; }
        .barra .acciones { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .boton { display: inline-block; padding: 6px 12px; border-radius: 6px; border: 1px solid #9ca3af; background: #fff; color: #111827; font-size: 13px; text-decoration: none; cursor: pointer; }
        .boton.principal { background: #1f2937; border-color: #1f2937; color: #fff; }
        .boton.peligro { border-color: #dc2626; color: #b91c1c; }
        .mensaje { margin: 12px auto 0; max-width: {{ $papel['ancho_mm'] }}mm; padding: 10px 12px; border-radius: 6px; font-size: 14px; }
        .mensaje.ok { background: #ecfdf5; color: #065f46; }
        .mensaje.aviso { background: #fffbeb; color: #92400e; }
        .mensaje.error, .aviso-desborde { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .aviso-desborde { display: none; margin: 12px auto 0; max-width: {{ $papel['ancho_mm'] }}mm; padding: 10px 12px; border-radius: 6px; font-size: 14px; }
        details.anular { position: relative; }
        details.anular[open] .panel { position: absolute; right: 0; z-index: 10; width: 340px; margin-top: 6px; padding: 12px; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.15); }
        .panel textarea { width: 100%; margin: 6px 0 8px; font: inherit; font-size: 13px; }
