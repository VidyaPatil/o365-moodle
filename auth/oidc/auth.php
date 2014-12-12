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
 * @package auth_oidc
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Remote-Learner.net Inc (http://www.remote-learner.net)
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/authlib.php');
require_once($CFG->dirroot.'/login/lib.php');

/**
 * OpenID Connect Authentication Plugin.
 */
class auth_plugin_oidc extends \auth_plugin_base {
    /** @var string Authentication plugin type - the same as db field. */
    public $authtype = 'oidc';

    /** @var object Plugin config. */
    public $config;

    /** @var \auth_oidc\httpclientinterface An HTTP client to use. */
    protected $httpclient;

    /**
     * Constructor.
     */
    public function __construct() {
        $default = [
            'opname' => get_string('pluginname', 'auth_oidc')
        ];
        $storedconfig = (array)get_config('auth_oidc');
        $forcedconfig = [
            'field_updatelocal_idnumber' => 'oncreate',
            'field_lock_idnumber' => 'locked',
            'field_updatelocal_lang' => 'oncreate',
            'field_lock_lang' => 'locked',
            'field_updatelocal_firstname' => 'onlogin',
            'field_lock_firstname' => 'unlocked',
            'field_updatelocal_lastname' => 'onlogin',
            'field_lock_lastname' => 'unlocked',
            'field_updatelocal_email' => 'onlogin',
            'field_lock_email' => 'unlocked',
        ];

        $this->config = (object)array_merge($default, $storedconfig, $forcedconfig);
    }

    /**
     * Returns a list of potential IdPs that this authentication plugin supports.
     * This is used to provide links on the login page.
     *
     * @param string $wantsurl The relative url fragment the user wants to get to.  You can use this to compose
     *                         a returnurl, for example
     *
     * @return array Array of idps.
     */
    public function loginpage_idp_list($wantsurl) {
        return [
            [
                'url' => new \moodle_url('/auth/oidc/'),
                'icon' => new \pix_icon('t/locked', get_string('pluginname', 'auth_oidc')),
                'name' => $this->config->opname,
            ]
        ];
    }

    /**
     * Set an HTTP client to use.
     *
     * @param auth_oidchttpclientinterface $httpclient [description]
     */
    public function set_httpclient(\auth_oidc\httpclientinterface $httpclient) {
        $this->httpclient = $httpclient;
    }

    /**
     * Construct the OpenID Connect client.
     *
     * @return \auth_oidc\oidcclient The constructed client.
     */
    protected function get_oidcclient() {
        if (empty($this->httpclient) || !($this->httpclient instanceof \auth_oidc\httpclientinterface)) {
            throw new \Exception('Please set an HTTP client.');
        }
        if (empty($this->config->clientid) || empty($this->config->clientsecret)) {
            throw new \Exception('Please configure OpenID Connect client credentials.');
        }
        if (empty($this->config->authendpoint) || empty($this->config->tokenendpoint)) {
            throw new \Exception('Please configure OpenID Connect server endpoints.');
        }
        $redirecturi = new moodle_url('/auth/oidc/');

        $httpclient = new \auth_oidc\httpclient;
        $client = new \auth_oidc\oidcclient($this->httpclient);
        $client->setcreds($this->config->clientid, $this->config->clientsecret, $redirecturi->out());
        $client->setendpoints(['auth' => $this->config->authendpoint, 'token' => $this->config->tokenendpoint]);
        return $client;
    }

    /**
     * Initiate an authorization request to the configured OP.
     */
    public function initiateauthrequest() {
        $client = $this->get_oidcclient();
        $client->authrequest();
    }

    /**
     * Handle an authorization request response received from the configured OP.
     *
     * @param array $authparams Received parameters.
     */
    public function handleauthresponse(array $authparams) {
        global $DB, $CFG, $SESSION;

        $staterec = $DB->get_record('auth_oidc_state', ['state' => $authparams['state']]);
        if (empty($staterec)) {
            throw new \Exception('Unknown state.');
        }
        $orignonce = $staterec->nonce;
        $DB->delete_records('auth_oidc_state', ['id' => $staterec->id]);

        $client = $this->get_oidcclient();

        if (!isset($authparams['code'])) {
            throw new \Exception('Auth code not received.');
        }

        $tokenparams = $client->tokenrequest($authparams['code']);

        if (!isset($tokenparams['id_token'])) {
            throw new \Exception('OIDC id_token not received.');
        }

        $idtoken = \auth_oidc\jwt::instance_from_encoded($tokenparams['id_token']);

        $sub = $idtoken->claim('sub');
        if (empty($sub)) {
            throw new \Exception('Invalid id_token received.');
        }

        $receivednonce = $idtoken->claim('nonce');
        if (empty($receivednonce) || $receivednonce !== $orignonce) {
            throw new \Exception('Invalid id_token received');
        }

        if (isset($SESSION->auth_oidc_justevent)) {
            $eventdata = ['other' => ['authparams' => $authparams, 'tokenparams' => $tokenparams]];
            $event = \auth_oidc\event\user_authed::create($eventdata);
            $event->trigger();
            return true;
        }

        $oidcuniqid = $idtoken->claim('oid');
        if (empty($oidcuniqid)) {
            $oidcuniqid = $idtoken->claim('sub');
        }
        if (isloggedin() === true) {
            $this->handlemigration($oidcuniqid, $authparams, $tokenparams, $idtoken);
        } else {
            $this->handlelogin($oidcuniqid, $authparams, $tokenparams, $idtoken);
        }
    }

    /**
     * Handle OIDC disconnection from Moodle account.
     */
    public function disconnect() {
        global $OUTPUT, $PAGE, $USER, $DB, $CFG;
        $PAGE->set_url('/auth/oidc/ucp.php');
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('standard');
        $USER->editing = false;

        $ucptitle = get_string('ucp_disconnect_title', 'auth_oidc', $this->config->opname);
        $PAGE->navbar->add($ucptitle, $PAGE->url);
        $PAGE->set_title($ucptitle);

        // We need the manual login plugin to be enabled for disconnection.
        if (is_enabled_auth('manual') !== true) {
            throw new \Exception('Cannot disconnect because manual login plugin is not enabled.');
        }

        // Check to see if the user has a username created by OIDC, or a self-created username.
        // OIDC-created usernames are usually very verbose, so we'll allow them to choose a sensible one.
        // Otherwise, keep their existing username.
        $oidctoken = $DB->get_record('auth_oidc_token', ['username' => $USER->username]);
        $customdata = [
            'canchooseusername' => (strtolower($oidctoken->oidcuniqid) === $USER->username) ? true : false,
        ];

        $mform = new \auth_oidc\form\disconnect('?action=disconnect', $customdata);

        if ($mform->is_cancelled()) {
            redirect(new \moodle_url('/auth/oidc/ucp.php'));
        } else if ($fromform = $mform->get_data()) {

            if (empty($fromform->password)) {
                throw new \Exception('Password cannot be empty');
            }

            if ($customdata['canchooseusername'] === true) {
                if (empty($fromform)) {
                    throw new \Exception('Username cannot be empty');
                }

                if ($fromform->username !== $USER->username) {
                    $usercheck = ['username' => $fromform->username, 'mnethostid' => $CFG->mnet_localhost_id];
                    $existinguser = $DB->get_record('user', $usercheck);
                    if (!empty($existinguser)) {
                        throw new \Exception('That username is already taken. Please choose a different one.');
                    } else {
                        // Update username.
                        $updateduser = ['id' => $USER->id, 'username' => $fromform->username];
                        $DB->update_record('user', (object)$updateduser);
                    }
                }
            }

            // Set auth.
            $DB->update_record('user', (object)['id' => $USER->id, 'auth' => 'manual']);

            // Update password.
            $user = $DB->get_record('user', ['id' => $USER->id]);
            update_internal_user_password($user, $fromform->password);

            // Delete token data.
            $DB->delete_records('auth_oidc_token', ['username' => $USER->username]);

            redirect(new \moodle_url('/auth/oidc/ucp.php'));
        }

        echo $OUTPUT->header();
        $mform->display();
        echo $OUTPUT->footer();
    }

    /**
     * Handle a user migration event.
     *
     * @param string $oidcuniqid A unique identifier for the user.
     * @param array $authparams Paramteres receieved from the auth request.
     * @param array $tokenparams Parameters received from the token request.
     * @param \auth_oidc\jwt $idtoken A JWT object representing the received id_token.
     */
    protected function handlemigration($oidcuniqid, $authparams, $tokenparams, $idtoken) {
        global $USER, $DB, $CFG;

        // Check if OIDC user is already connected to a Moodle user.
        $tokenrec = $DB->get_record('auth_oidc_token', ['oidcuniqid' => $oidcuniqid]);
        if (!empty($tokenrec)) {
            $existinguserparams = ['username' => $tokenrec->username, 'mnethostid' => $CFG->mnet_localhost_id];
            $existinguser = $DB->get_record('user', $existinguserparams);
            if (empty($existinguser)) {
                $DB->delete_records('auth_oidc_token', ['id' => $tokenrec->id]);
            } else {
                if ($USER->username === $tokenrec->username) {
                    // Already connected to current user.
                    if ($USER->auth !== 'oidc') {
                        // Update auth plugin.
                        $DB->update_record('user', (object)['id' => $USER->id, 'auth' => 'oidc']);
                    }
                    $tokenrec->authcode = $authparams['code'];
                    $tokenrec->token = $tokenparams['access_token'];
                    $tokenrec->expiry = $tokenparams['expires_on'];
                    $tokenrec->refreshtoken = $tokenparams['refresh_token'];
                    $tokenrec->idtoken = $tokenparams['id_token'];
                    $DB->update_record('auth_oidc_token', $tokenrec);
                    redirect(core_login_get_return_url());
                } else {
                    // OIDC user connected to user that is not us. Can't continue.
                    $err = 'The OpenID Connect user that authenticated is already connected to a Moodle user.';
                    throw new \Exception($err);
                }
            }
        }

        // Check if Moodle user is already connected to an OIDC user.
        $tokenrec = $DB->get_record('auth_oidc_token', ['username' => $USER->username]);
        if (!empty($tokenrec)) {
            if ($tokenrec->oidcuniqid === $oidcuniqid) {
                // Already connected to current user.
                if ($USER->auth !== 'oidc') {
                    // Update auth plugin.
                    $DB->update_record('user', (object)['id' => $USER->id, 'auth' => 'oidc']);
                }
                $tokenrec->authcode = $authparams['code'];
                $tokenrec->token = $tokenparams['access_token'];
                $tokenrec->expiry = $tokenparams['expires_on'];
                $tokenrec->refreshtoken = $tokenparams['refresh_token'];
                $tokenrec->idtoken = $tokenparams['id_token'];
                $DB->update_record('auth_oidc_token', $tokenrec);
                redirect(core_login_get_return_url());
            } else {
                throw new \Exception('You\'re already connected to a different OpenID Connect user.');
            }
        }

        // Create token data.
        $tokenrec = new \stdClass;
        $tokenrec->oidcuniqid = $oidcuniqid;
        $tokenrec->username = $USER->username;
        $tokenrec->scope = $tokenparams['scope'];
        $tokenrec->resource = $tokenparams['resource'];
        $tokenrec->authcode = $authparams['code'];
        $tokenrec->token = $tokenparams['access_token'];
        $tokenrec->expiry = $tokenparams['expires_on'];
        $tokenrec->refreshtoken = $tokenparams['refresh_token'];
        $tokenrec->idtoken = $tokenparams['id_token'];
        $tokenrec->id = $DB->insert_record('auth_oidc_token', $tokenrec);

        $eventdata = [
            'objectid' => $USER->id,
            'userid' => $USER->id,
            'other' => ['username' => $USER->username]
        ];
        $event = \auth_oidc\event\user_loggedin::create($eventdata);
        $event->trigger();

        // Update auth plugin.
        $DB->update_record('user', (object)['id' => $USER->id, 'auth' => 'oidc']);

        redirect(new \moodle_url('/auth/oidc/ucp.php'));
    }

    /**
     * Handle a login event.
     *
     * @param string $oidcuniqid A unique identifier for the user.
     * @param array $authparams Parameters receieved from the auth request.
     * @param array $tokenparams Parameters received from the token request.
     * @param \auth_oidc\jwt $idtoken A JWT object representing the received id_token.
     */
    protected function handlelogin($oidcuniqid, $authparams, $tokenparams, $idtoken) {
        global $DB, $CFG;
        $tokenrec = $DB->get_record('auth_oidc_token', ['oidcuniqid' => $oidcuniqid]);
        if (!empty($tokenrec)) {
            $username = $tokenrec->username;
            $tokenrec->authcode = $authparams['code'];
            $tokenrec->token = $tokenparams['access_token'];
            $tokenrec->expiry = $tokenparams['expires_on'];
            $tokenrec->refreshtoken = $tokenparams['refresh_token'];
            $tokenrec->idtoken = $tokenparams['id_token'];
            $DB->update_record('auth_oidc_token', $tokenrec);
        } else {
            $username = strtolower($oidcuniqid);
            $tokenrec = new \stdClass;
            $tokenrec->oidcuniqid = $oidcuniqid;
            $tokenrec->username = $username;
            $tokenrec->scope = $tokenparams['scope'];
            $tokenrec->resource = $tokenparams['resource'];
            $tokenrec->authcode = $authparams['code'];
            $tokenrec->token = $tokenparams['access_token'];
            $tokenrec->expiry = $tokenparams['expires_on'];
            $tokenrec->refreshtoken = $tokenparams['refresh_token'];
            $tokenrec->idtoken = $tokenparams['id_token'];
            $tokenrec->id = $DB->insert_record('auth_oidc_token', $tokenrec);
        }

        $existinguserparams = ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id];
        if ($DB->record_exists('user', $existinguserparams) !== true) {
            // User does not exist. Create user if site allows, otherwise fail.
            if (empty($CFG->authpreventaccountcreation)) {
                $user = create_user_record($oidcuniqid, null, 'oidc');
                $eventdata = [
                    'objectid' => $user->id,
                    'userid' => $user->id,
                    'other' => ['username' => $username]
                ];
                $event = \auth_oidc\event\user_created::create($eventdata);
                $event->trigger();
            } else {
                // Trigger login failed event.
                $failurereason = AUTH_LOGIN_NOUSER;
                $eventdata = ['other' => ['username' => $username, 'reason' => $failurereason]];
                $event = \core\event\user_login_failed::create($eventdata);
                $event->trigger();
                throw new \Exception('Invalid login.');
            }
        }

        $user = authenticate_user_login($username, null, true);
        if (empty($user)) {
            throw new \Exception('Login failed.');
        }

        $eventdata = [
            'objectid' => $user->id,
            'userid' => $user->id,
            'other' => ['username' => $user->username],
        ];
        $event = \auth_oidc\event\user_loggedin::create($eventdata);
        $event->trigger();

        complete_user_login($user);
        redirect(core_login_get_return_url());
    }

    /**
     * This is the primary method that is used by the authenticate_user_login()
     * function in moodlelib.php.
     *
     * This method should return a boolean indicating
     * whether or not the username and password authenticate successfully.
     *
     * Returns true if the username and password work and false if they are
     * wrong or don't exist.
     *
     * @param string $username The username (with system magic quotes)
     * @param string $password The password (with system magic quotes)
     *
     * @return bool Authentication success or failure.
     */
    public function user_login($username, $password) {
        global $CFG, $DB;

        // Check user exists.
        $userfilters = ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'auth' => 'oidc'];
        $userexists = $DB->record_exists('user', $userfilters);

        // Check token exists.
        $tokenrec = $DB->get_record('auth_oidc_token', ['username' => $username]);
        $code = optional_param('code', null, PARAM_RAW);
        $tokenvalid = (!empty($tokenrec) && !empty($code) && $tokenrec->authcode === $code) ? true : false;
        return ($userexists === true && $tokenvalid === true) ? true : false;
    }

    /**
     * Read user information from external database and returns it as array().
     * Function should return all information available. If you are saving
     * this information to moodle user-table you should honour synchronisation flags
     *
     * @param string $username username
     *
     * @return mixed array with no magic quotes or false on error
     */
    public function get_userinfo($username) {
        global $DB;

        $tokenrec = $DB->get_record('auth_oidc_token', ['username' => $username]);
        if (empty($tokenrec)) {
            return false;
        }

        $idtoken = \auth_oidc\jwt::instance_from_encoded($tokenrec->idtoken);

        $userinfo = [
            'lang' => 'en',
            'idnumber' => $username,
        ];

        $firstname = $idtoken->claim('given_name');
        if (!empty($firstname)) {
            $userinfo['firstname'] = $firstname;
        }

        $lastname = $idtoken->claim('family_name');
        if (!empty($lastname)) {
            $userinfo['lastname'] = $lastname;
        }

        $email = $idtoken->claim('email');
        if (!empty($email)) {
            $userinfo['email'] = $email;
        }

        return $userinfo;
    }

    /**
     * Indicates if moodle should automatically update internal user
     * records with data from external sources using the information
     * from get_userinfo() method.
     *
     * @return bool true means automatically copy data from ext to user table
     */
    public function is_synchronised_with_external() {
        return true;
    }

    /**
     * Returns true if this authentication plugin is "internal".
     *
     * Internal plugins use password hashes from Moodle user table for authentication.
     *
     * @return bool
     */
    public function is_internal() {
        return false;
    }

    /**
     * Cron function.
     */
    public function cron() {
        global $DB;

        $params = [time() - 5*60];
        $DB->delete_records_select('auth_oidc_state', 'timecreated < ?', $params);
    }

    /**
     * Prints a form for configuring this authentication plugin.
     *
     * This function is called from admin/auth.php, and outputs a full page with
     * a form for configuring this plugin.
     *
     * @param stdClass $config
     * @param array $err errors
     * @param array $userfields
     * @return void
     */
    public function config_form($config, $err, $userfields) {
        include(__DIR__.'/config.html');
    }

    /**
     * Processes and stores configuration data for this authentication plugin.
     *
     * @param object object with submitted configuration settings (without system magic quotes)
     */
    public function process_config($config) {
        set_config('opname', $config->opname, 'auth_oidc');
        set_config('clientid', $config->clientid, 'auth_oidc');
        set_config('clientsecret', $config->clientsecret, 'auth_oidc');
        set_config('authendpoint', $config->authendpoint, 'auth_oidc');
        set_config('tokenendpoint', $config->tokenendpoint, 'auth_oidc');
        return true;
    }
}