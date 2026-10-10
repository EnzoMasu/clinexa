{{-- Cartel fijo que detiene el autoguardado (versión vieja, consulta cerrada o sesión vencida). Sin navegar. --}}
<div x-show="cartel" style="display: none" role="alert"
    class="fixed inset-x-0 bottom-0 z-40 border-t border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 shadow-lg dark:border-red-800 dark:bg-red-900/90 dark:text-red-100">
    <span x-text="cartel"></span>
</div>
