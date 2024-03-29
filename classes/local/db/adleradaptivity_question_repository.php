<?php

namespace mod_adleradaptivity\local\db;

use dml_exception;
use moodle_database;
use stdClass;

class adleradaptivity_question_repository extends base_repository {
    /**
     * Get adleradaptivity question by question_bank_entries_id
     *
     * @param int $question_bank_entries_id
     * @return stdClass question object
     * @throws dml_exception
     */
    public function get_adleradaptivity_question_by_question_bank_entries_id(int $question_bank_entries_id) {
        $sql = "
            SELECT aq.*
            FROM {adleradaptivity_questions} aq
            JOIN {question_references} qr ON qr.itemid = aq.id
            WHERE qr.questionbankentryid = ?
        ";

        return $this->db->get_record_sql($sql, ['questionbankentryid' => $question_bank_entries_id], MUST_EXIST);
    }
}