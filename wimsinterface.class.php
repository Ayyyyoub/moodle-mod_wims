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
 * Low level communication library for interfacing to a WIMS server
 *
 * @package   mod_wims
 * @copyright 2015 Edunao SAS <contact@edunao.com>
 * @author    Sadge <daniel@edunao.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(dirname(__FILE__)."/wimscommswrapper.class.php");

// Defines used for wims_interface::getstudenturl() and wims_interface::getteacherurl() calls.
define('WIMS_HOME_PAGE', 1);
define('WIMS_GRADE_PAGE', 2);
define('WIMS_WORKSHEET', 3);
define('WIMS_EXAM', 4);

/**
 * Low level communication library for interfacing to a WIMS server
 *
 * @category external
 * @package  mod_wims
 * @author   Sadge <daniel@edunao.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link     https://github.com/suipnice/moodle-mod_wims
 *
 * @var array  $erromsgs In the case where an error is encounterd this variable will contain error message as an array of lines.
 * @var object $wims   wims_comms_wrapper object
 * @var string $qcl    querried WIMS class ID
 * @var string $rcl    local course ID (remote class for WIMS)
 * @var object $config the WIMS configuration object
 */
class wims_interface{
    public $erromsgs;
    private $wims;
    private $qcl;
    private $rcl;
    private $config;

    /**
     * Ctor (the class constructor)
     * stores away the supplied parametersbut performs no actions
     *
     * @param object  $config the WIMS configuration object
     * @param integer $debug  enables verbose output when set true
     */
    public function __construct($config, $debug=0) {
        $allowselfsignedcertificates =
            (property_exists($config, 'allowselfsigcerts')
            && ($config->allowselfsigcerts == true)) ? true : false;
        $this->wims = new wims_comms_wrapper($config->serverurl, $config->serverpassword, $allowselfsignedcertificates);
        $this->wims->debug = $debug;
        $this->config = $config;
    }

    /**
     * Attempt to connect to the WIMS server and verify that it responds with an OK message
     *
     * @return true if the connection attempt succeeded, null if it failed
     */
    public function testconnection() {
        // Try connecting to the server using both of the required API modes.
        $wimsresult = $this->wims->checkidentwims();
        $jsonresult = $this->wims->checkidentjson();

        // If both of the connection tests succeeded then we're done.
        if ($wimsresult && $jsonresult) {
            return true;
        }

        // At least one of the connection tests failed so construst an erro message and return NULL.
        $this->errormsgs = array();
        $this->errormsgs[] = 'WIMS connection test failed:';
        ($wimsresult === true) || $this->errormsgs[] = '- WIMS interface: ' . (($wimsresult === true) ? 'OK' : 'FAILED');
        ($jsonresult === true) || $this->errormsgs[] = '- JSON interface: ' . (($wimsresult === true) ? 'OK' : 'FAILED');
        return null;
    }

    /**
     * Select the class on the wims server with which to work (for a given Moodle WIMS module instance)
     * If the class doesn't exist then this routine will create it.
     *
     * @param object $course the current moodle course object
     * @param object $cm     the course module that the wims class is bound to. It should include:
     *                       integer $cm->id   the course module's unique id
     *                       string  $cm->name the course module instance name
     *
     * @return true on success, null if one failed
     */
    public function selectclassformodule($course, $cm) {
        // Start by determining the identifiers for the class.
        $this->initforcm($cm);

        // Work out what language to use
        // by default we use the config language
        // but if the course includes an ovveride then we need to use it.
        $this->lang = (property_exists($course, "lang")&&($course->lang != "")) ? $course->lang : $this->config->lang;

        // Try to connect and drop out if we managed it.
        $checkresult = $this->wims->checkclass($this->qcl, $this->rcl);
        if ($checkresult === true) {
            return true;
        }

        // Try to create the WIMS class.
        global $DB;
        $wimsinfo = $DB->get_record('wims', array('id' => $cm->instance));
        $randomvalue1 = rand(100000, 999999);
        $data1 =
            "description=$cm->name"."\n".
            "institution=$wimsinfo->userinstitution"."\n".
            "supervisor=$wimsinfo->username"."\n".
            "email=$wimsinfo->useremail"."\n".
            "password=Pwd$randomvalue1"."\n".
            "lang=$this->lang"."\n".
            "secure=all"."\n";
        $randomvalue2 = rand(100000, 999999);
        $data2 =
            "lastname=$wimsinfo->userlastname"."\n".
            "firstname=$wimsinfo->userfirstname"."\n".
            "password=Pwd$randomvalue2"."\n";
        $addresult = $this->wims->addclass($this->qcl, $this->rcl, $data1, $data2);

        // Ensure that everything went to plan.
        if ($addresult !== true) {
            $this->errormsgs = $this->wims->linedata;
            return null;
        }

        // Try to modify the class that we just created to set the connection rights.
        $data1 = $this->constructconnectsline();
        $modresult = $this->wims->updateclass($this->qcl, $this->rcl, $data1);

        // Ensure that everything went to plan.
        if ($modresult === true) {
            return true;
        } else {
            $this->errormsgs = $this->wims->linedata;
            return null;
        }
    }

