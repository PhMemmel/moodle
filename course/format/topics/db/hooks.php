<?php
$callbacks = [
        [
                'hook' => block_massaction\hook\filter_sections::class,
                'callback' => [\format_topics\local\callbacks::class, 'filter_sections'],
                'priority' => 1000,
        ],
];
