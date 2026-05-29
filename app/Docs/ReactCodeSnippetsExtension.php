<?php

namespace App\Docs;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;

class ReactCodeSnippetsExtension extends OperationExtension
{
    /**
     * @param Operation $operation
     * @param RouteInfo $routeInfo
     */
    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        $url = url($routeInfo->route->uri());
        $method = strtoupper($routeInfo->method);
        
        // Identificar si la ruta requiere autenticación (revisando si usa middleware auth:sanctum)
        $requiresAuth = collect($routeInfo->route->gatherMiddleware())->contains(function ($middleware) {
            return is_string($middleware) && str_contains($middleware, 'auth:sanctum');
        });

        // Extraer los parámetros del Request si es aplicable
        // En una implementación básica, ponemos un ejemplo genérico de JSON body si es POST/PUT
        $bodyExample = "";
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $bodyExample = ",\n    data: {\n        // parámetros...\n    }";
        }

        $headersAxios = $requiresAuth 
            ? ",\n    headers: { 'Authorization': 'Bearer TU_TOKEN' }" 
            : "";
            
        $headersFetch = $requiresAuth 
            ? ",\n        'Authorization': 'Bearer TU_TOKEN',\n        'Content-Type': 'application/json'\n    }" 
            : ",\n        'Content-Type': 'application/json'\n    }";

        $axiosSnippet = <<<JS
import axios from 'axios';

// 1. Configuración de Petición
axios({
    method: '{$method}',
    url: '{$url}'{$bodyExample}{$headersAxios}
})
.then(response => {
    // 2. Respuesta Exitosa
    console.log("Success:", response.data);
})
.catch(error => {
    // 3. Manejo de Errores (401, 422, 500)
    if (error.response) {
        console.error("Status:", error.response.status);
        console.error("Data:", error.response.data);
    }
});
JS;

        $bodyFetch = in_array($method, ['POST', 'PUT', 'PATCH']) 
            ? ",\n    body: JSON.stringify({\n        // parámetros...\n    })" 
            : "";

        $fetchSnippet = <<<JS
// 1. Configuración de Petición
fetch('{$url}', {
    method: '{$method}',
    headers: {
        'Accept': 'application/json'{$headersFetch}{$bodyFetch}
})
.then(async response => {
    // 3. Manejo de Errores nativo en Fetch
    if (!response.ok) {
        const errorData = await response.json();
        throw new Error(`HTTP \${response.status}: \${JSON.stringify(errorData)}`);
    }
    return response.json();
})
.then(data => {
    // 2. Respuesta Exitosa
    console.log("Success:", data);
})
.catch(error => console.error("Error:", error));
JS;

        // Inyectar a la especificación OpenAPI (x-codeSamples)
        // Scalar UI automáticamente renderiza esto en la vista lateral.
        $operation->setAttribute('x-codeSamples', [
            [
                'lang' => 'JavaScript',
                'label' => 'Axios (React Native)',
                'source' => $axiosSnippet,
            ],
            [
                'lang' => 'JavaScript',
                'label' => 'Fetch (React Native)',
                'source' => $fetchSnippet,
            ]
        ]);
    }
}
