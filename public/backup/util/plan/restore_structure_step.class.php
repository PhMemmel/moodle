<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package moodlecore
 * @subpackage backup-plan
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Abstract class defining the needed stuff to restore one xml file
 *
 * TODO: Finish phpdocs
 */
abstract class restore_structure_step extends restore_step {

    protected $filename; // Name of the file to be parsed
    protected $contentprocessor; // xml parser processor being used
                                 // (need it here, apart from parser
                                 // thanks to serialized data to process -
                                 // say thanks to blocks!)
    protected $pathelements;  // Array of pathelements to process
    protected $elementsoldid; // Array to store last oldid used on each element
    protected $elementsnewid; // Array to store last newid used on each element

    protected $pathlock;      // Path currently locking processing of children

    /** @var int Number of queued records after which the insert queue is written to the database. */
    protected int $insertqueuelimit = 500;

    /** @var stdClass[] Records queued by {@see self::queue_insert_record()}, in queue order. */
    private array $insertqueue = [];

    /** @var bool[] Mapping item names (as keys) of queued records whose mapping has not been stored yet. */
    private array $insertqueuemappings = [];

    /** @var bool[][] Ids (as keys) per table that have already been assigned to the mapping of a queued record. */
    private array $insertqueueassignedids = [];

    const SKIP_ALL_CHILDREN = -991399; // To instruct the dispatcher about to ignore
                                       // all children below path processor returning it

    /**
     * Constructor - instantiates one object of this class
     */
    public function __construct($name, $filename, $task = null) {
        if (!is_null($task) && !($task instanceof restore_task)) {
            throw new restore_step_exception('wrong_restore_task_specified');
        }
        $this->filename = $filename;
        $this->contentprocessor = null;
        $this->pathelements = array();
        $this->elementsoldid = array();
        $this->elementsnewid = array();
        $this->pathlock = null;
        parent::__construct($name, $task);
    }

    final public function execute() {

        if (!$this->execute_condition()) { // Check any condition to execute this
            return;
        }

        $fullpath = $this->task->get_taskbasepath();

        // We MUST have one fullpath here, else, error
        if (empty($fullpath)) {
            throw new restore_step_exception('restore_structure_step_undefined_fullpath');
        }

        // Append the filename to the fullpath
        $fullpath = rtrim($fullpath, '/') . '/' . $this->filename;

        // And it MUST exist
        if (!file_exists($fullpath)) { // Shouldn't happen ever, but...
            throw new restore_step_exception('missing_moodle_backup_xml_file', $fullpath);
        }

        // Get restore_path elements array adapting and preparing it for processing
        $structure = $this->define_structure();
        if (!is_array($structure)) {
            throw new restore_step_exception('restore_step_structure_not_array', $this->get_name());
        }
        $this->prepare_pathelements($structure);

        // Create parser and processor
        $xmlparser = new progressive_parser();
        $xmlparser->set_file($fullpath);
        $xmlprocessor = new restore_structure_parser_processor($this->task->get_courseid(), $this);
        $this->contentprocessor = $xmlprocessor; // Save the reference to the contentprocessor
                                                 // as far as we are going to need it out
                                                 // from parser (blame serialized data!)
        $xmlparser->set_processor($xmlprocessor);

        // Add pathelements to processor
        foreach ($this->pathelements as $element) {
            $xmlprocessor->add_path($element->get_path(), $element->is_grouped());
        }

        // Set up progress tracking.
        $progress = $this->get_task()->get_progress();
        $progress->start_progress($this->get_name(), \core\progress\base::INDETERMINATE);
        $xmlparser->set_progress($progress);

        // And process it, dispatch to target methods in step will start automatically
        $xmlparser->process();

        // Write all records still queued for bulk insertion.
        $this->flush_insert_queue();

        // Have finished, launch the after_execute method of all the processing objects
        $this->launch_after_execute_methods();
        $progress->end_progress();
    }

