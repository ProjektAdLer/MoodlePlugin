<?php

namespace local_adler\external;

use coding_exception;
use context;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_adler\dsl_score;
use moodle_exception;


class score_h5p_learning_element extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'xapi' => new external_value(PARAM_RAW, 'xapi json payload for h5p module', VALUE_REQUIRED),
            )
        );
    }


    public static function execute_returns() {
        return new  external_multiple_structure(
            new external_single_structure(
                array(
                    'module_id' => new external_value(PARAM_INT, 'moodle module id'),
                    'score' => new external_value(PARAM_FLOAT, 'achieved (dsl-file) score'),
                )
            )
        );
    }


    /** Get array of all course_module ids of the given xapi event
     * @param $xapi string xapi json payload
     * @return array of course_module ids
     * @throws coding_exception
     */
    private static function get_module_ids_from_xapi(string $xapi): array {
        $xapi = json_decode($xapi);
        $module_ids = array();
        foreach ($xapi as $statement) {
            $url = explode('/', $statement->object->id);
            $context_id = end($url);
            $module_id = context::instance_by_id($context_id)->instanceid;
            // add module id to array if not already in it
            if (!in_array($module_id, $module_ids)) {
                $module_ids[] = $module_id;
            }
        }
        return $module_ids;
    }


    /** process xapi payload and return array of dsl_score objects
     * xapi payload is proxied to core xapi library
     * @param $xapi string xapi json payload
     * @return array of dsl_score objects
     * @throws moodle_exception
     */
    public static function execute($xapi): array {
        $params = self::validate_parameters(self::execute_parameters(), array(
            'xapi' => $xapi,
        ));
        $xapi = $params['xapi'];

        external_api::call_external_function('core_xapi_statement_post', array(
            'component' => 'h5pactivity',
            'requestjson' => $xapi
        ), true);

        // get dsl score
        $module_ids = static::get_module_ids_from_xapi($xapi);
        try {
            $scores = dsl_score::get_achieved_scores($module_ids);
        } catch (moodle_exception $e) {
            debugging('Failed to get DSL scores, but xapi statements are already processed', E_ERROR);
            throw new moodle_exception('error:failed_to_get_dsl_score', 'local_adler', '', $e->getMessage());
        }

        // convert $scores to return format
        $return = array();
        foreach ($scores as $module_id => $score) {
            $return[] = array(
                'module_id' => $module_id,
                'score' => $score,
            );
        }
        return $return;
    }
}
