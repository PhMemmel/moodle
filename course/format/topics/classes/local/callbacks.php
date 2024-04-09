<?php

namespace format_topics\local;

class callbacks {

    public static function filter_sections(\block_massaction\hook\filter_sections $filtersectionshook): void {
        if (defined('PHPUNIT_TEST')) {
            return;
        }
        $filtersectionshook->remove_sectionnum(0);
        if ($filtersectionshook->get_targetcourse() === $filtersectionshook::ANOTHERCOURSE) {
            $filtersectionshook->remove_sectionnum(3);
            $filtersectionshook->disable_keeporiginalsection();
            $filtersectionshook->disable_createnewsection();
        }
    }

}