    /**
     * Receive one chunk of information form the xml parser processor and
     * dispatch it, following the naming rules
     */
    final public function process($data) {
        if (!array_key_exists($data['path'], $this->pathelements)) { // Incorrect path, must not happen
            throw new restore_step_exception('restore_structure_step_missing_path', $data['path']);
        }
        $element = $this->pathelements[$data['path']];
        $object = $element->get_processing_object();
        $method = $element->get_processing_method();
        $rdata = null;
        if (empty($object)) { // No processing object defined
            throw new restore_step_exception('restore_structure_step_missing_pobject', $object);
        }
        // Release the lock if we aren't anymore within children of it
        if (!is_null($this->pathlock) and strpos($data['path'], $this->pathlock) === false) {
            $this->pathlock = null;
        }
        if (is_null($this->pathlock)) { // Only dispatch if there isn't any lock
            $rdata = $object->$method($data['tags']); // Dispatch to proper object/method
        }

        // If the dispatched method returns SKIP_ALL_CHILDREN, we grab current path in order to
        // lock dispatching to any children
        if ($rdata === self::SKIP_ALL_CHILDREN) {
            // Check we haven't any previous lock
            if (!is_null($this->pathlock)) {
                throw new restore_step_exception('restore_structure_step_already_skipping', $data['path']);
            }
            // Set the lock
            $this->pathlock = $data['path'] . '/'; // Lock everything below current path

        // Continue with normal processing of return values
        } else if ($rdata !== null) { // If the method has returned any info, set element data to it
            $element->set_data($rdata);
        } else {               // Else, put the original parsed data
            $element->set_data($data);
        }
    }

    /**
     * To send ids pairs to backup_ids_table and to store them into paths
     *
     * This method will send the given itemname and old/new ids to the
     * backup_ids_temp table, and, at the same time, will save the new id
     * into the corresponding restore_path_element for easier access
     * by children. Also will inject the known old context id for the task
     * in case it's going to be used for restoring files later
     */
    public function set_mapping($itemname, $oldid, $newid, $restorefiles = false, $filesctxid = null, $parentid = null) {
        if ($restorefiles && $parentid) {
            throw new restore_step_exception('set_mapping_cannot_specify_both_restorefiles_and_parentitemid');
        }
        // If we haven't specified one context for the files, use the task one
        if (is_null($filesctxid)) {
            $parentitemid = $restorefiles ? $this->task->get_old_contextid() : null;
        } else { // Use the specified one
            $parentitemid = $restorefiles ? $filesctxid : null;
        }
        // We have passed one explicit parentid, apply it
        $parentitemid = !is_null($parentid) ? $parentid : $parentitemid;

        // Let's call the low level one
        restore_dbops::set_backup_ids_record($this->get_restoreid(), $itemname, $oldid, $newid, $parentitemid);
        // Now, if the itemname matches any pathelement->name, store the latest $newid
        if (array_key_exists($itemname, $this->elementsoldid)) { // If present in  $this->elementsoldid, is valid, put both ids
            $this->elementsoldid[$itemname] = $oldid;
            $this->elementsnewid[$itemname] = $newid;
        }
    }

    /**
     * Returns the latest (parent) old id mapped by one pathelement
     */
    public function get_old_parentid($itemname) {
        return array_key_exists($itemname, $this->elementsoldid) ? $this->elementsoldid[$itemname] : null;
    }

    /**
     * Returns the latest (parent) new id mapped by one pathelement
     */
    public function get_new_parentid($itemname) {
        return array_key_exists($itemname, $this->elementsnewid) ? $this->elementsnewid[$itemname] : null;
    }

    /**
     * Return the new id of a mapping for the given itemname
     *
     * @param string $itemname the type of item
     * @param int $oldid the item ID from the backup
     * @param mixed $ifnotfound what to return if $oldid wasnt found. Defaults to false
     */
    public function get_mappingid($itemname, $oldid, $ifnotfound = false) {
        $mapping = $this->get_mapping($itemname, $oldid);
        return $mapping ? $mapping->newitemid : $ifnotfound;
    }

    /**
     * Return the complete mapping from the given itemname, itemid
     */
    public function get_mapping($itemname, $oldid) {
        if (isset($this->insertqueuemappings[$itemname])) {
            // The mapping may belong to a queued record, so write the queue first.
            $this->flush_insert_queue();
        }
        return restore_dbops::get_backup_ids_record($this->get_restoreid(), $itemname, $oldid);
    }

