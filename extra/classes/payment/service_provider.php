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
 * Payment subsystem callback implementation for enrol_wallet.
 *
 * @package    enrol_wallet
 * @category   payment
 * @copyright  2023 Mo Farouk <phun.for.physics@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_wallet\payment;

use enrol_wallet\transactions;
/**
 * Payment subsystem callback implementation for enrol_wallet.
 *
 * @copyright  2023 Mo Farouk <phun.for.physics@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_provider implements \core_payment\local\callback\service_provider {

    /**
     * Callback function that returns the enrolment cost and the accountid
     * for the course that $instanceid enrolment instance belongs to or itemid
     * for fake item to topup wallet.
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The enrolment instance id or fake item id
     * @return \core_payment\local\entities\payable
     */
    public static function get_payable(string $paymentarea, int $itemid): \core_payment\local\entities\payable {
        global $DB, $USER;

        // Get the fake item in case of topping up the wallet.
        $item = $DB->get_record('enrol_wallet_items', ['id' => $itemid], '*', MUST_EXIST);

        // In this case we get the default settings.
        if ($paymentarea === 'walletenrol') {
            $account = $DB->get_field('enrol', 'customint1', ['id' => $item->instanceid]);
        } else {
            $account = get_config('enrol_wallet', 'paymentaccount');
        }

        return new \core_payment\local\entities\payable($item->cost, $item->currency, $account);
    }

    /**
     * Callback function that returns the URL of the page the user should be redirected to in the case of a successful payment.
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The enrolment instance id
     * @return \moodle_url
     */
    public static function get_success_url(string $paymentarea, int $itemid): \moodle_url {
        global $DB;
        $item = $DB->get_record('enrol_wallet_items', ['id' => $itemid], '*', IGNORE_MISSING);
        // Check if the payment is for enrolment or topping up the wallet.
        if ($paymentarea == 'walletenrol' && !empty($item->instanceid)) {

            $courseid = $DB->get_field('enrol', 'courseid', ['enrol' => 'wallet', 'id' => $item->instanceid], MUST_EXIST);

            return new \moodle_url('/course/view.php', ['id' => $courseid]);

        } else {

            return new \moodle_url('/');
        }
    }

    /**
     * Callback function that delivers what the user paid for to them.
     *
     * @param string $paymentarea
     * @param int $itemid The item id
     * @param int $paymentid payment id as inserted into the 'payments' table, if needed for reference
     * @param int $userid The user id the order is going to deliver to
     * @return bool Whether successful or not
     */
    public static function deliver_order(string $paymentarea, int $itemid, int $paymentid, int $userid): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot.'/enrol/wallet/lib.php');
        // Get the fake item in case of topping up the wallet.
        $item = $DB->get_record('enrol_wallet_items', ['id' => $itemid], '*', MUST_EXIST);

        // Check if the payment is for enrolment or topping up the wallet.
        if ($paymentarea == 'walletenrol') {
            $plugin = enrol_get_plugin('wallet');
            $instance = $plugin->get_instance_by_id($item->instanceid);

            $user = \core_user::get_user($userid);

            $coststring = \core_payment\helper::get_cost_as_string($item->cost, $item->currency);
            $desc = get_string('topuppayment_desc', 'enrol_wallet', $coststring);
            $id = transactions::payment_topup($item->cost, $userid, $desc, $userid, false, true);

            if (is_number($id)) {
                // Now enrol the user after successful payment.
                $enroled = $plugin->enrol_self($instance, $user);

                if (true === $enroled) {
                    return true;
                }
            }

            return false;

        } else {

            $coststring = \core_payment\helper::get_cost_as_string($item->cost, $item->currency);
            $desc = get_string('topuppayment_desc', 'enrol_wallet', $coststring);

            $response = transactions::payment_topup($item->cost, $userid, $desc);

            if (is_number($response)) {
                return true;
            } else {
                return false;
            }
        }
    }
}
