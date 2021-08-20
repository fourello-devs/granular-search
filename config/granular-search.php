<?php

return [
    'q_alias' => env('GRANULAR_Q_ALIAS','q'),
    'database' => [
        'allowed_connections' => [
            'mysql',
        ],
        'special_columns_error_mapping' => [
            'mysql' => [
                'linestring' => 'string',
                'multilinestring' => 'string',
                'enum' => 'string',
                'geometry' => 'float',
                'geometrycollection' => 'float',
                'point' => 'float',
                'multipoint' => 'float',
                'polygon' => 'float',
                'multipolygon' => 'float',
            ],
        ],
        'non_string_columns' => [
            'bigint',
            'blob',
            'boolean',
            'datetime',
            'float',
            'integer',
            'json',
            'smallint',
            'geometry',
            'geometrycollection',
            'point',
            'multipoint',
            'polygon',
            'multipolygon',
        ]
    ],
];
