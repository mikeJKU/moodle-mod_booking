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
 * Booking answers scope class.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_answers\scopes;

use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\table\manageusers_table;
use moodle_url;

/**
 * Class for booking answers.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class alloptions extends option {
    /**
     * Render users table based on status param
     *
     * @param string $scope
     * @param int $scopeid
     * @param int $statusparam
     * @param string $tablenameprefix
     * @param array $columns
     * @param array $headers
     * @param bool $sortable
     * @param bool $paginate
     * @return ?string
     */
    public function return_users_table(
        string $scope,
        int $scopeid,
        int $statusparam,
        string $tablenameprefix,
        array $columns,
        array $headers = [],
        bool $sortable = false,
        bool $paginate = false
    ): wunderbyte_table {
        [$fields, $from, $where, $params] = $this->return_sql_for_booked_users($scope, $scopeid, $statusparam);

        $tablename = "{$tablenameprefix}_{$scope}_{$scopeid}";
        $table = new manageusers_table($tablename);

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        // Todo: $table->define_baseurl() ...
        $table->define_cache('mod_booking', "bookedusertable");
        $table->define_columns($columns);
        $table->define_headers($headers);

        if ($sortable) {
            $table->sortablerows = true;
        }

        if ($paginate) {
            $table->use_pages = true;
        }

        $table->set_sql($fields, $from, $where, $params);

        $this->show_download_button($table, $scope, $scopeid, $statusparam);

        $table->define_fulltextsearchcolumns(['firstname', 'lastname', 'email']);
        switch ($statusparam) {
            case MOD_BOOKING_STATUSPARAM_DELETED:
                $sortablecolumns = [
                    'firstname' => get_string('firstname'),
                    'lastname' => get_string('lastname'),
                    'email' => get_string('email'),
                    'timemodified' => get_string('timemodified', 'mod_booking'),
                ];
                $table->sort_default_column = 'timemodified';
                $table->sort_default_order = SORT_DESC;
                break;
            case MOD_BOOKING_STATUSPARAM_BOOKED:
                $sortablecolumns = [
                    'firstname' => get_string('firstname'),
                    'lastname' => get_string('lastname'),
                    'email' => get_string('email'),
                    'status' => get_string('presence', 'mod_booking'),
                    'presencecount' => get_string('presencecount', 'mod_booking'),
                ];
                $table->sort_default_column = 'lastname';
                $table->sort_default_order = SORT_ASC;
                break;
            case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                if (
                    get_config('booking', 'waitinglistshowplaceonwaitinglist')
                    && $scope === 'option'
                ) {
                    $sortablecolumns = [];
                    $table->sort_default_column = 'userrank';
                    $table->sort_default_order = SORT_ASC;
                } else {
                    $sortablecolumns = [
                        'firstname' => get_string('firstname'),
                        'lastname' => get_string('lastname'),
                        'email' => get_string('email'),
                    ];
                    $table->sort_default_column = 'lastname';
                    $table->sort_default_order = SORT_ASC;
                }
                break;
            default:
                $sortablecolumns = [
                    'firstname' => get_string('firstname'),
                    'lastname' => get_string('lastname'),
                    'email' => get_string('email'),
                ];
                $table->sort_default_column = 'lastname';
                $table->sort_default_order = SORT_ASC;
                break;
        }

        $table->define_sortablecolumns($sortablecolumns);

        return $table;
    }

    /**
     * Returns the sql to fetch booked users with a certain status.
     * Orderd by timemodified, to be able to sort them.
     * @param string $scope option | instance | course | system
     * @param int $scopeid optionid | cmid | courseid | 0
     * @param int $statusparam
     * @return array
     */
    public function return_sql_for_booked_users(string $scope, int $scopeid, int $statusparam): array {
        $where = " 1 = 1 ";
        $params['statusparam'] = $statusparam;

        // If presence counter is activated, we add that to SQL.
        $selectpresencecount = '';
        $presencecountsqlpart = '';
        if (get_config('booking', 'bookingstrackerpresencecounter')) {
            $selectpresencecount = 'pcnt.presencecount,';
            $presencecountsqlpart =
                "LEFT JOIN (
                    SELECT boda.optionid, boda.userid, COUNT(*) AS presencecount
                    FROM {booking_optiondates_answers} boda
                    WHERE boda.status = :statustocount
                    GROUP BY boda.optionid, boda.userid
                ) pcnt
                ON pcnt.optionid = ba.optionid AND pcnt.userid = u.id";
            $params['statustocount'] = get_config('booking', 'bookingstrackerpresencecountervaluetocount');
        }

        // Only for waiting list, we need to add the rank.
        $ranksqlpart = '';
        $orderby = ' ORDER BY lastname, firstname, timemodified ASC';
        if (
            $statusparam == MOD_BOOKING_STATUSPARAM_WAITINGLIST
            && $scope === 'option'
        ) {
            // For waiting list, we need to determine the rank order.
            $ranksqlpart = ", (
                SELECT COUNT(*)
                FROM (
                    SELECT
                        ba.id,
                        ba.timemodified
                    FROM {booking_answers} ba
                    WHERE ba.waitinglist=:statusparam2
                ) s3
                WHERE (s3.timemodified < s2.timemodified) OR (s3.timemodified = s2.timemodified AND s3.id <= s2.id)
            ) AS userrank";
            $orderby = ' ORDER BY userrank ASC';

            // Params for rank order.
            $params['statusparam2'] = $statusparam;
        }

        // We need to set a limit for the query in mysqlfamily.
        $fields = 's1.*';
        $from = "
        (
            SELECT s2.* $ranksqlpart
            FROM (
                SELECT
                    ba.id,
                    u.id AS userid,
                    u.username,
                    u.firstname,
                    u.lastname,
                    u.email,
                    ba.waitinglist,
                    ba.status,
                    ba.notes,
                    $selectpresencecount
                    ba.timemodified,
                    ba.timecreated,
                    ba.optionid,
                    ba.json,
                    '" . $scope . "' AS scope
                FROM {booking_answers} ba
                JOIN {user} u ON ba.userid = u.id
                $presencecountsqlpart
                WHERE ba.waitinglist=:statusparam
                LIMIT 1000000
            ) s2
            $orderby
        ) s1";

        return [$fields, $from, $where, $params];
    }

    /**
     * Each scope can decide under which circumstances it actually adds the downloadbutton.
     *
     * @param wunderbyte_table $table
     * @param string $scope
     * @param int $scopeid
     * @param int $statusparam
     *
     * @return void
     *
     */
    public function show_download_button(wunderbyte_table &$table, string $scope, int $scopeid, int $statusparam) {
        if ($this->has_capability_in_scope($scopeid, 'mod/booking:updatebooking')) {
            $baseurl = new moodle_url(
                '/mod/booking/download_report2.php',
                [
                    'scope' => self::return_classname(),
                    'statusparam' => $statusparam,
                ]
            );
            $table->define_baseurl($baseurl);

            // We currently support download for booked users only.
            if ($statusparam == 0) {
                $table->showdownloadbutton = true;
                $table->showdownloadbuttonatbottom = true;
            }
        }
    }

    /**
     * Return classname.
     *
     * @return string
     *
     */
    public static function return_classname(): string {
        $classnamewith = static::class;
        $classparts = explode('\\', $classnamewith);
        $classname = end($classparts);
        return $classname;
    }

    /**
     * Helper function to check capability for logged-in user in provided scope.
     * @param int $scopeid
     * @param string $capability
     */
    public function has_capability_in_scope($scopeid, $capability) {
        return has_capability($capability, context_system::instance());
    }
}
