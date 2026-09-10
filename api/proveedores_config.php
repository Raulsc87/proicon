<?php
return [
    'tabla' => 'proveedor',
    'id' => 'id_proveedor',
    'buscar' => ['nombre', 'nit'],
    'campos' => [
        'nombre' => ['max' => 150, 'requerido' => true],
        'nit' => ['max' => 20],
        'telefono' => ['max' => 20],
        'correo' => ['max' => 120],
        'direccion' => ['max' => 200],
        'estado' => ['max' => 20, 'requerido' => true]
    ]
];
