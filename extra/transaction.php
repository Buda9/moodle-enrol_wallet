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
 * wallet enrolment plugin transaction page.
 *
 * @package    enrol_wallet
 * @copyright  2023 Mo Farouk <phun.for.physics@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once(__DIR__.'/../lib.php');
require_once($CFG->libdir.'/tablelib.php');
require_once($CFG->libdir.'/formslib.php');

global $DB, $USER;
// Adding some security.
require_login();

$systemcontext = context_system::instance();
$viewall = has_capability('enrol/wallet:transaction', $systemcontext);

$sort = optional_param('tsort', 'userid', PARAM_ALPHA);
$userid = optional_param('user', '', PARAM_INT);
$datefrom = optional_param_array('datefrom', [], PARAM_INT);
$dateto = optional_param_array('dateto', [], PARAM_INT);
$ttype = optional_param('ttype', '', PARAM_TEXT);
$value = optional_param('value', '', PARAM_NUMBER);
// Setup the page.
$PAGE->set_pagelayout('admin');
$PAGE->set_context($systemcontext);
$thisurl = new moodle_url('/enrol/wallet/extra/transaction.php');
$PAGE->set_url($thisurl);
$PAGE->set_title("Wallet Transactions");
$PAGE->set_heading('Wallet Transactions');

// If the user didn't have the capability to view all transaction, show him only his transactions.
$conditions = ($viewall) ? [] : ['userid' => $USER->id];

// Lets add a filtration form for people with capabilities.
if ($viewall) {
    // Check the data from submitted form first.
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }

    if (!empty($ttype)) {
        $conditions['type'] = $ttype;
    }

    if (!empty($value)) {
        $conditions['amount'] = $value;
    }

    if (!empty($datefrom)) {
        $timefrom = mktime(
            $datefrom['hour'],
            $datefrom['minute'],
            0,
            $datefrom['month'],
            $datefrom['day'],
            $datefrom['year'],
        );
    }

    if (!empty($dateto)) {
        $timeto = mktime(
            $dateto['hour'],
            $dateto['minute'],
            59,
            $dateto['month'],
            $dateto['day'],
            $dateto['year'],
        );
    }

    // Create the form.
    $mform = new MoodleQuickForm('transactions', 'GET', $thisurl);

    // Borrow potential users selectors from enrol_manual.
    $options = array(
        'ajax' => 'enrol_manual/form-potential-user-selector',
        'multiple' => false,
        'courseid' => SITEID,
        'enrolid' => 0,
        'perpage' => $CFG->maxusersperpage,
        'userfields' => implode(',', \core_user\fields::get_identity_fields($systemcontext, true)),
        'noselectionstring' => get_string('allusers', 'enrol_wallet'),
    );
    $mform->addElement('autocomplete', 'user', get_string('selectusers', 'enrol_manual'), array(), $options);

    // Adding starting and ending dates for transactions.
    $mform->addElement('date_time_selector', 'datefrom', get_string('datefrom', 'enrol_wallet'), array('optional' => true));
    $mform->addElement('date_time_selector', 'dateto', get_string('dateto', 'enrol_wallet'), array('optional' => true));

    // Select specific type of transaction.
    $options = [
        '' => 'All',
        'debit' => 'debit',
        'credit' => 'credit',
    ];
    $mform->addElement('select', 'ttype', get_string('transaction_type', 'enrol_wallet'), $options);

    // Select specific value.
    $mform->addElement('text', 'value', get_string('value', 'enrol_wallet'));
    $mform->setType('value', PARAM_NUMBER);

    $mform->addElement('submit', '', get_string('submit'));
}

echo $OUTPUT->header();

// Display the filtration form.
if ($viewall) {
    $mform->display();
}

// Set up the transactions table.
$columns = array(
    'user' => 'User',
    'timecreated' => 'Time',
    'amount' => 'Amount',
    'type' => 'Type of transaction',
    'balbefore' => 'balance before',
    'balance' => 'balance after',
    'norefund' => 'Non refundable',
    'descripe' => 'description',
);

$table = new flexible_table('wallet_transactions');
$table->define_columns(array_keys($columns));
$table->define_headers(array_values($columns));
$table->set_attribute('class', 'generaltable generalbox wallet-transactions');
$table->define_baseurl($PAGE->url);

// Setup up the sorting properties.
$table->sortable(true);
$table->no_sorting('user');
$table->no_sorting('norefund');
$table->setup();

// Work out direction of sort required.
$sortcolumns = $table->get_sort_columns();
// Now do sorting if specified.

// Sanity check $sort var before including in sql. Make sure it matches a known column.
$allowedsort = array_diff(array_keys($table->columns), $table->column_nosort);
if (!in_array($sort, $allowedsort)) {
    $sort = '';
}
// Doing the sorting.
$orderby = 'id DESC';
if (!empty($sort)) {
    $direction = ' DESC';
    if (!empty($sortcolumns[$sort]) && $sortcolumns[$sort] == SORT_ASC) {
        $direction = ' ASC';
    }
    $orderby = " $sort $direction";
}

$records = $DB->get_records('enrol_wallet_transactions', $conditions, $orderby);
foreach ($records as $record) {
    // I'm just to lazy to rebuilt it using get_records_sql.
    // TODO use get_records_sql instate of these conditions.
    if (isset($timeto) && $record->timecreated > $timeto) {
        continue;
    }
    if (isset($timefrom) && $record->timecreated < $timefrom) {
        continue;
    }
    $user = core_user::get_user($record->userid);
    $userfullname = fullname($user);

    $time = userdate($record->timecreated);

    $amount = number_format($record->amount, 2);
    $before = number_format($record->balbefore, 2);
    $after = number_format($record->balance, 2);
    $norefund = number_format($record->norefund, 2);
    $desc = $record->descripe;

    $row = [
        'user' => $userfullname,
        'timecreated' => $time,
        'amount' => $amount,
        'type' => $record->type,
        'balbefore' => $before,
        'balance' => $after,
        'norefund' => $norefund,
        'descripe' => $desc,
    ];

    $table->add_data_keyed($row);

    flush();
}

$table->finish_output();

echo $OUTPUT->footer();