    /**
     * Queue a record to be inserted in bulk together with other queued records.
     *
     * Use this instead of $DB->insert_record() to reduce the number of write queries. The queue is written when it is
     * full, before a mapping of a queued $mappingitemname is read via {@see self::get_mapping()} and before the
     * after_execute methods are launched. So nothing must read queued records from the table before.
     *
     * If $mappingitemname is given, the new id is identified after the bulk insert by comparing the content of the
     * records sharing the same value in $parentfield, and the mapping from $oldid to the new id is stored.
     *
     * @param string $table The table to insert the record into.
     * @param stdClass|array $record The record to insert, fields which are no columns of the table are ignored.
     * @param string|null $mappingitemname The item name to store the mapping of the new id with, null for no mapping.
     * @param int|null $oldid The old id of the record, required if $mappingitemname is given.
     * @param string|null $parentfield Field identifying records only created by this restore (e.g. the new question id),
     *     required if $mappingitemname is given.
     */
    public function queue_insert_record(
        string $table,
        stdClass|array $record,
        ?string $mappingitemname = null,
        ?int $oldid = null,
        ?string $parentfield = null,
    ): void {
        global $DB;

        if ($mappingitemname !== null && ($oldid === null || $parentfield === null)) {
            throw new coding_exception('Queued records with a mapping need an old id and a parent field.');
        }
        $record = array_intersect_key((array) $record, $DB->get_columns($table));
        unset($record['id']);
        // All records of one bulk insert need the same structure, so use a defined field order.
        ksort($record);
        $this->insertqueue[] = (object) [
            'table' => $table,
            'record' => $record,
            'mappingitemname' => $mappingitemname,
            'oldid' => $oldid,
            'parentfield' => $parentfield,
        ];
        if ($mappingitemname !== null) {
            $this->insertqueuemappings[$mappingitemname] = true;
        }
        if (count($this->insertqueue) >= $this->insertqueuelimit) {
            $this->flush_insert_queue();
        }
    }

    /**
     * Write all queued records with bulk inserts and store the mappings of their new ids.
     */
    public function flush_insert_queue(): void {
        global $DB;

        if (!$this->insertqueue) {
            return;
        }
        $queue = $this->insertqueue;
        $this->insertqueue = [];
        $this->insertqueuemappings = [];

        // Consecutive records of the same table and structure are written with one bulk insert, keeping their order.
        $batch = [];
        $batchkey = null;
        $batchtable = null;
        $mappeditems = [];
        foreach ($queue as $item) {
            $key = $item->table . ':' . implode(',', array_keys($item->record));
            if ($key !== $batchkey && $batch) {
                $DB->insert_records($batchtable, $batch);
                $batch = [];
            }
            $batchkey = $key;
            $batchtable = $item->table;
            $batch[] = $item->record;
            if ($item->mappingitemname !== null) {
                $mappeditems[$item->table . ':' . $item->parentfield][] = $item;
            }
        }
        $DB->insert_records($batchtable, $batch);

        foreach ($mappeditems as $items) {
            $this->store_queued_record_mappings($items);
        }
    }

