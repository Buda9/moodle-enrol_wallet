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
 * wallet enrolment plugin.
 *
 * @package    enrol_wallet
 * @copyright  2023 Mo Farouk <phun.for.physics@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use enrol_wallet\form\enrol_form;
use enrol_wallet\form\empty_form;
use enrol_wallet\form\applycoupon_form;
use enrol_wallet\form\insuf_form;
use enrol_wallet\form\topup_form;
use enrol_wallet\transactions;

/**
 * wallet enrolment plugin implementation.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_wallet_plugin extends enrol_plugin {

    /**
     * If coupons disabled.
     */
    public const WALLET_NOCOUPONS = 0;
    /**
     * If only fixed value coupons enabled.
     */
    public const WALLET_COUPONSFIXED = 1;
    /**
     * If only percentage discount coupons enabled.
     */
    public const WALLET_COUPONSDISCOUNT = 2;
    /**
     * If all coupons enabled.
     */
    public const WALLET_COUPONSALL = 3;
    /**
     * If the user has insufficient balance.
     */
    public const INSUFFICIENT_BALANCE = 2;
    /**
     * If the user has insufficient balance even after discount.
     */
    public const INSUFFICIENT_BALANCE_DISCOUNTED = 3;
    /**
     * lasternoller
     * @var array
     */
    protected $lasternoller = null;
    /**
     * lasternollerinstanceid
     * @var int
     */
    protected $lasternollerinstanceid = 0;
    /**
     * The cost after discounts.
     * @var float
     */
    protected $costafter;

    /**
     * Returns optional enrolment information icons.
     *
     * This is used in course list for quick overview of enrolment options.
     *
     * We are not using single instance parameter because sometimes
     * we might want to prevent icon repetition when multiple instances
     * of one type exist. One instance may also produce several icons.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     * @throws \coding_exception
     * @throws \dml_exception
     * @since 1.0
     */
    public function get_info_icons(array $instances) {
        foreach ($instances as $instance) {
            if ($this->can_self_enrol($instance, false) !== true) {
                // User can not enrol himself.
                // Note that we do not check here if user is already enrolled for performance reasons -
                // such check would execute extra queries for each course in the list of courses and
                // would hide self-enrolment icons from guests.
                continue;
            }
        }

        return [new pix_icon('wallet', get_string('pluginname', 'enrol_wallet'), 'enrol_wallet')];
    }

    /**
     * Returns localised name of enrol instance
     *
     * @param stdClass $instance (null is accepted too)
     * @return string
     * @throws \coding_exception
     * @throws \dml_exception
     * @since 1.0
     */
    public function get_instance_name($instance) {
        global $DB;

        if (empty($instance->name)) {
            if (!empty($instance->roleid) && $role = $DB->get_record('role', ['id' => $instance->roleid])) {
                $role = ' (' . role_get_name($role, context_course::instance($instance->courseid, IGNORE_MISSING)) . ')';
            } else {
                $role = '';
            }
            $enrol = $this->get_name();
            return get_string('pluginname', 'enrol_' . $enrol) . $role;
        } else {
            return format_string($instance->name);
        }
    }

    /**
     * Does this plugin assign protected roles are can they be manually removed?
     * @return bool - false means anybody may tweak roles, it does not use itemid and component when assigning roles
     * @since 1.0
     */
    public function roles_protected() {
        return false;
    }

    /**
     * Does this plugin allow manual unenrolment of all users?
     * All plugins allowing this must implement 'enrol/xxx:unenrol' capability
     *
     * @param stdClass $instance course enrol instance
     * @return bool - true means user with 'enrol/xxx:unenrol' may unenrol others freely, false means nobody may touch
     * user_enrolments
     * @since 1.0
     */
    public function allow_unenrol(stdClass $instance) {
        return true;
    }

    /**
     * Does this plugin allow manual changes in user_enrolments table?
     *
     * All plugins allowing this must implement 'enrol/xxx:manage' capability
     *
     * @param stdClass $instance course enrol instance
     * @return bool - true means it is possible to change enrol period and status in user_enrolments table
     * @since 1.0
     */
    public function allow_manage(stdClass $instance) {
        return true;
    }

    /**
     * Does this plugin support some way to user to self enrol?
     *
     * @param stdClass $instance course enrol instance
     * @return bool - true means show "Enrol me in this course" link in course UI
     * @since 1.0
     */
    public function show_enrolme_link(stdClass $instance) {

        if (true !== $this->can_self_enrol($instance, false)) {
            return false;
        }

        return true;
    }

    /**
     * Return true if we can add a new instance to this course.
     *
     * @param int $courseid
     * @return boolean
     * @throws \coding_exception
     * @since 1.0
     */
    public function can_add_instance($courseid) {
        global $DB;
        $context = context_course::instance($courseid, MUST_EXIST);

        if (!has_capability('moodle/course:enrolconfig', $context) || !has_capability('enrol/wallet:config', $context)) {
            return false;
        }
        if ($DB->record_exists('enrol', array('courseid' => $courseid, 'enrol' => 'wallet'))) {
            // Only one instance allowed, sorry.
            return false;
        }
        return true;
    }

    /**
     * Self enrol user to course
     *
     * @param stdClass $instance enrolment instance
     * @param stdClass $user User to enrol and deduct fees from
     * @return bool|array true if enroled else eddor code and messege
     * @throws \coding_exception
     * @since 1.0
     */
    public function enrol_self(stdClass $instance, \stdClass $user) {
        // Get the name of the course.
        $coursename = get_course($instance->courseid)->fullname;
        $coupon = optional_param('coupon', null, PARAM_RAW);
        $coupon = isset($_SESSION['coupon']) ? $_SESSION['coupon'] : $coupon;
        // Get the final cost after discount (if there is no discount it return the full cost).
        $costafter = (!empty($this->costafter)) ? $this->costafter : $this->get_cost_after_discount($user->id, $instance, $coupon);

        $timestart = time();
        $timeend = ($instance->enrolperiod) ? $timestart + $instance->enrolperiod : 0;

        $this->enrol_user($instance, $user->id, $instance->roleid, $timestart, $timeend);
        $balance = transactions::get_user_balance($user->id);
        $deduct = min($balance, $costafter);
        // Deduct fees from user's account after ensure that he got enroled.
        transactions::debit($user->id, $deduct, $coursename);
        // Mark coupon as used (this is for percentage discount coupons only).
        if ($coupon != null && $costafter < $instance->cost) {
            transactions::mark_coupon_used($coupon, $user->id, $instance->id);
        }
        if (isset($_SESSION['coupon'])) {
            unset($_SESSION['coupon']);
        }
        // Now apply the cashback if enabled.
        $cashbackenabled = get_config('enrol_wallet', 'cashback');
        if ($cashbackenabled) {
            $percent = get_config('enrol_wallet', 'cashbackpercent');
            $desc = 'added by cashback due to enrolment in '.$coursename;
            $value = $costafter * $percent / 100;
            transactions::payment_topup($value, $user->id, $desc, $user->id);
        }

        // Send welcome message.
        if ($instance->customint4 != ENROL_DO_NOT_SEND_EMAIL) {
            $this->email_welcome_message($instance, $user);
        }

        return true;
    }

    /**
     * Creates course enrol form, checks if form submitted
     * and enrols user if necessary. It can also redirect.
     *
     * @param stdClass $instance
     * @return string html text, usually a form in a text box
     * @throws \coding_exception
     * @throws \dml_exception
     * @since 1.0
     */
    public function enrol_page_hook(stdClass $instance) {
        global $OUTPUT, $USER;

        $enrolstatus = $this->can_self_enrol($instance);
        $this->costafter = self::get_cost_after_discount($USER->id, $instance);
        $costafter = $this->costafter;
        $costbefore = $instance->cost;
        $balance = transactions::get_user_balance($USER->id);
        $output = '';
        if (true === $enrolstatus) {
            // This user can self enrol using this instance.
            $form = new enrol_form(null, $instance);
            $instanceid = optional_param('instance', 0, PARAM_INT);
            if ($instance->id == $instanceid) {
                // If form validates user can purchase enrolment with wallet balance.
                if ($data = $form->get_data()) {
                    $this->enrol_self($instance, $USER);
                }
            }
            ob_start();
            $form->display();
            $output .= ob_get_clean();
            // Now prepare the coupon form.
            // Check the coupons settings first.
            $couponsetting = get_config('enrol_wallet', 'coupons');
            $coupon = optional_param('coupon', '', PARAM_RAW);
            if ($couponsetting != self::WALLET_NOCOUPONS) {
                $data = new stdClass();
                $data->header = $this->get_instance_name($instance);
                $data->instance = $instance;
                require_once(__DIR__.'/classes/form/applycoupon_form.php');
                $action = new moodle_url('/enrol/wallet/extra/action.php');
                $couponform = new applycoupon_form($action, $data);
                ob_start();
                $couponform->display();
                $output .= ob_get_clean();
            }
        } else if (self::INSUFFICIENT_BALANCE == $enrolstatus ||
            self::INSUFFICIENT_BALANCE_DISCOUNTED == $enrolstatus
            ) {
            // This user has insufficient wallet balance to be directly enrolled.
            // So we will show him several ways for payments or recharge his wallet.
            $data = new stdClass();
            $data->header = $this->get_instance_name($instance);
            $data->instance = $instance;
            if ($enrolstatus == self::INSUFFICIENT_BALANCE) {
                $data->info = get_string('insufficient_balance', 'enrol_wallet', [
                    'cost_before' => $costbefore,
                    'user_balance' => $balance]);
            } else {
                $data->info = get_string('insufficient_balance_discount', 'enrol_wallet', [
                    'cost_before' => $costbefore,
                    'cost_after' => $costafter,
                    'user_balance' => $balance]);
            }

            require_once(__DIR__.'/classes/form/insuf_form.php');
            $form = new insuf_form(null, $data);
            ob_start();
            $form->display();
            $output .= ob_get_clean();

            // Now prepare the coupon form.
            // Check the coupons settings first.
            $couponsetting = get_config('enrol_wallet', 'coupons');
            if ($couponsetting != self::WALLET_NOCOUPONS) {
                require_once(__DIR__.'/classes/form/applycoupon_form.php');
                $action = new moodle_url('/enrol/wallet/extra/action.php');
                $couponform = new applycoupon_form($action, $data);
                ob_start();
                $couponform->display();
                $output .= ob_get_clean();
            }
            // If the payment enbled in this instance, display the payment button.
            if ($instance->customint1 != null && $instance->customint1 != '' && $instance->currency != null) {
                $output .= self::show_payment_info($instance, $costafter);
            }
            // If payment is enabled in general, adding topup option.
            $account = get_config('enrol_wallet', 'paymentaccount');
            if (!empty($account) && $account > 0) {
                require_once(__DIR__.'/classes/form/topup_form.php');
                $topupurl = new moodle_url('/enrol/wallet/extra/topup.php');
                $topupform = new topup_form($topupurl, $data);
                ob_start();
                $topupform->display();
                $output .= ob_get_clean();
            }
        } else {
            // This user can not enrol using this instance. Using an empty form to keep
            // the UI consistent with other enrolment plugins that returns a form.
            $data = new stdClass();
            $data->header = $this->get_instance_name($instance);
            $data->info = $enrolstatus;
            $data->instance = $instance;
            // The can_self_enrol call returns a button to the login page if the user is a
            // guest, setting the login url to the form if that is the case.
            $url = isguestuser() ? get_login_url() : null;
            $form = new empty_form($url, $data);
            ob_start();
            $form->display();
            $output .= ob_get_clean();
        }

        return $OUTPUT->box($output);
    }

    /**
     * Checks if user can self enrol.
     *
     * @param stdClass $instance enrolment instance
     * @param bool $checkuserenrolment if true will check if user enrolment is inactive.
     *             used by navigation to improve performance.
     * @return bool|string true if successful, else error message or false.
     * @throws \dml_exception
     * @throws \coding_exception
     * @since 1.0
     */
    public function can_self_enrol(stdClass $instance, $checkuserenrolment = true) {
        global $CFG, $DB, $OUTPUT, $USER;

        if ($checkuserenrolment) {
            if (isguestuser()) {
                // Can not enrol guest.
                return get_string('noguestaccess', 'enrol') . $OUTPUT->continue_button(get_login_url());
            }
            // Check if user is already enroled.
            if ($DB->record_exists('user_enrolments', ['userid' => $USER->id, 'enrolid' => $instance->id])) {
                return get_string('alreadyenroled', 'enrol_wallet');
            }
        }
        // Disabled instance.
        if ($instance->status != ENROL_INSTANCE_ENABLED) {
            return get_string('canntenrol', 'enrol_wallet');
        }
        // Cannot enrol early.
        if ($instance->enrolstartdate != 0 && $instance->enrolstartdate > time()) {
            return get_string('canntenrolearly', 'enrol_wallet', userdate($instance->enrolstartdate));
        }
        // Cannot enrol late.
        if ($instance->enrolenddate != 0 && $instance->enrolenddate < time()) {
            return get_string('canntenrollate', 'enrol_wallet', userdate($instance->enrolenddate));
        }
        // New enrols not allowed.
        if (!$instance->customint6) {
            return get_string('canntenrol', 'enrol_wallet');
        }
        // Max enrolments reached.
        if ($instance->customint3 > 0) {
            // Max enrol limit specified.
            $count = $DB->count_records('user_enrolments', ['enrolid' => $instance->id]);
            if ($count >= $instance->customint3) {
                // Bad luck, no more self enrolments here.
                return get_string('maxenrolledreached', 'enrol_wallet');
            }
        }
        // Check the restrictions upon other course enrollment.
        if (!empty($instance->customint7)) {
            $coursectx = context_course::instance($instance->customint7);
            if (!is_enrolled($coursectx)) {
                // The user is not enrolled in the required course.
                $coursename = get_course($instance->customint7)->fullname;
                return get_string('othercourserestriction', 'enrol_wallet', $coursename);
            }
        }
        // Check the cohorts restrictions.
        if ($instance->customint5) {
            require_once("$CFG->dirroot/cohort/lib.php");
            if (!cohort_is_member($instance->customint5, $USER->id)) {
                $cohort = $DB->get_record('cohort', ['id' => $instance->customint5]);
                if (!$cohort) {
                    return null;
                }
                $a = format_string($cohort->name, true, ['context' => context::instance_by_id($cohort->contextid)]);
                return markdown_to_html(get_string('cohortnonmemberinfo', 'enrol_wallet', $a));
            }
        }
        // Non valid cost.
        if ($instance->cost < 0 || empty($instance->cost) || !is_numeric($instance->cost)) {
            return get_string('nocost', 'enrol_wallet');
        }
        // Insufficient balance.
        $costafter = self::get_cost_after_discount($USER->id, $instance);
        $this->costafter = $costafter;
        $costbefore = $instance->cost;
        $balance = transactions::get_user_balance($USER->id);
        if ($balance < $costafter) {
            if ($costbefore == $costafter) {
                return self::INSUFFICIENT_BALANCE;
            } else {
                return self::INSUFFICIENT_BALANCE_DISCOUNTED;
            }
        }

        return true;
    }

    /**
     * Return information for enrolment instance containing list of parameters required
     * for enrolment, name of enrolment plugin etc.
     *
     * @param stdClass $instance enrolment instance
     * @return stdClass instance info.
     * @throws \dml_exception
     * @throws \coding_exception
     * @since 1.0
     */
    public function get_enrol_info(stdClass $instance) {

        $instanceinfo = new stdClass();
        $instanceinfo->id = $instance->id;
        $instanceinfo->courseid = $instance->courseid;
        $instanceinfo->type = $this->get_name();
        $instanceinfo->name = $this->get_instance_name($instance);
        $instanceinfo->status = $this->can_self_enrol($instance);

        return $instanceinfo;
    }

    /**
     * Add new instance of enrol plugin with default settings.
     * @param stdClass $course
     * @return int id of new instance
     * @since 1.0
     */
    public function add_default_instance($course) {
        $fields = $this->get_instance_defaults();

        return $this->add_instance($course, $fields);
    }

    /**
     * Returns defaults for new instances.
     * @return array
     * @since 1.0
     */
    public function get_instance_defaults() {
        $expirynotify = $this->get_config('expirynotify');
        if ($expirynotify == 2) {
            $expirynotify = 1;
            $notifyall = 1;
        } else {
            $notifyall = 0;
        }

        $fields = [];
        $fields['status'] = $this->get_config('status');
        $fields['roleid'] = $this->get_config('roleid');
        $fields['enrolperiod'] = $this->get_config('enrolperiod');
        $fields['expirynotify'] = $expirynotify;
        $fields['notifyall'] = $notifyall;
        $fields['expirythreshold'] = $this->get_config('expirythreshold');
        $fields['currency'] = $this->get_config('currency');
        $fields['customint1'] = $this->get_config('paymentaccount');
        $fields['customint2'] = $this->get_config('longtimenosee');
        $fields['customint3'] = $this->get_config('maxenrolled');
        $fields['customint4'] = $this->get_config('sendcoursewelcomemessage');
        $fields['customint5'] = 0;
        $fields['customint6'] = $this->get_config('newenrols');
        $fields['customint7'] = 0;
        $awards = $this->get_config('awards');
        $fields['customint8'] = isset($awards) ? $awards : 0;
        if ($awards) {
            $fields['customdec1'] = $this->get_config('awardcreteria');
            $fields['customdec2'] = $this->get_config('awardvalue');
        } else {
            $fields['customdec1'] = 0;
            $fields['customdec2'] = 0;
        }

        return $fields;
    }

    /**
     * Send welcome email to specified user.
     *
     * @param stdClass $instance
     * @param stdClass $user user record
     * @return void
     * @throws \dml_exception
     * @throws \coding_exception
     * @since 1.0
     */
    protected function email_welcome_message($instance, $user) {
        global $CFG, $DB;

        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
        $context = context_course::instance($course->id);

        $a = new stdClass();
        $a->coursename = format_string($course->fullname, true, ['context' => $context]);
        $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id&course=$course->id";
        $trimed = preg_replace('/^\s+|\s+$/m', '', $instance->customtext1);
        if ($trimed !== '') {
            $message = $instance->customtext1;
            $key = ['{$a->coursename}', '{$a->profileurl}', '{$a->fullname}', '{$a->email}'];
            $value = [$a->coursename, $a->profileurl, fullname($user), $user->email];
            $message = str_replace($key, $value, $message);
            if (strpos($message, '<') === false) {
                // Plain text only.
                $messagetext = $message;
                $messagehtml = text_to_html($messagetext, null, false, true);
            } else {
                // This is most probably the tag/newline soup known as FORMAT_MOODLE.
                $options = new \stdClass();
                $options->context = $context;
                $options->para = false;
                $options->newlines = true;
                $options->filter = true;

                $messagehtml = format_text($message, FORMAT_MOODLE, $options);
                $messagetext = html_to_text($messagehtml);
            }
        } else {
            $messagetext = get_string('welcometocoursetext', 'enrol_wallet', $a);
            $messagehtml = text_to_html($messagetext, null, false, true);
        }

        $subject = get_string('welcometocourse', 'enrol_wallet', format_string($course->fullname, true, ['context' => $context]));

        $sendoption = $instance->customint4;
        $contact = $this->get_welcome_email_contact($sendoption, $context);

        // Directly emailing welcome message rather than using messaging.
        email_to_user($user, $contact, $subject, $messagetext, $messagehtml);
    }

    /**
     * Sync all meta course links.
     *
     * Unenrols users that have exceeded the "longtimenosee" value set on wallet enrolment instances.
     *
     * @param progress_trace $trace
     * @param int $courseid one course, empty mean all
     * @return int 0 means ok, 1 means error, 2 means plugin disabled
     * @throws \dml_exception
     * @throws \coding_exception
     * @since 1.0
     */
    public function sync(progress_trace $trace, $courseid = null) {
        global $DB;

        if (!enrol_is_enabled('wallet')) {
            $trace->finished();
            return 2;
        }

        // Unfortunately this may take a long time, execution can be interrupted safely here.
        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);

        $trace->output('Verifying wallet enrolments...');

        $params = ['now' => time(), 'useractive' => ENROL_USER_ACTIVE, 'courselevel' => CONTEXT_COURSE];
        $coursesql = "";
        if ($courseid) {
            $coursesql = "AND e.courseid = :courseid";
            $params['courseid'] = $courseid;
        }

        // Note: the logic of wallet enrolment guarantees that user logged in at least once (=== u.lastaccess set)
        // and that user accessed course at least once too (=== user_lastaccess record exists).

        // First deal with users that did not log in for a really long time - they do not have user_lastaccess records.
        $sql = "SELECT e.*, ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'wallet' AND e.customint2 > 0)
                  JOIN {user} u ON u.id = ue.userid
                 WHERE :now - u.lastaccess > e.customint2
                       $coursesql";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $instance) {
            $userid = $instance->userid;
            unset($instance->userid);
            $this->unenrol_user($instance, $userid);
            $days = $instance->customint2 / DAYSECS;
            $msg = "unenrolling user $userid from course $instance->courseid as they have did not log in for at least $days days";
            $trace->output($msg, 1);
        }
        $rs->close();

        // Now unenrol from course user did not visit for a long time.
        $sql = "SELECT e.*, ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'wallet' AND e.customint2 > 0)
                  JOIN {user_lastaccess} ul ON (ul.userid = ue.userid AND ul.courseid = e.courseid)
                 WHERE :now - ul.timeaccess > e.customint2
                       $coursesql";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $instance) {
            $userid = $instance->userid;
            unset($instance->userid);
            $this->unenrol_user($instance, $userid);
            $days = $instance->customint2 / DAYSECS;
            $msg = 'unenrolling user '.$userid.' from course '.$instance->courseid.
            ' as they have did not access course for at least '.$days.' days';
            $trace->output($msg, 1);
        }
        $rs->close();

        $trace->output('...user wallet enrolment updates finished.');
        $trace->finished();

        $this->process_expirations($trace, $courseid);

        return 0;
    }

    /**
     * Returns the user who is responsible for wallet enrolments in given instance.
     *
     * Usually it is the first editing teacher - the person with "highest authority"
     * as defined by sort_by_roleassignment_authority() having 'enrol/wallet:manage'
     * capability.
     *
     * @param int $instanceid enrolment instance id
     * @return stdClass user record
     * @throws \dml_exception
     * @since 1.0
     */
    protected function get_enroller($instanceid) {
        global $DB;

        if ($this->lasternollerinstanceid == $instanceid && $this->lasternoller) {
            return $this->lasternoller;
        }

        $instance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => $this->get_name()], '*', MUST_EXIST);
        $context = context_course::instance($instance->courseid);

        if ($users = get_enrolled_users($context, 'enrol/wallet:manage')) {
            $users = sort_by_roleassignment_authority($users, $context);
            $this->lasternoller = reset($users);
            unset($users);
        } else {
            $this->lasternoller = parent::get_enroller($instanceid);
        }

        $this->lasternollerinstanceid = $instanceid;

        return $this->lasternoller;
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     * @throws \dml_exception
     * @throws restore_step_exception
     * @since 1.0
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;
        if ($step->get_task()->get_target() == backup::TARGET_NEW_COURSE) {
            $merge = false;
        } else {
            $merge = [
                'courseid' => $data->courseid,
                'enrol' => $this->get_name(),
                'status' => $data->status,
                'roleid' => $data->roleid,
            ];
        }
        if ($merge && $instances = $DB->get_records('enrol', $merge, 'id')) {
            $instance = reset($instances);
            $instanceid = $instance->id;
        } else {
            if (!empty($data->customint5)) {
                if (!$step->get_task()->is_samesite()) {
                    // Use some id that can not exist in order to prevent wallet enrolment,
                    // because we do not know what cohort it is in this site.
                    $data->customint5 = -1;
                }
            }
            $instanceid = $this->add_instance($course, (array)$data);
        }
        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $userid
     * @param int $oldinstancestatus
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, $data->status);
    }

    /**
     * Restore role assignment.
     *
     * @param stdClass $instance
     * @param int $roleid
     * @param int $userid
     * @param int $contextid
     */
    public function restore_role_assignment($instance, $roleid, $userid, $contextid) {
        // This is necessary only because we may migrate other types to this instance,
        // we do not use component in manual or self enrol.
        role_assign($roleid, $userid, $contextid, '', 0);
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     * @throws coding_exception
     * @since 1.0
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/wallet:config', $context);
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     * @throws \coding_exception
     * @since 1.0
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/wallet:config', $context);
    }

    /**
     * Return an array of valid options for the status.
     *
     * @return array
     * @throws \coding_exception
     * @since 1.0
     */
    protected function get_status_options() {
        $options = [ENROL_INSTANCE_ENABLED => get_string('yes'),
                    ENROL_INSTANCE_DISABLED => get_string('no')];
        return $options;
    }

    /**
     * Return an array of valid options for the newenrols property.
     *
     * @return array
     * @throws \coding_exception
     * @since 1.0
     */
    protected function get_newenrols_options() {
        $options = [1 => get_string('yes'), 0 => get_string('no')];
        return $options;
    }

    /**
     * Return an array of valid options for the groupkey property.
     *
     * @return array
     * @throws \coding_exception
     * @since 1.0
     */
    protected function get_groupkey_options() {
        $options = [1 => get_string('yes'), 0 => get_string('no')];
        return $options;
    }

    /**
     * Return an array of valid options for the expirynotify property.
     *
     * @return array
     * @throws \coding_exception
     * @since 1.0
     */
    protected function get_expirynotify_options() {
        $options = [0 => get_string('no'),
                    1 => get_string('expirynotifyenroller', 'core_enrol'),
                    2 => get_string('expirynotifyall', 'core_enrol')];
        return $options;
    }

    /**
     * Return an array of valid options for the longtimenosee property.
     *
     * @return array
     * @throws \coding_exception
     * @since 1.0
     */
    protected function get_longtimenosee_options() {
        $options = [0 => get_string('never'),
                    1800 * 3600 * 24 => get_string('numdays', '', 1800),
                    1000 * 3600 * 24 => get_string('numdays', '', 1000),
                    365 * 3600 * 24 => get_string('numdays', '', 365),
                    180 * 3600 * 24 => get_string('numdays', '', 180),
                    150 * 3600 * 24 => get_string('numdays', '', 150),
                    120 * 3600 * 24 => get_string('numdays', '', 120),
                    90 * 3600 * 24 => get_string('numdays', '', 90),
                    60 * 3600 * 24 => get_string('numdays', '', 60),
                    30 * 3600 * 24 => get_string('numdays', '', 30),
                    21 * 3600 * 24 => get_string('numdays', '', 21),
                    14 * 3600 * 24 => get_string('numdays', '', 14),
                    7 * 3600 * 24 => get_string('numdays', '', 7)];
        return $options;
    }

    /**
     * The wallet enrollment plugin has several bulk operations that can be performed.
     * @param course_enrolment_manager $manager
     * @return array
     * @throws \coding_exception
     * @since 1.0
     */
    public function get_bulk_operations(course_enrolment_manager $manager) {
        $context = $manager->get_context();
        $bulkoperations = array();
        if (has_capability("enrol/wallet:manage", $context)) {
            $bulkoperations['editselectedusers'] = new \enrol_wallet\editselectedusers_operation($manager, $this);
        }
        if (has_capability("enrol/wallet:unenrol", $context)) {
            $bulkoperations['deleteselectedusers'] = new \enrol_wallet\deleteselectedusers_operation($manager, $this);
        }
        return $bulkoperations;
    }

    /**
     * Add elements to the edit instance form.
     *
     * @param stdClass $instance
     * @param MoodleQuickForm $mform
     * @param context $context
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context) {
        global $CFG, $DB;

        // Merge these two settings to one value for the single selection element.
        if ($instance->notifyall && $instance->expirynotify) {
            $instance->expirynotify = 2;
        }
        unset($instance->notifyall);

        $nameattribs = ['size' => '20', 'maxlength' => '255'];
        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'), $nameattribs);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'server');

        $mform->addElement('text', 'cost', get_string('credit_cost', 'enrol_wallet'));
        $mform->setType('cost', PARAM_INT);
        $mform->addHelpButton('cost', 'credit_cost', 'enrol_wallet');

        $accounts = \core_payment\helper::get_payment_accounts_menu($context);
        if ($accounts) {
            $accounts = ((count($accounts) > 1) ? ['' => ''] : []) + $accounts;
            $mform->addElement('select', 'customint1', get_string('paymentaccount', 'payment'), $accounts);
        } else {
            $mform->addElement('static', 'customint1_text', get_string('paymentaccount', 'payment'),
                html_writer::span(get_string('noaccountsavilable', 'payment'), 'alert alert-danger'));
            $mform->addElement('hidden', 'customint1');
            $mform->setType('customint1', PARAM_INT);
        }
        $mform->addHelpButton('customint1', 'paymentaccount', 'enrol_wallet');

        $supportedcurrencies = $this->get_possible_currencies();
        $mform->addElement('select', 'currency', get_string('currency', 'enrol_wallet'), $supportedcurrencies);
        $mform->addHelpButton('currency', 'currency', 'enrol_wallet');

        $options = $this->get_status_options();
        $mform->addElement('select', 'status', get_string('status', 'enrol_wallet'), $options);
        $mform->addHelpButton('status', 'status', 'enrol_wallet');

        $options = $this->get_newenrols_options();
        $mform->addElement('select', 'customint6', get_string('newenrols', 'enrol_wallet'), $options);
        $mform->addHelpButton('customint6', 'newenrols', 'enrol_wallet');
        $mform->disabledIf('customint6', 'status', 'eq', ENROL_INSTANCE_DISABLED);

        $roles = $this->extend_assignable_roles($context, $instance->roleid);
        $mform->addElement('select', 'roleid', get_string('role', 'enrol_wallet'), $roles);

        $options = ['optional' => true, 'defaultunit' => 86400];
        $mform->addElement('duration', 'enrolperiod', get_string('enrolperiod', 'enrol_wallet'), $options);
        $mform->addHelpButton('enrolperiod', 'enrolperiod', 'enrol_wallet');

        $options = $this->get_expirynotify_options();
        $mform->addElement('select', 'expirynotify', get_string('expirynotify', 'core_enrol'), $options);
        $mform->addHelpButton('expirynotify', 'expirynotify', 'core_enrol');

        $options = ['optional' => false, 'defaultunit' => 86400];
        $mform->addElement('duration', 'expirythreshold', get_string('expirythreshold', 'core_enrol'), $options);
        $mform->addHelpButton('expirythreshold', 'expirythreshold', 'core_enrol');
        $mform->disabledIf('expirythreshold', 'expirynotify', 'eq', 0);

        $options = ['optional' => true];
        $mform->addElement('date_time_selector', 'enrolstartdate', get_string('enrolstartdate', 'enrol_wallet'), $options);
        $mform->setDefault('enrolstartdate', 0);
        $mform->addHelpButton('enrolstartdate', 'enrolstartdate', 'enrol_wallet');

        $options = ['optional' => true];
        $mform->addElement('date_time_selector', 'enrolenddate', get_string('enrolenddate', 'enrol_wallet'), $options);
        $mform->setDefault('enrolenddate', 0);
        $mform->addHelpButton('enrolenddate', 'enrolenddate', 'enrol_wallet');

        $options = $this->get_longtimenosee_options();
        $mform->addElement('select', 'customint2', get_string('longtimenosee', 'enrol_wallet'), $options);
        $mform->addHelpButton('customint2', 'longtimenosee', 'enrol_wallet');

        $mform->addElement('text', 'customint3', get_string('maxenrolled', 'enrol_wallet'));
        $mform->addHelpButton('customint3', 'maxenrolled', 'enrol_wallet');
        $mform->setType('customint3', PARAM_INT);

        require_once($CFG->dirroot.'/cohort/lib.php');

        $cohorts = [0 => get_string('no')];
        $allcohorts = cohort_get_available_cohorts($context, 0, 0, 0);
        if ($instance->customint5 && !isset($allcohorts[$instance->customint5])) {
            $c = $DB->get_record('cohort',
                                 ['id' => $instance->customint5],
                                 'id, name, idnumber, contextid, visible',
                                 IGNORE_MISSING);
            if ($c) {
                // Current cohort was not found because current user can not see it. Still keep it.
                $allcohorts[$instance->customint5] = $c;
            }
        }
        foreach ($allcohorts as $c) {
            $cohorts[$c->id] = format_string($c->name, true, ['context' => context::instance_by_id($c->contextid)]);
            if ($c->idnumber) {
                $cohorts[$c->id] .= ' ['.s($c->idnumber).']';
            }
        }
        if ($instance->customint5 && !isset($allcohorts[$instance->customint5])) {
            // Somebody deleted a cohort, better keep the wrong value so that random ppl can not enrol.
            $cohorts[$instance->customint5] = get_string('unknowncohort', 'cohort', $instance->customint5);
        }
        if (count($cohorts) > 1) {
            $mform->addElement('select', 'customint5', get_string('cohortonly', 'enrol_wallet'), $cohorts);
            $mform->addHelpButton('customint5', 'cohortonly', 'enrol_wallet');
        } else {
            $mform->addElement('hidden', 'customint5');
            $mform->setType('customint5', PARAM_INT);
            $mform->setConstant('customint5', 0);
        }
        // Adding restriction upon another course enrolment.
        // Prepare the course selector.
        $courses = get_courses();
        $options = [];
        $options[0] = get_string('none');
        foreach ($courses as $course) {
            // We don't check enrolment in home page.
            if ($course->id == SITEID) {
                continue;
            }

            $category = core_course_category::get($course->category);
            $parentname = $category->name.': ';
            // For sites with greate number of course.
            // This will make it clearer for selections.
            while ($category->parent > 0) {
                $parent = core_course_category::get($category->parent);
                $parentname = $parent->name . ': ' . $parentname;
                $category = $parent;
            }

            $options[$course->id] = $parentname.$course->fullname;
        }

        $select = $mform->addElement('select', 'customint7', get_string('coursesrestriction', 'enrol_wallet'), $options);
        $select->setMultiple(false);
        $mform->addHelpButton('customint7', 'coursesrestriction', 'enrol_wallet');

        $mform->addElement('select', 'customint4', get_string('sendcoursewelcomemessage', 'enrol_wallet'),
                enrol_send_welcome_email_options());
        $mform->addHelpButton('customint4', 'sendcoursewelcomemessage', 'enrol_wallet');

        $options = ['cols' => '60', 'rows' => '8'];
        $mform->addElement('textarea', 'customtext1', get_string('customwelcomemessage', 'enrol_wallet'), $options);
        $mform->addHelpButton('customtext1', 'customwelcomemessage', 'enrol_wallet');

        // Adding the awarding program options for this course.

        $mform->addElement('advcheckbox', 'customint8', get_string('awards', 'enrol_wallet'), '', [], [false, true]);
        $mform->setDefault('customint8', false);
        $mform->addHelpButton('customint8', 'awards', 'enrol_wallet');

        $mform->addElement('float', 'customdec1', get_string('awardcreteria', 'enrol_wallet'));
        $mform->disabledIf('customdec1', 'customint8', 'notchecked');
        $mform->addHelpButton('customdec1', 'awardcreteria', 'enrol_wallet');

        $mform->addElement('float', 'customdec2', get_string('awardvalue', 'enrol_wallet'));
        $mform->disabledIf('customdec2', 'customint8', 'notchecked');
        $mform->addHelpButton('customdec2', 'awardvalue', 'enrol_wallet');

        if (enrol_accessing_via_instance($instance)) {
            $warntext = get_string('instanceeditselfwarningtext', 'core_enrol');
            $mform->addElement('static', 'selfwarn', get_string('instanceeditselfwarning', 'core_enrol'), $warntext);
        }
    }

    /**
     * We are a good plugin and don't invent our own UI/validation code path.
     *
     * @return boolean
     */
    public function use_standard_editing_ui() {
        return true;
    }

    /**
     * Returns the list of currencies that the payment subsystem supports and therefore we can work with.
     *
     * @return array[currencycode => currencyname]
     */
    public function get_possible_currencies(): array {
        $codes = \core_payment\helper::get_supported_currencies();

        $currencies = [];
        foreach ($codes as $c) {
            $currencies[$c] = new lang_string($c, 'core_currencies');
        }

        uasort($currencies, function($a, $b) {
            return strcmp($a, $b);
        });

        return $currencies;
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @param object $instance The instance loaded from the DB
     * @param context $context The context of the instance we are editing
     * @return array of "element_name"=>"error_description" if there are errors,
     *         or an empty array if everything is OK.
     * @return array<string>
     * @throws \coding_exception
     * @since 1.0
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        $errors = [];

        if ($data['status'] == ENROL_INSTANCE_ENABLED) {
            if (!empty($data['enrolenddate']) && $data['enrolenddate'] < $data['enrolstartdate']) {
                $errors['enrolenddate'] = get_string('enrolenddaterror', 'enrol_wallet');
            }
        }

        if ($data['expirynotify'] > 0 && $data['expirythreshold'] < 86400) {
            $errors['expirythreshold'] = get_string('errorthresholdlow', 'core_enrol');
        }

        // Now these ones are checked by quickforms, but we may be called by the upload enrolments tool, or a webservive.
        if (core_text::strlen($data['name']) > 255) {
            $errors['name'] = get_string('err_maxlength', 'form', 255);
        }
        $validstatus = array_keys($this->get_status_options());
        $validnewenrols = array_keys($this->get_newenrols_options());

        $context = context_course::instance($instance->courseid);
        $validroles = array_keys($this->extend_assignable_roles($context, $instance->roleid));
        $validexpirynotify = array_keys($this->get_expirynotify_options());
        $validlongtimenosee = array_keys($this->get_longtimenosee_options());
        $validcurrencies = array_keys($this->get_possible_currencies());
        $tovalidate = array(
            'enrolstartdate' => PARAM_INT,
            'enrolenddate' => PARAM_INT,
            'name' => PARAM_TEXT,
            'currency' => $validcurrencies,
            'cost' => PARAM_NUMBER,
            'customint2' => $validlongtimenosee,
            'customint3' => PARAM_INT,
            'customint4' => PARAM_INT,
            'customint5' => PARAM_INT,
            'customint6' => $validnewenrols,
            'customint7' => PARAM_INT,
            'customint8' => PARAM_BOOL,
            'customdec1' => PARAM_NUMBER,
            'customdec2' => PARAM_NUMBER,
            'status' => $validstatus,
            'enrolperiod' => PARAM_INT,
            'expirynotify' => $validexpirynotify,
            'roleid' => $validroles
        );
        if ($data['expirynotify'] != 0) {
            $tovalidate['expirythreshold'] = PARAM_INT;
        }
        $typeerrors = $this->validate_param_types($data, $tovalidate);
        $errors = array_merge($errors, $typeerrors);

        return $errors;
    }

    /**
     * Add new instance of enrol plugin.
     * @param object $course
     * @param array $fields instance fields
     * @return int id of new instance, null if can not be created
     * @throws \coding_exception
     * @since 1.0
     */
    public function add_instance($course, array $fields = null) {
        // In the form we are representing 2 db columns with one field.
        if (!empty($fields) && !empty($fields['expirynotify'])) {
            if ($fields['expirynotify'] == 2) {
                $fields['expirynotify'] = 1;
                $fields['notifyall'] = 1;
            } else {
                $fields['notifyall'] = 0;
            }
        }

        return parent::add_instance($course, $fields);
    }

    /**
     * Update instance of enrol plugin.
     * @param stdClass $instance
     * @param stdClass $data modified instance fields
     * @return boolean
     * @since 1.0
     */
    public function update_instance($instance, $data) {
        // Check first if expiry notify is sent by the edit form (not sent in case of bulk edit only).
        if (isset($data->expirynotify)) {
            // In the form we are representing 2 db columns with one field.
            if ($data->expirynotify == 2) {
                $data->expirynotify = 1;
                $data->notifyall = 1;
            } else {
                $data->notifyall = 0;
            }
            // Keep previous/default value of disabled expirythreshold option.
            if (!$data->expirynotify) {
                $data->expirythreshold = $instance->expirythreshold;
            }
        }

        // Add previous value of newenrols if disabled.
        if (!isset($data->customint6)) {
            $data->customint6 = $instance->customint6;
        }

        return parent::update_instance($instance, $data);
    }

    /**
     * Gets a list of roles that this user can assign for the course as the default for wallet enrolment.
     *
     * @param context $context the context.
     * @param integer $defaultrole the id of the role that is set as the default for wallet enrolment
     * @return array index is the role id, value is the role name
     * @throws \dml_exception
     * @throws \coding_exception
     * @since 1.0
     */
    public function extend_assignable_roles($context, $defaultrole) {
        global $DB;

        $roles = get_assignable_roles($context, ROLENAME_BOTH);
        if (!isset($roles[$defaultrole])) {
            if ($role = $DB->get_record('role', array('id' => $defaultrole))) {
                $roles[$defaultrole] = role_get_name($role, $context, ROLENAME_BOTH);
            }
        }
        return $roles;
    }

    /**
     * Get the "from" contact which the email will be sent from.
     *
     * @param int $sendoption send email from constant ENROL_SEND_EMAIL_FROM_*
     * @param object $context context where the user will be fetched
     * @return array|stdClass the contact user object.
     */
    public function get_welcome_email_contact($sendoption, $context) {
        global $CFG;

        $contact = null;
        // Send as the first user assigned as the course contact.
        if ($sendoption == ENROL_SEND_EMAIL_FROM_COURSE_CONTACT) {
            $rusers = array();
            if (!empty($CFG->coursecontact)) {
                $croles = explode(',', $CFG->coursecontact);
                list($sort, $sortparams) = users_order_by_sql('u');
                // We only use the first user.
                $i = 0;
                do {
                    $userfieldsapi = \core_user\fields::for_name();
                    $allnames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
                    $rusers = get_role_users($croles[$i], $context, true, 'u.id,  u.confirmed, u.username, '. $allnames . ',
                    u.email, r.sortorder, ra.id', 'r.sortorder, ra.id ASC, ' . $sort, false, '', '', '', '', $sortparams);
                    $i++;
                } while (empty($rusers) && !empty($croles[$i]));
            }
            if ($rusers) {
                $contact = array_values($rusers)[0];
            }
        } else if ($sendoption == ENROL_SEND_EMAIL_FROM_KEY_HOLDER) {
            // Send as the first user with enrol/wallet:holdkey capability assigned in the course.
            list($sort) = users_order_by_sql('u');
            $keyholders = get_users_by_capability($context, 'enrol/wallet:holdkey', 'u.*', $sort);
            if (!empty($keyholders)) {
                $contact = array_values($keyholders)[0];
            }
        }

        // If send welcome email option is set to no reply or if none of the previous options have
        // returned a contact send welcome message as noreplyuser.
        if ($sendoption == ENROL_SEND_EMAIL_FROM_NOREPLY || empty($contact)) {
            $contact = core_user::get_noreply_user();
        }

        return $contact;
    }

    /**
     * Get percentage discount for a user from custom profile field and coupon code.
     * Calculate the cost of the course after discount.
     *
     * @param int $userid
     * @param object $instance
     * @param string $coupon the coupon code in case if the discount from it.
     * @return int the cost after discount.
     */
    public static function get_cost_after_discount($userid, $instance, $coupon = null) {
        global $DB;
        $couponsetting = get_config('enrol_wallet', 'coupons');
        // Check if there is a coupon discount first.
        if ($coupon == null) {
            $coupon = optional_param('coupon', null, PARAM_RAW);
        }
        $_SESSION['coupon'] = $coupon;

        $costaftercoupon = $instance->cost;

        if ($coupon != null) {
            $coupondata = transactions::get_coupon_value($coupon, $userid);

            $type = (is_array($coupondata)) ? $coupondata['type'] : '';
            if ($type == 'percent' && $couponsetting != self::WALLET_COUPONSFIXED) {
                $costaftercoupon = $instance->cost * (1 - $coupondata['value'] / 100);
            } else if ($type == 'fixed' && $couponsetting != self::WALLET_COUPONSDISCOUNT) {
                // There is no need for this condition as if the type is fixed.
                // we add the value to the wallet then redirect to enrolment page again.
                // I added id just in case of future change in the code.
                $costaftercoupon = $instance->cost - $coupondata['value'];
            }
            // If we disabled the coupons it will not be appearing in the form.
            // This condition is just in case as a security reason.
            if ($couponsetting == self::WALLET_NOCOUPONS) {
                $costaftercoupon = $instance->cost;
            }
        }

        // Check if the discount according to custom profile field in enabled.
        if (!$fieldid = get_config('enrol_wallet', 'discount_field')) {
            return $costaftercoupon;
        }
        // Check the data in the discount field.
        $data = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $fieldid]);

        if (empty($data)) {
            return $costaftercoupon;
        }
        // If the user has free access to courses return 0 cost.
        if (stripos($data, 'free') !== false) {
            return 0;
            // If there is a word no in the data means no discount.
        } else if (stripos($data, 'no') !== false) {
            return $costaftercoupon;
        } else {
            // Get the integer from the data.
            preg_match('/\d+/', $data, $matches);
            if (isset($matches[0])) {
                $discount = intval($matches[0]);
                $cost = $costaftercoupon * (100 - $discount) / 100;
                return $cost;
            } else {
                return $costaftercoupon;
            }
        }
    }

    /**
     * Generates payment information to display on enrol/info page.
     *
     * @param stdClass $instance
     * @param float $costafter the cost after discounts.
     * @return false|string
     */
    public static function show_payment_info(stdClass $instance, $costafter) {
        global $USER, $OUTPUT, $DB;

        if ($DB->record_exists('user_enrolments', array('userid' => $USER->id, 'enrolid' => $instance->id))) {
            return '';
        }

        if ($instance->enrolstartdate != 0 && $instance->enrolstartdate > time()) {
            return '';
        }

        if ($instance->enrolenddate != 0 && $instance->enrolenddate < time()) {
            return '';
        }

        $fee = (float)$costafter;
        $balance = (float)transactions::get_user_balance($USER->id);
        $cost = $fee - $balance;
        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
        $context = context_course::instance($course->id);

        if (abs($cost) < 0.01) { // No cost, other enrolment methods (instances) should be used.
            echo '<p>'.get_string('nocost', 'enrol_wallet').'</p>';
        } else {
            require_once(__DIR__.'/classes/payment/service_provider.php');
            $data = [
                'isguestuser' => isguestuser() || !isloggedin(),
                'cost' => \core_payment\helper::get_cost_as_string($cost, $instance->currency),
                'instanceid' => $instance->id,
                'description' => get_string('purchasedescription', 'enrol_wallet',
                    format_string($course->fullname, true, ['context' => $context])),
                'successurl' => \enrol_wallet\payment\service_provider::get_success_url('wallet', $instance->id)->out(false),
            ];
            $balance != 0 ? $data['balance'] =
                \core_payment\helper::get_cost_as_string($balance, $instance->currency) : $data['balance'] = false;
        }

        return $OUTPUT->render_from_template('enrol_wallet/payment_region', $data);
    }

}

