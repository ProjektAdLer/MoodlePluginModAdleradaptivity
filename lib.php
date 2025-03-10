<?php

use core\di;
use core_completion\api as completion_api;
use local_logging\logger;
use mod_adleradaptivity\local\db\adleradaptivity_attempt_repository;
use mod_adleradaptivity\local\db\adleradaptivity_question_repository;
use mod_adleradaptivity\local\db\adleradaptivity_repository;
use mod_adleradaptivity\local\db\adleradaptivity_task_repository;
use mod_adleradaptivity\event\course_module_viewed;


/**
 * Return if the plugin supports $feature.
 *
 * @param string $feature Constant representing the feature.
 * @return string|true|null True if the feature is supported, null otherwise. Or a string for FEATURE_MOD_PURPOSE (moodle logic ...)
 */
function adleradaptivity_supports(string $feature): bool|string|null {
    switch ($feature) {
//        case FEATURE_COMPLETION_TRACKS_VIEWS:  // seems to add the "Require view" checkbox to the "when conditions are met" in the "activity completion" section of the activity settings
        case FEATURE_COMPLETION_HAS_RULES:  // custom completion rules
        case FEATURE_USES_QUESTIONS:
        case FEATURE_MOD_INTRO:
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:  # new since moodle 4.0 https://moodledev.io/docs/4.1/devupdate#activity-icons
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/** The [modname]_add_instance() function is called when the activity
 * creation form is submitted. This function is only called when adding
 * an activity and should contain any logic required to add the activity.
 *
 * @param $instancedata
 * @param $mform
 * @return int
 * @throws dml_exception
 */
function adleradaptivity_add_instance($instancedata, $mform = null): int {
    $instancedata->timemodified = time();

    $id = di::get(adleradaptivity_repository::class)->create_adleradaptivity($instancedata);

    // Update completion date event. This is a default feature activated for all modules (create module -> Activity completion).
    $completiontimeexpected = !empty($instancedata->completionexpected) ? $instancedata->completionexpected : null;
    completion_api::update_completion_date_event($instancedata->coursemodule, 'adleradaptivity', $id, $completiontimeexpected);

    return $id;
}

/** The [modname]_update_instance() function is called when the activity
 * editing form is submitted.
 *
 * @param $moduleinstance
 * @param null $mform
 * @return bool
 * @throws moodle_exception
 */
function adleradaptivity_update_instance($moduleinstance, $mform = null): bool {
    throw new moodle_exception('unsupported', 'adleradaptivity', '', 'update_instance() is not supported');
}

/** The adleradaptivity_delete_instance() function is called when the activity
 * deletion is confirmed. It is responsible for removing all data associated
 * with the instance.
 * questions itself are not deleted here as they belong to the course, not to the module. The adleradaptivity_questions are deleted.
 *
 * @param $instance_id int The instance id of the module to delete.
 * @return bool true if success, false if failed.
 * @throws dml_transaction_exception if the transaction failed and could not be rolled back.
 * @throws dml_exception
 */
function adleradaptivity_delete_instance(int $instance_id): bool {
    $logger = new logger('mod_adleradaptivity', 'lib.php');
    $adleradaptivity_attempt_repository = di::get(adleradaptivity_attempt_repository::class);
    $adleradaptivity_tasks_repository = di::get(adleradaptivity_task_repository::class);
    $adleradaptivity_question_repository = di::get(adleradaptivity_question_repository::class);
    $adleradaptivity_repository = di::get(adleradaptivity_repository::class);


    // there is no transaction above this level. Unsuccessful deletions basically result in unpredictable
    // behaviour. This at least ensures this module is either deleted completely or not at all.
    $transaction = di::get(moodle_database::class)->start_delegated_transaction();

    try {
        // first ensure that the module instance exists
        $adleradaptivity_repository->get_instance_by_instance_id($instance_id);

        // load all attempts related to $instance_id
        $cm = get_coursemodule_from_instance('adleradaptivity', $instance_id, 0, false, MUST_EXIST);
        $attempts = $adleradaptivity_attempt_repository->get_adleradaptivity_attempt_by_cmid($cm->id);
        // delete all attempts
        foreach ($attempts as $attempt) {
            $adleradaptivity_attempt_repository->delete_adleradaptivity_attempt_by_question_usage_id($attempt->attempt_id);
            question_engine::delete_questions_usage_by_activity($attempt->attempt_id);
        }

        // delete the module itself and all related tasks and questions
        // load required data
        $adler_tasks = $adleradaptivity_tasks_repository->get_tasks_by_adleradaptivity_id($instance_id);
        $adler_questions = [];
        foreach ($adler_tasks as $task) {
            $adler_questions = array_merge(
                $adler_questions,
                $adleradaptivity_question_repository->get_adleradaptivity_questions_with_moodle_question_id($task->id, true)
            );
        }
        // perform deletion
        foreach ($adler_questions as $question) {
            $adleradaptivity_question_repository->delete_question_by_id($question->id);
        }
        foreach ($adler_tasks as $task) {
            $adleradaptivity_tasks_repository->delete_task_by_id($task->id);
        }
        $adleradaptivity_repository->delete_adleradaptivity_by_id($instance_id);

        $transaction->allow_commit();
    } catch (Throwable $e) {
        $logger->error('Could not delete adleradaptivity instance with id ' . $instance_id);
        $logger->error($e->getMessage());
        // although the existing documentation suggests this method should return true|false depending
        // on whether the deletion succeeded, it seems to be "better" to throw exceptions.
        // - other code is doing it like that
        // - I am unsure whether the rollback behaviour is correct for all databases without exceptions
        // - course deletion succeeds without indication of an error
        // - only module deletion behaves as expected and shows an error
        $transaction->rollback($e);
    }

    return true;
}


/**
 * Add a get_coursemodule_info function to add 'extra' information
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info|false An object on information that the courses
 *                        will know about (most noticeably, an icon).
 * @throws dml_exception
 */
function adleradaptivity_get_coursemodule_info(stdClass $coursemodule): cached_cm_info|bool {
    $adleradaptivity_repository = di::get(adleradaptivity_repository::class);

    if (!$cm = $adleradaptivity_repository->get_instance_by_instance_id($coursemodule->instance)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $cm->name;

    // This populates the description field in the course overview
    if ($coursemodule->showdescription) {
        $result->content = format_module_intro('adleradaptivity', $cm, $coursemodule->id, false);
    }

    // for some inexplicable reason moodle requires an object for each custom rule defined in get_defined_custom_rules(),
    // otherwise they at least don't fully work or even don't work at all.
    // If missing the completion requirement (completion rule) is not shown in the "completion list" in course overview and
    // completing the activity that has this rule active did not work in a short test.
    // As I don't have any data to append to the course_module object for my rule I just use some random data.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['default_rule'] = "some random string to make the completion rule work";
    }
    return $result;
}


/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * I am unaware of any source documenting the <modname>_view function,
 * but it seems to be common practice to do it like that.
 *
 * @param stdClass $adleradaptivity adleradaptivity object
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param context_module $context context object
 * @throws coding_exception
 */
function adleradaptivity_view(stdClass $adleradaptivity, stdClass $course, stdClass $cm, context_module $context): void {

    $params = [
        'objectid' => $adleradaptivity->id,
        'context' => $context
    ];

    $event = course_module_viewed::create($params);
    $event->add_record_snapshot('adleradaptivity', $adleradaptivity);
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}


// --------
// methods required according to mod/README.md
// --------
// The following ones are actually not required and not implemented as they are not needed for the functionality of this module.
//
//
///**
// * Given a course and a date, prints a summary of the recent activity happened in this module.
// * As mod_adleradaptivity does not implement this method i assume it is not required.
// *
// * It is shown eg in in the "recent activity" sidebar block.
// * The actual output of this method (what is shown in the "recent activity") is outputted via echo.
// * Return value is just "success"
// *
// * @uses CONTEXT_MODULE
// * @param object $course
// * @param bool $viewfullnames capability
// * @param int $timestart
// * @return bool success
// */
//function adleradaptivity_print_recent_activity($course, $viewfullnames, $timestart) {
//    echo "(The recent activity of this modul)>";
//    return true;
//}
//
//The functions xxx_user_outline() and xxx_user_complete() have been removed from the majority of core modules (see MDL-41286),
//except for those that require unique functionality. These functions are used by the outline report, but now if they no longer
//exist, the default behaviour is chosen, which supports the legacy and standard log storages introduced in 2.7 (see MDL-41266).
//It is highly recommended you remove these functions from your module if they are simply performing the default behaviour.
//
///**
// *  Print a detailed representation of what a user has done with
// *  a given particular instance of this module, for user activity reports.
// *
// * @param $course
// * @param $user
// * @param $mod
// * @param stdClass $adleradaptivity database record of the module instance
// * @return void
// */
//function adleradaptivity_user_complete($course, $user, $mod, $adleradaptivity) {}
//
///**
// * Return a small object with summary information about what a
// * user has done with a given particular instance of this module
// * Used for user activity reports.
// * $return->time = the time they did it
// * $return->info = a short text description
// *
// * @param stdClass $course
// * @param stdClass $user
// * @param cm_info|stdClass $mod
// * @param stdClass $feedback
// * @return stdClass
// */
//function feedback_user_outline($course, $user, $mod, $feedback) {}