    /**
     * Attempt to access a WIMS class for a given moodle module - to verify whether it is generally accessible
     *
     * @param object $cm the course module that the wims class is bound to. It should include:
     *                   integer $cm->id the course module's unique id
     *
     * @return true on success, null if on failure
     */
    public function verifyclassaccessible($cm) {
        // Start by determining the identifiers for the class.
        $this->initforcm($cm);

        // Delegate to the wims comms wrapper to do the work.
        return $this->wims->checkclass($this->qcl, $this->rcl, true) ? true : null;
    }

    /**
     * Create a WIMS login from a user record
     *
     * @param object $user including the following:
     *                     string $user->id        the user's unique id from within moodle
     *                     string $user->firstname the user's first name
     *                     string $user->lastname  the user's last name
     *
     * @return string login for use in wims
     */
    public function generatewimslogin($user) {
        // Lookup our configuration to see whether or not we are supposed to use the user name
        // in the WIMS login. Using the user name in the WIMS login has the advantage of making
        // the login more readable but the disadvantage of breaking the link between moodle and
        // WIMS accounts if ever the user's profile is updated in MOODLE.
        if ($this->config->usenameinlogin == 1) {
            // Start by assembling the basic string parts that we're interested in.
            $initial  = ($user->firstname) ? $user->firstname[0] : '';
            $fullname = strtolower($initial . $user->lastname);
            // Now filter out all of the characters that we don't like in the user name.
            $cleanname = '';
            // We limit the name length to 16 characters because of an internal limit in WIMS.
            for ($i = 0; $i < strlen($fullname)&&strlen($cleanname) < 16; ++$i) {
                $letter = $fullname[$i];
                if ($letter >= 'a' && $letter <= 'z') {
                    $cleanname .= $letter;
                }
            }
            // Add the user id on the end and call it done.
            $result = $cleanname . $user->id;
            return $result;
        } else {
            // Add the user id on the end and call it done.
            $result = 'moodleuser' . $user->id;
            return $result;
        }
    }

    /**
     * Create a WIMS session for the given user, connecting them to this course and return an access url
     *
     * @param object $user        including the following:
     *                            string $user->firstname the user's first name
     *                            string $user->lastname the user's last name
     *                            string $user->username the user's login name
     * @param string $currentlang current language (to force the wims site language to match the moodle language)
     * @param string $urltype     the type of url required (defaults to 'home page')
     * @param string $arg         the argument to be used for selecting which worksheet or exam page to display,
                                  depending on $urltype
     *
     * @return string connection URL for the user to use to access the session if the operation succeeded, null if it failed
     */
    public function getstudenturl($user, $currentlang, $urltype=WIMS_HOME_PAGE, $arg=null) {
        // Derive the WIMS login from the MOODLE user data record.
        $login = $this->generatewimslogin($user);

        // Check if the user exists within the given course.
        $checkresult = $this->wims->checkuser($this->qcl, $this->rcl, $login);
        if ($checkresult == null) {
            // The user doesn't exist so try to create them.
            $firstname = $user->firstname;
            $lastname = $user->lastname;
            $addresult = $this->wims->adduser($this->qcl, $this->rcl, $firstname, $lastname, $login);
            if ($addresult == null) {
                // If the call to adduser failed then deal with it.
                $this->errormsgs = $this->wims->linedata;
                return null;
            }
        }

        // The user should exist now so create the session and return it's access url.
        switch($urltype) {
            case WIMS_HOME_PAGE  :
                return $this->gethomepageurlforlogin($login, $currentlang);
            case WIMS_GRADE_PAGE :
                return $this->getscorepageurlforlogin($login, $currentlang);
            case WIMS_WORKSHEET  :
                return $this->getworksheeturlforlogin($login, $currentlang, $arg);
            case WIMS_EXAM       :
                return $this->getexamurlforlogin($login, $currentlang, $arg);
            default :
                throw new Exception('BUG: Bad urltype parameter '.$urltype);
        }
    }

