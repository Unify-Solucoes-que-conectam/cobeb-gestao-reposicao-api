<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    // Define as regras padrão para os filtros
    protected static $defaultFilterRules = [
        'page' => ['integer', 'min:1'],
        'per_page' => ['integer', 'min:1'],
        'search' => ['nullable', 'string'],
    ];

    /**
     * Formata a resposta de uma requisição paginada.
     */
    protected static function paginatedResponse(LengthAwarePaginator $paginatedResult, array $additionalData = []): array
    {
        return array_merge([
            'success' => true,
            'data' => $paginatedResult->items(),
            'pagination' => [
                'current_page' => $paginatedResult->currentPage(),
                'last_page' => $paginatedResult->lastPage(),
                'from' => $paginatedResult->firstItem(),
                'to' => $paginatedResult->lastItem(),
                'total' => $paginatedResult->total(),
                'per_page' => $paginatedResult->perPage(),
            ],
        ], $additionalData);
    }

    /**
     * Pagina a consulta quando a requisição informa "per_page" (máx. 100);
     * sem ele, devolve a lista completa (telas que precisam de todos os registros).
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  callable  $resource  transforma cada registro (ex.: fn ($m) => new MotoristaResource($m))
     */
    protected static function paginarOuListar(Request $request, $query, callable $resource, string $message): JsonResponse
    {
        if ($request->filled('per_page')) {
            $request->validate([
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['integer', 'min:1', 'max:100'],
            ]);

            $paginado = $query->paginate((int) $request->input('per_page'))->through($resource);

            return response()->json(static::paginatedResponse($paginado, ['message' => $message]));
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $query->get()->map($resource)->values(),
        ]);
    }
}