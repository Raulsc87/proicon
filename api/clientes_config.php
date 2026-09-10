<?php
return [
    'tabla' => 'cliente',
    'id' => 'id_cliente',
    'buscar' => ['nombre', 'nit'],
    'campos' => [
        'nombre' => ['max' => 120, 'requerido' => true],
        'nit' => ['max' => 20],
        'telefono' => ['max' => 20],
        'correo' => ['max' => 120],
        'direccion' => ['max' => 200],
        'estado' => ['max' => 20, 'requerido' => true]
    ]
];
