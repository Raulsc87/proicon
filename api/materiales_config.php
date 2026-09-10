<?php
return [
    'tabla' => 'material',
    'id' => 'id_material',
    'buscar' => ['nombre', 'codigo'],
    'campos' => [
        'codigo' => ['max' => 40],
        'nombre' => ['max' => 150, 'requerido' => true],
        'descripcion' => ['max' => 250],
        'estado' => ['max' => 20, 'requerido' => true],
        'id_unidad' => ['tipo' => 'entero', 'requerido' => true]
    ]
];
