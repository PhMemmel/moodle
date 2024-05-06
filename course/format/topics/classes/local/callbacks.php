<?php

namespace format_topics\local;

class callbacks {

    public static function filter_sections_different_course(\block_massaction\hook\filter_sections_different_course $filtersectionshook): void {

        $filtersectionshook->remove_sectionnum(1);
        $filtersectionshook->remove_sectionnum(2);
        $filtersectionshook->disable_keeporiginalsection();
        $filtersectionshook->disable_createnewsection();
    }

    public static function filter_sections_same_course(\block_massaction\hook\filter_sections_same_course $filtersectionshook): void {

        $filtersectionshook->remove_sectionnum(3);
        $filtersectionshook->remove_sectionnum(4);
    }

}