    /**
     * Create a WIMS supervisor session for this course and return an access url
     *
     * @param string $currentlang current language
                                  (to force the WIMS site language to match the Moodle language)
     * @param string $urltype     the type of url required (defaults to 'home page')
     * @param string $arg         the argument to be used for selecting which worksheet or exam page to display,
                                  depending on $urltype
     *
     * @return string connection URL for the user to use to access the session if the operation succeeded, null if it failed
     */
    public function getteacherurl($currentlang, $urltype=WIMS_HOME_PAGE, $arg=null) {
        // The "supervisor" login is a special login bound by WIMS,
        // using it we get the url to the teacher's page and not the student page.
        $login = "supervisor";
        switch($urltype) {
            case WIMS_HOME_PAGE  :
                return $this->gethomepageurlforlogin($login, $currentlang);
            case WIMS_GRADE_PAGE :
                return $this->getscorepageurlforlogin($login, $currentlang);
            case WIMS_WORKSHEET  :
                return $this->getworksheeturlforlogin($login, $currentlang, $arg);
            case WIMS_EXAM       :
                return $this->getexamurlforlogin($login, $currentlang, $arg);
            default:
                throw new Exception('BUG: Bad urltype parameter '.$urltype);
        }
    }

    /**
     * Fetch the class config from the WIMS server (for a given Moodle WIMS module instance)
     * Note that it is valid for this method to be called for classes that have
     * not yet been instantiated on the WIMS server
     *
     * @param object $cm the course module that the wims class is bound to
     *
     * @return associate array course propert values on success or null on fail
     */
    public function getclassconfigformodule($cm) {
        // Start by determining the identifiers for the class.
        $this->initforcm($cm);

        // Try to fetch the class config.
        $classconfig = $this->wims->getclassconfig($this->qcl, $this->rcl);
        if ($classconfig == null) {
            return null;
        }

        // Try to fetch the supervisor user config.
        $userconfig = $this->wims->getuserconfig($this->qcl, $this->rcl, "supervisor");
        if ($userconfig == null) {
            return null;
        }

        // Combine the two.
        $result = array_merge($userconfig, $classconfig);

        // Fetch the list of worksheets and add them to the result one by one.
        $result["worksheets"] = array();
        $worksheetids = $this->wims->getworksheetlist($this->qcl, $this->rcl);
        foreach ($worksheetids as $sheetid => $sheetinfo) {
            $sheetconfig = $this->wims->getworksheetproperties($this->qcl, $this->rcl, $sheetid);
            $result["worksheets"][$sheetid] = $sheetconfig;
        }

        // Fetch the list of exams and add them to the result one by one.
        $result["exams"] = array();
        $examids = $this->wims->getexamlist($this->qcl, $this->rcl);
        foreach ($examids as $examid => $examinfo) {
            $examconfig = $this->wims->getexamproperties($this->qcl, $this->rcl, $examid);
            $result["exams"][$examid] = $examconfig;
        }

        return $result;
    }

