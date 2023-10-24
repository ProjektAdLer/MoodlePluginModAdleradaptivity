<?php

namespace mod_adleradaptivity\external;

global $CFG;
require_once($CFG->dirroot . '/lib/externallib.php');
require_once($CFG->libdir . '/questionlib.php');

use completion_info;
use context_module;
use core_external\restricted_context_exception;
use dml_exception;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_value;
use external_single_structure;
use invalid_parameter_exception;
use mod_adleradaptivity\local\completion_helpers;
use mod_adleradaptivity\local\helpers;
use moodle_exception;
use question_engine;

class answer_questions extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'module' => new external_single_structure(
                    [
                        'module_id' => new external_value(
                            PARAM_TEXT,
                            'Either module_id or instance_id are required. Module_id of the adaptivity module',
                            VALUE_OPTIONAL
                        ),
                        'instance_id' => new external_value(
                            PARAM_TEXT,
                            'Either module_id or instance_id are required. Instance_id of the adaptivity module',
                            VALUE_OPTIONAL
                        ),
                    ]
                ),
                'questions' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'uuid' => new external_value(
                                PARAM_TEXT,
                                'UUID of the question',
                            ),
                            'answer' => new external_value(
                                PARAM_TEXT,
                                'JSON encoded data containing the question answer. For example for a multiple choice question: [false, false, true, false]. null if the question was not attempted.',
                            ),
                        ]
                    )
                ),
            ]
        );
    }

    public static function execute_returns(): external_function_parameters {
        return new external_function_parameters([
            'data' => new external_single_structure(
                [
                    'module' => new external_single_structure(
                        [
                            'module_id' => new external_value(
                                PARAM_TEXT,
                                'Either module_id or instance_id are required. Module_id of the adaptivity module',
                                VALUE_OPTIONAL
                            ),
                            'instance_id' => new external_value(
                                PARAM_TEXT,
                                'Either module_id or instance_id are required. Instance_id of the adaptivity module',
                                VALUE_OPTIONAL
                            ),
                            "status" => new external_value(
                                PARAM_TEXT,
                                "Status of the Task, one of correct, incorrect"
                            ),
                        ]
                    ),
                    'tasks' => new external_multiple_structure(
                        new external_single_structure(
                            [
                                "uuid" => new external_value(
                                    PARAM_TEXT,
                                    "UUID of the task"
                                ),
                                "status" => new external_value(
                                    PARAM_TEXT,
                                    "Status of the Task, one of correct, incorrect"
                                ),
                            ]
                        )
                    ),
                    'questions' => new external_multiple_structure(
                        new external_single_structure(
                            [
                                "uuid" => new external_value(
                                    PARAM_TEXT,
                                    "UUID of the question"
                                ),
                                "status" => new external_value(
                                    PARAM_TEXT,
                                    "Status of the Task, one of correct, incorrect, notAttempted"
                                ),
                                "answers" => new external_value(
                                    PARAM_TEXT,
                                    "JSON encoded data containing the question answer. For example for a multiple choice question: array of objects with the fields 'checked' and 'user_answer_correct'. null if the question was not attempted."
                                ),
                                // TODO two status: status_current_try and status_highest_attempt
                            ]
                        )
                    ),
                ]
            )
        ]);
    }

    /**
     * @param array $module [int $module_id, string $instance_id]
     * @param array $questions [array $question]
     * @throws invalid_parameter_exception
     * @throws dml_exception
     * @throws restricted_context_exception If the context is not valid (user is not allowed to access the module)
     * @throws moodle_exception
     */
    public static function execute(array $module, array $questions): array {
        global $DB;
        $time_at_request_start = time();  // save time here to ensure users are not disadvantaged if processing takes a longer

        // Parameter validation
        $params = self::validate_parameters(self::execute_parameters(), array('module' => $module, 'questions' => $questions));
        $module = $params['module'];
        $questions = $params['questions'];

        $module = external_helpers::validate_module_params_and_get_module($module);
        $module_id = $module->id;
        $instance_id = $module->instance;

        // default validation stuff with context
        $context = context_module::instance($module_id);
        static::validate_context($context);


        // validate all questions are in the given module and save question_bank_entry and task in $questions for later use
        foreach ($questions as $key => $question) {
            try {
                $adleradaptivity_task = external_helpers::get_task_by_question_uuid($question['uuid'], $instance_id);
            } catch (moodle_exception $e) {
                throw new invalid_parameter_exception('Question with uuid ' . $question['uuid'] . ' does not exist.');
            }

            // save $adleradaptivity_task for later use
            $questions[$key]['task'] = $adleradaptivity_task;
        }


        // load attempt
        $quba = helpers::load_or_create_question_usage($module_id);

        // start delegating transaction
        $transaction = $DB->start_delegated_transaction();

        // start processing the questions
        foreach ($questions as $key => $question) {
            // load question object
            $question['question_object'] = $quba->get_question(helpers::get_slot_number_by_uuid($question['uuid'], $quba));

            // switch case over question types. For now only multichoice is supported
            // reformat answer from api format to question type format
            switch (get_class($question['question_object']->qtype)) {
                case 'qtype_multichoice':
                    // process multichoice question
                    $is_single = get_class($question['question_object']) == 'qtype_multichoice_single_question';
                    $question['formatted_answer'] = static::format_multichoice_answer(
                        $question['answer'],
                        $is_single,
                        count($question['question_object']->answers)
                    );
                    break;
                default:
                    throw new invalid_parameter_exception('Question type ' . get_class($question['question_object']->qtype) . ' is not supported.');
            }

            // now the formatted answer can be processed like it came from the web interface
            // Also note that answer shuffling is (has to be) disabled for all questions in this module
            $quba->process_action(
                helpers::get_slot_number_by_uuid($question['uuid'], $quba),
                $question['formatted_answer'],
                $time_at_request_start
            );
        }

        // save current questions usage
        question_engine::save_questions_usage_by_activity($quba);

        // Update completion state
        $course = get_course($module->course);
        $completion = new completion_info($course);
        if ($completion->is_enabled($module)) {
            // possibleresult: COMPLETION_COMPLETE prevents setting the completion state to incomplete after it was set to complete
            $completion->update_state($module, COMPLETION_COMPLETE);
        } else {
            throw new moodle_exception('Completion is not enabled for this module.');
        }

        // allow commit
        $transaction->allow_commit();


        // check completion state of questions, tasks and module
        // completion state of module
        $module_completion = ($completion->get_data($module)->completionstate == COMPLETION_COMPLETE || $completion->get_data($module)->completionstate == COMPLETION_COMPLETE_PASS)
            ? 'correct'
            : 'incorrect';

        // completion state of tasks
        $tasks = [];
        foreach ($questions as $question) {
            // check whether $question['task'] is already in $tasks
            foreach ($tasks as $task) {
                if ($task['uuid'] == $question['task']->uuid) {
                    // if it is, skip this question
                    continue 2;
                }
            }
            $tasks[] = [
                'uuid' => $question['task']->uuid,
                'status' => completion_helpers::check_task_completed($quba, $question['task']) ? 'correct' : 'incorrect',
            ];
        }

        // completion state of questions
        $questions_completion = external_helpers::generate_question_response_data(array_column($questions, 'uuid'), $quba);

        return [
            'data' => [
                'module' => [
                    'module_id' => $module_id,
                    'instance_id' => $instance_id,
                    'status' => $module_completion,
                ],
                'tasks' => $tasks,
                'questions' => $questions_completion,
            ]
        ];
    }

    /** Converts the answers from our api format to the format the multichoice question type expects
     *
     * @param string $answer JSON encoded array of booleans
     * @param bool $is_single_choice Whether the question is single choice or not (multiple choice
     * @param int $number_of_choices Validate the number of choices the question should have. If it is not the same, throw an exception. If it is null, do not validate.
     * @return array answer string in multichoice format
     * @throws invalid_parameter_exception If the answer has invalid format after json_decode
     *
     *  Format for single choice:
     *  Single choice: $submitteddata = [
     *     '-submit' => "1",   // always set if submitted, otherwise this entry is missing
     *     'answer' => "1",    // selected answer in both cases, submitted and not submitted
     *     'answer' => "-1",   // This was never submitted (before)
     *  ]
     *
     *  Format for multiple choice:
     *  Multiple choice:
     *  $submitteddata = [
     *     '-submit' => "1",   // always set if submitted, otherwise this entry is missing
     *     'choice0' => "0",   // choices are there in all cases, submitted, not submitted and never submitted
     *     'choice1' => "1",
     *     'choice2' => "1",
     *     'choice3' => "0",
     *     'choice4' => "0",
     *  ] // submitted this question
     */
    private static function format_multichoice_answer(string $answer, bool $is_single_choice, int $number_of_choices = null) {
        // Answer shuffling is no problem because it is disabled for all attempts in this module

        $answers_array = json_decode($answer);

        // Check if decoded value is not only an array, but an array of booleans.
        // if answer is null is no mappable, throw exception
        if (!is_array($answers_array) || !self::all_elements_are_bool($answers_array)) {
            throw new invalid_parameter_exception('Answer has invalid format: ' . json_encode($answers_array));
        }

        // if $number_of_choices is set, check if the number of choices is correct
        if ($number_of_choices !== null && count($answers_array) != $number_of_choices) {
            throw new invalid_parameter_exception('Answer has invalid number of choices: ' . json_encode($answers_array));
        }

        $result = ['-submit' => "1"];

        if ($is_single_choice) {
            // if single choice, return the index of the first true value
            $true_index = array_search(true, $answers_array, true);

            if ($true_index === false) {
                throw new invalid_parameter_exception("Invalid answer, no \"true\" value found: " . json_encode($answers_array));
            }

            $result["answer"] = strval($true_index);
        } else {
            // iterate over all answers and set ['choice<index>'] = "1" if true, "0" if false
            foreach ($answers_array as $key => $value) {
                $result['choice' . $key] = $value ? "1" : "0";
            }
        }

        return $result;
    }

    /**
     * Helper function to check if all elements of an array are booleans.
     *
     * @param array $array The array to check.
     * @return bool True if all elements are booleans, false otherwise.
     */
    private static function all_elements_are_bool(array $array): bool {
        foreach ($array as $item) {
            if (!is_bool($item)) {
                return false;
            }
        }
        return true;
    }

}
