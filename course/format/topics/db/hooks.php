<?php
$callbacks = [
        [
                'hook' => block_massaction\hook\filter_sections_different_course::class,
                'callback' => [\format_topics\local\callbacks::class, 'filter_sections_different_course'],
                'priority' => 1000,
        ],
        [
                'hook' => block_massaction\hook\filter_sections_same_course::class,
                'callback' => [\format_topics\local\callbacks::class, 'filter_sections_same_course'],
                'priority' => 1000,
        ],
];
