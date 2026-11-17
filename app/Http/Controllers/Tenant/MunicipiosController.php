<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Estados;
use App\Support\Municipios;
use Illuminate\Http\JsonResponse;

/** Lista de municípios de uma UF, para o seletor de cidade (cadastro e ficha do promotor). */
class MunicipiosController extends Controller
{
    public function porUf(string $uf): JsonResponse
    {
        abort_unless(in_array(strtoupper($uf), Estados::UFS, true), 404);

        return response()->json(Municipios::porUf($uf))->header('Cache-Control', 'public, max-age=86400');
    }
}
