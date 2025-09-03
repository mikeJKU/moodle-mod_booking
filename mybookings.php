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
 * Handling my bookings page
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

// No guest autologin.
require_login(0, false);

use mod_booking\shortcodes;

$url = new moodle_url('/mod/booking/mybookings.php');
$userid = optional_param('userid', 0, PARAM_INT);
$PAGE->set_url($url);

$PAGE->set_context(context_user::instance($USER->id));
$PAGE->navigation->extend_for_user($USER);
$mybookingsurl = new moodle_url('/mod/booking/mybookings.php');
$PAGE->navbar->add(get_string('mybookingoptions', 'mod_booking'), $mybookingsurl);

$PAGE->set_pagelayout('base');

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('mybookingoptions', 'mod_booking'));

echo shortcodes::mycourselist('', ['userid' => $userid], '', (object)[], fn($a) => $a);

if (class_exists('local_shopping_cart\shopping_cart') && get_config('booking', 'displayshoppingcarthistory')) {
    echo local_shopping_cart\shortcodes::shoppingcarthistory('', [], '', (object)[], fn($a) => $a);
}

echo $OUTPUT->footer();
