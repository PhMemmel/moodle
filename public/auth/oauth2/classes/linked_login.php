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
 * Class for loading/storing issuers from the DB.
 *
 * @package    auth_oauth2
 * @copyright  2017 Damyon Wiese
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace auth_oauth2;

defined('MOODLE_INTERNAL') || die();

use core\clock;
use core\di;
use core\persistent;
use dml_exception;

/**
 * Class for loading/storing issuer from the DB
 *
 * @copyright  2017 Damyon Wiese
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class linked_login extends persistent {

    const TABLE = 'auth_oauth2_linked_login';

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties() {
        return array(
            'issuerid' => array(
                'type' => PARAM_INT
            ),
            'userid' => array(
                'type' => PARAM_INT
            ),
            'username' => array(
                'type' => PARAM_RAW
            ),
            'email' => array(
                'type' => PARAM_RAW
            ),
            'confirmtoken' => array(
                'type' => PARAM_RAW
            ),
            'confirmtokenexpires' => array(
                'type' => PARAM_INT
            )
        );
    }

    /**
     * Check whether there are any valid linked accounts for this issuer
     * and username combination.
     *
     * @param \core\oauth2\issuer $issuer The issuer
     * @param string $username The username to check
     */
    public static function has_existing_issuer_match(\core\oauth2\issuer $issuer, $username) {
        global $DB;

        $where = "issuerid = :issuerid
              AND username = :username
              AND (confirmtokenexpires = 0 OR confirmtokenexpires > :maxexpiry)";

        $count = $DB->count_records_select(static::TABLE, $where, [
            'issuerid' => $issuer->get('id'),
            'username' => $username,
            'maxexpiry' => (new \DateTime('NOW'))->getTimestamp(),
        ]);

        return $count > 0;
    }

    /**
     * Remove all linked logins that are using issuers that have been deleted.
     *
     * @param int $issuerid The issuer id of the issuer to check, or false to check all (defaults to all)
     * @return boolean
     */
    public static function delete_orphaned($issuerid = false) {
        global $DB;
        // Determine the issuer ids referenced by linked logins first. The DISTINCT query can be answered
        // from the issuerid index alone (loose index scan), so neither a full table scan nor a write
        // statement is needed in the common case where there are no orphaned linked logins.
        if (!empty($issuerid)) {
            $linkedissuerids = [$issuerid];
        } else {
            $linkedissuerids = $DB->get_fieldset_sql("SELECT DISTINCT issuerid FROM {" . self::TABLE . "}");
        }
        if (empty($linkedissuerids)) {
            return true;
        }

        [$insql, $params] = $DB->get_in_or_equal($linkedissuerids, SQL_PARAMS_NAMED);
        $existingissuerids = $DB->get_fieldset_select(\core\oauth2\issuer::TABLE, 'id', "id $insql", $params);
        $orphanedissuerids = array_diff($linkedissuerids, $existingissuerids);
        if (!empty($orphanedissuerids)) {
            $DB->delete_records_list(self::TABLE, 'issuerid', $orphanedissuerids);
        }
        return true;
    }

    /**
     * Delete expired confirmation tokens.
     *
     * @return void
     * @throws dml_exception
     */
    public static function delete_expired_confirmation_tokens(): void {
        global $DB;

        $where = 'confirmtokenexpires > 0 AND confirmtokenexpires < :now';
        $params = ['now' => di::get(clock::class)->now()->getTimestamp()];

        // Select the ids first with a non-locking read to avoid locking the entire table for the duration of the delete operation.
        $ids = $DB->get_fieldset_select(self::TABLE, 'id', $where, $params);

        // Delete by primary key in chunks, re-checking the expiry condition so that records confirmed
        // in the meantime are not deleted.
        foreach (array_chunk($ids, 1000) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $DB->delete_records_select(self::TABLE, "id $insql AND $where", $inparams + $params);
        }
    }

    /**
     * Delete an expired pending linked login record for a specific user, issuer, and username.
     *
     * @param \core\oauth2\issuer $issuer The issuer the pending record belongs to.
     * @param string $username The external username of the pending record.
     * @param int $userid The Moodle user ID the pending record belongs to.
     * @return void
     * @throws dml_exception
     */
    public static function delete_expired_pending(\core\oauth2\issuer $issuer, string $username, int $userid): void {
        global $DB;

        $where = "issuerid = :issuerid
              AND username = :username
              AND userid = :userid
              AND confirmtokenexpires <> 0
              AND confirmtokenexpires < :now";

        $DB->delete_records_select(static::TABLE, $where, [
            'issuerid' => $issuer->get('id'),
            'username' => $username,
            'userid' => $userid,
            'now' => di::get(clock::class)->now()->getTimestamp(),
        ]);
    }
}