/**
 * To add the category and node information into the my profile page.
 * THIS FUNCTION IS NOT WORKING ON ENROLLMENT PLUGINS. DON'T KNOW WHY.
 * @param core_user\output\myprofile\tree $tree The myprofile tree to add categories and nodes to.
 * @param stdClass                        $user The user object that the profile page belongs to.
 * @param bool                            $iscurrentuser If the $user object is the current user.
 * @param stdClass                        $course The course to determine if we are in a course context or system context.
 * @return void
 */
function enrol_wallet_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $OUTPUT;
    // Get the user balance.
    $balance = transactions::get_user_balance($user->id);

    // Get the default currency.
    $currency = get_config('enrol_wallet', 'currency');

    // Prepare transaction URL to display.
    $transactionsurl = new moodle_url('/enrol/wallet/extra/transaction.php');
    $transactions = html_writer::link($transactionsurl, get_string('transactions', 'enrol_wallet'));
    $tempctx = new stdClass;
    $tempctx->balance = $balance;
    $tempctx->currency = $currency;
    $tempctx->transactions = $transactions;

    // Display the current user's balance in the wallet.
    $render = $OUTPUT->render_from_template('enrol_wallet/display', $tempctx);

    $wdcategory = new core_user\output\myprofile\category('walletcreditdisplay',
                                                    get_string('walletcredit', 'enrol_wallet'));
    $tree->add_category($wdcategory);

    $creditdisplay = '';

    $node = new core_user\output\myprofile\node('walletcreditdisplay', 'walletcreditnode', $creditdisplay, null, null, $render);
    $tree->add_node($node);
}
