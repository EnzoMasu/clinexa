<?php

namespace Database\Seeders;

use App\Models\Ciudad;
use App\Models\Departamento;
use App\Models\Pais;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los 249 países de ISO 3166-1 (database/data/paises.php), para elegir la nacionalidad de una
 * persona. Solo Paraguay tiene departamentos y ciudades cargados.
 *
 * Paraguay: sus 17 departamentos más Asunción (Distrito Capital, que no pertenece a ningún
 * departamento) y sus 263 distritos, que son las ciudades (database/data/distritos-paraguay.php;
 * fuente: INE, Cartografía Censal 2022).
 *
 * Idempotente y solo aditivo: busca por clave de origen (departamentos y ciudades) o código ISO (países), agrega lo que
 * falte y no modifica ni quita lo existente: un nombre o estado cambiado desde el ABM no se pisa ni se duplica.
 */
class GeografiaSeeder extends Seeder
{
    public const DEPARTAMENTOS = [
        'Concepción', 'San Pedro', 'Cordillera', 'Guairá', 'Caaguazú', 'Caazapá', 'Itapúa', 'Misiones',
        'Paraguarí', 'Alto Paraná', 'Central', 'Ñeembucú', 'Amambay', 'Canindeyú', 'Presidente Hayes',
        'Boquerón', 'Alto Paraguay',
    ];

    public const DISTRITO_CAPITAL = 'Asunción (Distrito Capital)';

    public function run(): void
    {
        // Por código ISO: si el nombre se cambió desde la pantalla, no se duplica.
        foreach (require database_path('data/paises.php') as $codigo => $nombre) {
            Pais::firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        }

        // Por código, no por nombre: Paraguay puede haberse renombrado desde la pantalla.
        $paraguay = Pais::where('codigo', 'PY')->sole();

        foreach ([...self::DEPARTAMENTOS, self::DISTRITO_CAPITAL] as $nombre) {
            $this->asegurar(Departamento::class, ['pais_id' => $paraguay->id], $nombre, self::claveDepartamento($nombre));
        }

        foreach (require database_path('data/distritos-paraguay.php') as $departamento => $ciudades) {
            $departamentoId = Departamento::where('clave_origen', self::claveDepartamento($departamento))->sole()->id;
            foreach ($ciudades as $ciudad) {
                $this->asegurar(Ciudad::class, ['departamento_id' => $departamentoId], $ciudad, self::claveCiudad($departamento, $ciudad));
            }
        }
    }

    public static function claveDepartamento(string $nombre): string
    {
        return "PY:{$nombre}";
    }

    public static function claveCiudad(string $departamento, string $nombre): string
    {
        return "PY:{$departamento}/{$nombre}";
    }

    /**
     * Busca por clave de origen (sobrevive a un cambio de nombre o de estado hecho en la pantalla) y, si no está, por
     * nombre dentro de su padre (registros anteriores a la clave: se les asigna sin tocar nada más). Nunca pisa el nombre.
     */
    private function asegurar(string $modelo, array $padre, string $nombre, string $clave): void
    {
        if ($modelo::where('clave_origen', $clave)->exists()) {
            return;
        }

        $existente = $modelo::where($padre)->where('nombre', $nombre)->whereNull('clave_origen')->first();
        if ($existente) {
            DB::table($existente->getTable())->where('id', $existente->id)->update(['clave_origen' => $clave]); // sin auditar: no cambia ningún dato
            return;
        }

        $nuevo = new $modelo([...$padre, 'nombre' => $nombre]);
        $nuevo->clave_origen = $clave;
        $nuevo->save();
    }
}