    /**
     * Update the class config on the WIMS server (if the class exist) (for a given Moodle WIMS module instance)
     * Note that it is valid for this method to be called for classes that have
     * not yet been instantiated on the WIMS server
     *
     * @param object $cm   the course module that the wims class is bound to
     * @param array  $data an associative array of data values
     *
     * @return true on success, null on failure
     */
    public function updateclassconfigformodule($cm, $data) {
        // Start by determining the identifiers for the class.
        $this->initforcm($cm);

        // Build and apply updated class parameters.
        $classdata = "";
        $classdata .= $this->dataline($data, "description");
        $classdata .= $this->dataline($data, "institution");
        $classdata .= $this->dataline($data, "supervisor");
        $classdata .= $this->dataline($data, "email");
        $classdata .= $this->dataline($data, "lang");
        $classdata .= $this->dataline($data, "expiration");
        if ($classdata != "") {
            $result = $this->wims->updateclass($this->qcl, $this->rcl, $classdata);
            if ($result == null) {
                $this->wims->debugmsg(__FILE__.':'.__LINE__.': wims interface returning NULL due to comms wrapper null result');
                return null;
            }
        }

        // Build and apply updated supervisor parameters.
        $userdata = "";
        $userdata .= $this->dataline($data, "lastname");
        $userdata .= $this->dataline($data, "firstname");
        $userdata .= $this->dataline($data, "email");
        if ($userdata != "") {
            $result = $this->wims->updateclasssupervisor($this->qcl, $this->rcl, $userdata);
            if ($result == null) {
                $this->wims->debugmsg(__FILE__.':'.__LINE__.': wims interface returning NULL due to comms wrapper null result');
                return null;
            }
        }

        // Update worksheets.
        foreach ($data["worksheets"] as $sheetid => $sheetconfig) {
            $sheetdata = "";
            foreach ($sheetconfig as $prop => $val) {
                $sheetdata .= $prop.'='.$val."\n";
            }
            if ($sheetdata != "") {
                $result = $this->wims->updateworksheetproperties($this->qcl, $this->rcl, $sheetid, $sheetdata);
                if ($result == null) {
                    $this->wims->debugmsg(__FILE__.':'.__LINE__.': wims interface returning NULL due to comms wrapper null result');
                    return null;
                }
            }
        }

        // Update exams.
        foreach ($data["exams"] as $examid => $examconfig) {
            $examdata = "";
            foreach ($examconfig as $prop => $val) {
                $examdata .= $prop.'='.$val."\n";
            }
            if ($examdata != "") {
                $result = $this->wims->updateexamproperties($this->qcl, $this->rcl, $examid, $examdata);
                if ($result == null) {
                    $this->wims->debugmsg(__FILE__.':'.__LINE__.': wims interface returning NULL due to comms wrapper null result');
                    return null;
                }
            }
        }
        return true;
    }

    /**
     * Fetch associative arrays of id=>info for worksheets and exams that compose the given WIMS class
     * Each object in the result has the following fields:
     * - title string containing the item title
     * - the string containing state flag provided by WIMS
     *
     * @param object $cm the course module that the wims class is bound to
     *
     * @return array of arrays of objects on success, null on failure
     */
    public function getsheetindex($cm) {
        // Start by determining the identifiers for the class.
        $this->initforcm($cm);

        // Setup a result object.
        $result = array();

        // Ask WIMS for a list of worksheets.
        $sheetlist = $this->wims->getworksheetlist($this->qcl, $this->rcl);
        if ($sheetlist === null) {
            $this->wims->debugmsg(__FILE__.':'.__LINE__.': wims interface returning NULL due to comms wrapper null result');
            return null;
        }
        $result['worksheets'] = $sheetlist;

        // Ask WIMS for a list of exams.
        $examlist = $this->wims->getexamlist($this->qcl, $this->rcl);
        if ($examlist === null) {
            $this->wims->debugmsg(__FILE__.':'.__LINE__.': wims interface returning NULL due to comms wrapper null result');
            return null;
        }
        $result['exams'] = $examlist;

        // Return the result object.
        return $result;
    }

