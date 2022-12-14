<?php
namespace local_adler\external;

use completion_info;
use context_course;
use dml_exception;
use dml_transaction_exception;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use invalid_parameter_exception;
use local_adler\dsl_score;
use local_adler\helpers;
use moodle_exception;
use restricted_context_exception;

class score_primitive_learning_element extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'module_id' => new external_value(PARAM_INT, 'moodle module id', VALUE_REQUIRED),
                'is_completed' => new external_value(PARAM_BOOL, '1: completed, 0: not completed', VALUE_REQUIRED),
            )
        );
    }

    public static function execute_returns() {
        return new  external_single_structure(
            array(
                'score' => new external_value(PARAM_FLOAT, 'achieved (dsl-file) score'),
            )
        );
    }

    /** creates dsl_score objects, simplifies testing
     * @param $course_module object course module object with field modname
     * @return dsl_score for currently logged in user
     */
    protected static function create_dsl_score_instance($course_module): dsl_score {
        return new dsl_score($course_module);
    }

    /**
     * @throws restricted_context_exception
     * @throws dml_transaction_exception
     * @throws moodle_exception
     * @throws invalid_parameter_exception
     */
    public static function execute($module_id, $is_completed) {
        // TODO: check if completion is enabled. If not nothing will happen but no error is thrown. See completionlib.php is_enabled(...)
        global $CFG;
        require_once("$CFG->libdir/completionlib.php");

        // Parameter validation
        $params = self::validate_parameters(self::execute_parameters(), array(
            'module_id' => $module_id,
            'is_completed' => $is_completed
        ));

        // create moodle course object $course
        try {
            $course_module = get_coursemodule_from_id(null, $params['module_id'], 0, false, MUST_EXIST);
        } catch (dml_exception $e) {
            // PHPStorm says this exception is never thrown, but this is wrong,
            // see test test_score_primitive_learning_element_course_module_not_exist
            throw new invalid_parameter_exception('failed_to_get_course_module');
        }
        $course_id = $course_module->course;
        $course = helpers::get_course_from_course_id($course_id);

        // security stuff https://docs.moodle.org/dev/Access_API#Context_fetching
        $context = context_course::instance($course_id);
        self::validate_context($context);

        // check if course_module is a primitive learning element. If it's supporting gradelib it might cause unexpected behaviour if manually setting completion state
        if (helpers::is_primitive_learning_element($course_module)) {
            // update completion status
            $new_completion_state = COMPLETION_INCOMPLETE;
            if ($params['is_completed']) {
                $new_completion_state = COMPLETION_COMPLETE;
            }
            $completion = new completion_info($course);
            $completion->update_state($course_module, $new_completion_state);

            // TODO: add module_id field to be consistent with h5p
            // return dsl score
            $dsl_score = static::create_dsl_score_instance($course_module);
            return [
                'score' => $dsl_score->get_score()
            ];
        } else {
            debugging("Course module is not a known primitive learning element.", E_WARNING);
            throw new moodle_exception("course_module_is_not_a_primitive_learning_element", 'local_adler');
        }
    }
}