    /**
     * Identify the new ids of bulk inserted records by their content and store their mappings.
     *
     * @param stdClass[] $items Queued records of the same table and parent field, in insert order.
     */
    private function store_queued_record_mappings(array $items): void {
        global $DB;

        $table = reset($items)->table;
        $parentfield = reset($items)->parentfield;
        $parentids = array_unique(array_map(fn($item) => $item->record[$parentfield], $items));

        // Load the candidate rows ordered by id, so normally the first unassigned row of a parent matches.
        $rowsbyparent = [];
        foreach (array_chunk($parentids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $rs = $DB->get_recordset_select($table, "$parentfield $insql", $params, 'id');
            foreach ($rs as $row) {
                if (!isset($this->insertqueueassignedids[$table][$row->id])) {
                    $rowsbyparent[$row->$parentfield][$row->id] = $row;
                }
            }
            $rs->close();
        }

        foreach ($items as $item) {
            $parentid = $item->record[$parentfield];
            $newid = null;
            foreach ($rowsbyparent[$parentid] ?? [] as $row) {
                if (self::queued_record_matches($item->record, $row)) {
                    $newid = $row->id;
                    break;
                }
            }
            if ($newid === null) {
                throw new restore_step_exception(
                    'queued_record_not_found',
                    (object) ['table' => $table, 'oldid' => $item->oldid],
                );
            }
            unset($rowsbyparent[$parentid][$newid]);
            $this->insertqueueassignedids[$table][$newid] = true;
            $this->set_mapping($item->mappingitemname, $item->oldid, $newid);
        }
    }

    /**
     * Check whether a row read from the database contains the values of a queued record.
     *
     * @param array $record The queued record.
     * @param stdClass $row The row from the database.
     * @return bool True if all values of the record match.
     */
    private static function queued_record_matches(array $record, stdClass $row): bool {
        foreach ($record as $field => $value) {
            $dbvalue = $row->$field;
            if (is_numeric($dbvalue) && (is_numeric($value) || is_bool($value) || $value === '' || $value === null)) {
                // Numbers may have been normalised or rounded by the database.
                if (abs((float) $dbvalue - (float) $value) > 0.000001) {
                    return false;
                }
            } else if ((string) $dbvalue !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Add all the existing file, given their component and filearea and one backup_ids itemname to match with
     */
    public function add_related_files($component, $filearea, $mappingitemname, $filesctxid = null, $olditemid = null) {
        // If the current progress object is set up and ready to receive
        // indeterminate progress, then use it, otherwise don't. (This check is
        // just in case this function is ever called from somewhere not within
        // the execute() method here, which does set up progress like this.)
        $progress = $this->get_task()->get_progress();
        if (!$progress->is_in_progress_section() ||
                $progress->get_current_max() !== \core\progress\base::INDETERMINATE) {
            $progress = null;
        }

        $filesctxid = is_null($filesctxid) ? $this->task->get_old_contextid() : $filesctxid;
        $results = restore_dbops::send_files_to_pool($this->get_basepath(), $this->get_restoreid(), $component,
                $filearea, $filesctxid, $this->task->get_userid(), $mappingitemname, $olditemid, null, false,
                $progress);
        $resultstoadd = array();
        foreach ($results as $result) {
            $this->log($result->message, $result->level);
            $resultstoadd[$result->code] = true;
        }
        $this->task->add_result($resultstoadd);
    }

    /**
     * As far as restore structure steps are implementing restore_plugin stuff, they need to
     * have the parent task available for wrapping purposes (get course/context....)
     * @return restore_task|null
     */
    public function get_task() {
        return $this->task;
    }

// Protected API starts here

    /**
     * Add plugin structure to any element in the structure restore tree
     *
     * @param string $plugintype type of plugin as defined by core_component::get_plugin_types()
     * @param restore_path_element $element element in the structure restore tree that
     *                                       we are going to add plugin information to
     */
    protected function add_plugin_structure($plugintype, $element) {

        global $CFG;

        // Check the requested plugintype is a valid one
        if (!array_key_exists($plugintype, core_component::get_plugin_types($plugintype))) {
             throw new restore_step_exception('incorrect_plugin_type', $plugintype);
        }

        // Get all the restore path elements, looking across all the plugin dirs
        $pluginsdirs = core_component::get_plugin_list($plugintype);
        foreach ($pluginsdirs as $name => $pluginsdir) {
            // We need to add also backup plugin classes on restore, they may contain
            // some stuff used both in backup & restore
            $backupclassname = 'backup_' . $plugintype . '_' . $name . '_plugin';
            $backupfile = $pluginsdir . '/backup/moodle2/' . $backupclassname . '.class.php';
            if (file_exists($backupfile)) {
                require_once($backupfile);
            }
            // Now add restore plugin classes and prepare stuff
            $restoreclassname = 'restore_' . $plugintype . '_' . $name . '_plugin';
            $restorefile = $pluginsdir . '/backup/moodle2/' . $restoreclassname . '.class.php';
            if (file_exists($restorefile)) {
                require_once($restorefile);
                $restoreplugin = new $restoreclassname($plugintype, $name, $this);
                // Add plugin paths to the step
                $this->prepare_pathelements($restoreplugin->define_plugin_structure($element));
            }
        }
    }

    /**
     * Add subplugin structure for a given plugin to any element in the structure restore tree
     *
     * This method allows the injection of subplugins (of a specific plugin) parsing and proccessing
     * to any element in the restore structure.
     *
     * NOTE: Initially subplugins were only available for activities (mod), so only the
     * {@link restore_activity_structure_step} class had support for them, always
     * looking for /mod/modulenanme subplugins. This new method is a generalization of the
     * existing one for activities, supporting all subplugins injecting information everywhere.
     *
     * @param string $subplugintype type of subplugin as defined in plugin's db/subplugins.json.
     * @param restore_path_element $element element in the structure restore tree that
     *                              we are going to add subplugin information to.
     * @param string $plugintype type of the plugin.
     * @param string $pluginname name of the plugin.
     * @return void
     */
    protected function add_subplugin_structure($subplugintype, $element, $plugintype = null, $pluginname = null) {
        global $CFG;
        // This global declaration is required, because where we do require_once($backupfile);
        // That file may in turn try to do require_once($CFG->dirroot ...).
        // That worked in the past, we should keep it working.

        // Verify if this is a BC call for an activity restore. See NOTE above for this special case.
        if ($plugintype === null and $pluginname === null) {
            $plugintype = 'mod';
            $pluginname = $this->task->get_modulename();
            // TODO: Once all the calls have been changed to add both not null plugintype and pluginname, add a debugging here.
        }

        // Check the requested plugintype is a valid one.
        if (!array_key_exists($plugintype, core_component::get_plugin_types())) {
            throw new restore_step_exception('incorrect_plugin_type', $plugintype);
        }

        // Check the requested pluginname, for the specified plugintype, is a valid one.
        if (!array_key_exists($pluginname, core_component::get_plugin_list($plugintype))) {
            throw new restore_step_exception('incorrect_plugin_name', array($plugintype, $pluginname));
        }

        // Check the requested subplugintype is a valid one.
        $subplugins = core_component::get_subplugins("{$plugintype}_{$pluginname}");
        if (null === $subplugins) {
            throw new restore_step_exception('plugin_missing_subplugins_configuration', array($plugintype, $pluginname));
        }
        if (!array_key_exists($subplugintype, $subplugins)) {
             throw new restore_step_exception('incorrect_subplugin_type', $subplugintype);
        }

        // Every subplugin optionally can have a common/parent subplugin
        // class for shared stuff.
        $parentclass = 'restore_' . $plugintype . '_' . $pluginname . '_' . $subplugintype . '_subplugin';
        $parentfile = core_component::get_component_directory($plugintype . '_' . $pluginname) .
            '/backup/moodle2/' . $parentclass . '.class.php';
        if (file_exists($parentfile)) {
            require_once($parentfile);
        }

        // Get all the restore path elements, looking across all the subplugin dirs.
        $subpluginsdirs = core_component::get_plugin_list($subplugintype);
        foreach ($subpluginsdirs as $name => $subpluginsdir) {
            $classname = 'restore_' . $subplugintype . '_' . $name . '_subplugin';
            $restorefile = $subpluginsdir . '/backup/moodle2/' . $classname . '.class.php';
            if (file_exists($restorefile)) {
                require_once($restorefile);
                $restoresubplugin = new $classname($subplugintype, $name, $this);
                // Add subplugin paths to the step.
                $this->prepare_pathelements($restoresubplugin->define_subplugin_structure($element));
            }
        }
    }

    /**
     * Launch all the after_execute methods present in all the processing objects
     *
     * This method will launch all the after_execute methods that can be defined
     * both in restore_plugin and restore_structure_step classes
     *
     * For restore_plugin classes the name of the method to be executed will be
     * "after_execute_" + connection point (as far as can be multiple connection
     * points in the same class)
     *
     * For restore_structure_step classes is will be, simply, "after_execute". Note
     * that this is executed *after* the plugin ones
     */
    protected function launch_after_execute_methods() {
        $alreadylaunched = array(); // To avoid multiple executions
        foreach ($this->pathelements as $key => $pathelement) {
            // Get the processing object
            $pobject = $pathelement->get_processing_object();
            // Skip null processors (child of grouped ones for sure)
            if (is_null($pobject)) {
                continue;
            }
            // Skip restore structure step processors (this)
            if ($pobject instanceof restore_structure_step) {
                continue;
            }
            // Skip already launched processing objects
            if (in_array($pobject, $alreadylaunched, true)) {
                continue;
            }
            // Add processing object to array of launched ones
            $alreadylaunched[] = $pobject;
            // If the processing object has support for
            // launching after_execute methods, use it
            if (method_exists($pobject, 'launch_after_execute_methods')) {
                $pobject->launch_after_execute_methods();
            }
        }
        // Finally execute own (restore_structure_step) after_execute method
        $this->after_execute();

    }

    /**
     * Launch all the after_restore methods present in all the processing objects
     *
     * This method will launch all the after_restore methods that can be defined
     * both in restore_plugin class
     *
     * For restore_plugin classes the name of the method to be executed will be
     * "after_restore_" + connection point (as far as can be multiple connection
     * points in the same class)
     */
    public function launch_after_restore_methods() {
        $alreadylaunched = array(); // To avoid multiple executions
        foreach ($this->pathelements as $pathelement) {
            // Get the processing object
            $pobject = $pathelement->get_processing_object();
            // Skip null processors (child of grouped ones for sure)
            if (is_null($pobject)) {
                continue;
            }
            // Skip restore structure step processors (this)
            if ($pobject instanceof restore_structure_step) {
                continue;
            }
            // Skip already launched processing objects
            if (in_array($pobject, $alreadylaunched, true)) {
                continue;
            }
            // Add processing object to array of launched ones
            $alreadylaunched[] = $pobject;
            // If the processing object has support for
            // launching after_restore methods, use it
            if (method_exists($pobject, 'launch_after_restore_methods')) {
                $pobject->launch_after_restore_methods();
            }
        }
        // Finally execute own (restore_structure_step) after_restore method
        $this->after_restore();
    }

    /**
     * This method will be executed after the whole structure step have been processed
     *
     * After execution method for code needed to be executed after the whole structure
     * has been processed. Useful for cleaning tasks, files process and others. Simply
     * overwrite in in your steps if needed
     */
    protected function after_execute() {
        // do nothing by default
    }

    /**
     * This method will be executed after the rest of the restore has been processed.
     *
     * Use if you need to update IDs based on things which are restored after this
     * step has completed.
     */
    protected function after_restore() {
        // do nothing by default
    }

    /**
     * Prepare the pathelements for processing, looking for duplicates, applying
     * processing objects and other adjustments
     */
    protected function prepare_pathelements($elementsarr) {

        // First iteration, push them to new array, indexed by name
        // detecting duplicates in names or paths
        $names = array();
        $paths = array();
        foreach($elementsarr as $element) {
            if (!$element instanceof restore_path_element) {
                throw new restore_step_exception('restore_path_element_wrong_class', get_class($element));
            }
            if (array_key_exists($element->get_name(), $names)) {
                throw new restore_step_exception('restore_path_element_name_alreadyexists', $element->get_name());
            }
            if (array_key_exists($element->get_path(), $paths)) {
                throw new restore_step_exception('restore_path_element_path_alreadyexists', $element->get_path());
            }
            $names[$element->get_name()] = true;
            $paths[$element->get_path()] = $element;
        }
        // Now, for each element not having one processing object, if
        // not child of grouped element, assign $this (the step itself) as processing element
        // Note method must exist or we'll get one @restore_path_element_exception
        foreach ($paths as $pelement) {
            if ($pelement->get_processing_object() === null && !$this->grouped_parent_exists($pelement, $paths)) {
                $pelement->set_processing_object($this);
            }
            // Populate $elementsoldid and $elementsoldid based on available pathelements
            $this->elementsoldid[$pelement->get_name()] = null;
            $this->elementsnewid[$pelement->get_name()] = null;
        }
        // Done, add them to pathelements (dupes by key - path - are discarded)
        $this->pathelements = array_merge($this->pathelements, $paths);
    }

    /**
     * Given one pathelement, return true if grouped parent was found
     *
     * @param restore_path_element $pelement the element we are interested in.
     * @param restore_path_element[] $elements the elements that exist.
     * @return bool true if this element is inside a grouped parent.
     */
    public function grouped_parent_exists($pelement, $elements) {
        foreach ($elements as $element) {
            if ($pelement->get_path() == $element->get_path()) {
                continue; // Don't compare against itself.
            }
            // If element is grouped and parent of pelement, return true.
            if ($element->is_grouped() and strpos($pelement->get_path() .  '/', $element->get_path()) === 0) {
                return true;
            }
        }
        return false; // No grouped parent found.
    }

    /**
     * To conditionally decide if one step will be executed or no
     *
     * For steps needing to be executed conditionally, based in dynamic
     * conditions (at execution time vs at declaration time) you must
     * override this function. It will return true if the step must be
     * executed and false if not
     */
    protected function execute_condition() {
        return true;
    }

    /**
     * Function that will return the structure to be processed by this restore_step.
     * Must return one array of @restore_path_element elements
     */
    abstract protected function define_structure();
}