    /**
     * Fetch the scores for the given set of worksheets and exams from the given WIMS class
     *
     * @param object                   $cm             the course module that the wims class is bound to
     * @param array of array of string $requiredsheets the identifiers of the exams and worksheets requested
     *
     * @return array of arrays of objects on success, null on failure
     */
    public function getsheetscores($cm, $requiredsheets) {
        // Start by determining the identifiers for the class.
        $this->initforcm($cm);

        // Setup a result object.
        $result = array();

        // Iterate over worksheets.
        if (array_key_exists('worksheets', $requiredsheets)) {
            $result['worksheets'] = array();
            foreach ($requiredsheets['worksheets'] as $sheetid) {
                // Ask WIMS for the worksheet scores.
                $sheetdata = $this->wims->getworksheetscores($this->qcl, $this->rcl, $sheetid);
                if (!$sheetdata) {
                    $this->wims->debugmsg(__FILE__.':'.__LINE__.': wims interface returning NULL due to comms wrapper null result');
                    return null;
                }
                // Iterate over user score records.
                foreach ($sheetdata as $userscore) {
                    $result['worksheets'][$sheetid][$userscore->id] = floatval($userscore->user_percent) * 0.1;
                }
            }
        }

        // Iterate over exams.
        if (array_key_exists('exams', $requiredsheets)) {
            $result['exams'] = array();
            foreach ($requiredsheets['exams'] as $sheetid) {
                // Ask WIMS for the exam scores.
                $sheetdata = $this->wims->getexamscores($this->qcl, $this->rcl, $sheetid);
                if (!$sheetdata) {
                    $this->wims->debugmsg(__FILE__.':'.__LINE__.': wims interface returning NULL due to comms wrapper null result');
                    return null;
                }
                // Iterate over user score records.
                foreach ($sheetdata as $userscore) {
                    $result['exams'][$sheetid][$userscore->id] = $userscore->score;
                }
            }
        }

        // Return the result object.
        return $result;
    }

    /**
     * Private utility routine
     *
     * @param array  $data data
     * @param string $prop prop
     *
     * @return string
     */
    private function dataline($data, $prop) {
        if (array_key_exists($prop, $data)) {
            return $prop."=".$data[$prop]."\n";
        } else {
            return "";
        }
    }

    /**
     * Private utility routine
     *
     * @param string $login       login
     * @param string $currentlang current lang code
     *
     * @return string $accessurl
     */
    private function gethomepageurlforlogin($login, $currentlang) {
        // Attempt to create the WIMS session.
        $accessurl = $this->wims->gethomepageurl($this->qcl, $this->rcl, $login, $currentlang);

        // On failure setup the error message.
        if ($accessurl == null) {
            $this->errormsgs = $this->wims->linedata;
        }

        // Construct the result URL.
        return $accessurl;
    }

    /**
     * Private utility routine
     *
     * @param string $login       login
     * @param string $currentlang current lang code
     *
     * @return string $accessurl
     */
    private function getscorepageurlforlogin($login, $currentlang) {
        // Attempt to create the WIMS session.
        $accessurl = $this->wims->getscorepageurl($this->qcl, $this->rcl, $login, $currentlang);

        // On failure setup the error message.
        if ($accessurl == null) {
            $this->errormsgs = $this->wims->linedata;
        }

        // Construct the result URL.
        return $accessurl;
    }

    /**
     * Private utility routine
     *
     * @param string $login       login
     * @param string $currentlang current lang
     * @param string $sheet       sheet id
     */
    private function getworksheeturlforlogin($login, $currentlang, $sheet) {
        // Attempt to create the WIMS session.
        $accessurl = $this->wims->getworksheeturl($this->qcl, $this->rcl, $login, $currentlang, $sheet);

        // On failure setup the error message.
        if ($accessurl == null) {
            $this->errormsgs = $this->wims->linedata;
        }

        // Construct the result URL.
        return $accessurl;
    }

    /**
     * Private utility routine
     *
     * @param string $login       login
     * @param string $currentlang current lang code
     * @param string $exam        exam id
     */
    private function getexamurlforlogin($login, $currentlang, $exam) {
        // Attempt to create the WIMS session.
        $accessurl = $this->wims->getexamurl($this->qcl, $this->rcl, $login, $currentlang, $exam);

        // On failure setup the error message.
        if ($accessurl == null) {
            $this->errormsgs = $this->wims->linedata;
        }

        // Construct the result URL.
        return $accessurl;
    }

    /**
     * Private utility routine
     */
    private function constructconnectsline() {
        return "connections=+moodle/$this->rcl+ +moodlejson/$this->rcl+ +moodlehttps/$this->rcl+ +moodlejsonhttps/$this->rcl+";
    }

    /**
     * Private utility routine
     *
     * @param object $cm course module object
     */
    private function initforcm($cm) {
        // Setup the unique WIMS class identifier.
        $this->qcl = "".($this->config->qcloffset + $cm->id);
        // Setup the 'owner' identifier (derived from the Moodle class id).
        $this->rcl = "moodle_$cm->id";
    }
}
