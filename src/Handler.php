<?php
declare(strict_types=1);

/*
 * 	Data base handler class
 *
 *	@package	sync*gw
 *	@subpackage	RoundCube data base
 *	@copyright	(c) 2008 - 2024 Florian Daeumling, Germany. All right reserved
 * 	@license 	LGPL-3.0-or-later
 */

namespace syncgw\interface\roundcube;

use syncgw\lib\Config;
use syncgw\lib\DB;
use syncgw\lib\DataStore;
use syncgw\lib\ErrorHandler;
use syncgw\lib\Log;
use syncgw\lib\Msg;
use syncgw\lib\Server;
use syncgw\lib\User;
use syncgw\lib\Util;
use syncgw\lib\XML;
use syncgw\interface\DBextHandler;
use rcmail;
use rcube_user;

class Handler extends \syncgw\interface\mysql\Handler implements DBextHandler {

	/**
	 * 	Group record
	 *
	 *  Handler::GROUP 	- Group id
	 *  Handler::NAME 	- Name
	 *  Handler::COLOR	- Color
	 *  Handler::LOAD	- Group loaded
	 *  Handler::ATTR	- fldAttribute flags
	 *
	 *	Categories
	 *
	 *  Handler::GROUP 	- Group id
	 *  Handler::NAME 	- Name
	 *  Handler::REFS 	- Number of references
	 *
	 *  Data record
	 *
	 *  Handler::GROUP 	- Group id
	 *  Handler::CID	- Category1;Category2...
	 *
	 **/
	const GROUP       	 = 'Group';							// record group
	const NAME        	 = 'Name';							// name of record
	const COLOR       	 = 'Color';							// color of group
	const LOAD 		  	 = 'Loaded';						// group is loaded
	const ATTR 		  	 = 'Attr';							// group attributes
	const REFS        	 = 'References';					// file reference
	const CID         	 = 'Category';						// record category

	const PLUGIN      	 = [ 'roundcube_plugin', '9.18.78' ];

	// constants from roundcube_select_for_sync.php
	const MAIL_FULL   	 = 'M';								// full mail box
	const ABOOK_MERGE 	 = 'X';								// merge address books
	const ABOOK_FULL  	 = 'A';								// full address book
	const ABOOK_SMALL  	 = 'P';								// only contacts with tlephone number assigned
	const CAL_FULL    	 = 'C';								// full calendar
	const TASK_FULL   	 = 'T';								// full task list
	const NOTES_FULL  	 = 'N';								// full notes

 	/**
	 * 	Roundcube mail handler
	 * 	@var rcmail
	 */
	public $RCube		 = null;

	/**
	 * 	Retry counter
	 * 	@var int
	 */
	public $Retry 		 = 0;

    /**
	 * 	Handler table
	 * 	@var array
	 */
	private $_hd		 = [];

	/**
	 * 	Configuration class pointer
	 * 	@var Config
	 */
	private $_cnf;

	/**
     * 	Singleton instance of object
     * 	@var Handler
     */
    private static $_obj = null;

    /**
	 *  Get class instance handler
	 *
	 *  @return - Class object
	 */
	public static function getInstance(): Handler {

		if (!self::$_obj) {

			self::$_obj = new self();
			self::$_obj->_cnf = Config::getInstance();

			// set error filter
			ErrorHandler::filter(E_NOTICE|E_DEPRECATED|E_WARNING, 'rcube_vcard.php');
			ErrorHandler::filter(E_NOTICE|E_DEPRECATED|E_WARNING, 'bootstrap.php');
			ErrorHandler::filter(E_NOTICE|E_DEPRECATED|E_WARNING, 'plugins/ident_switch');
			ErrorHandler::filter(E_NOTICE|E_DEPRECATED|E_WARNING, 'plugins/globaladdressbook');
			ErrorHandler::filter(E_NOTICE|E_DEPRECATED|E_WARNING, 'plugins/contextmenu_folder');
			ErrorHandler::filter(E_NOTICE|E_DEPRECATED|E_WARNING, 'plugins/message_highlight');
			ErrorHandler::filter(E_NOTICE|E_DEPRECATED|E_WARNING, 'plugins/calendar');
			ErrorHandler::filter(E_NOTICE|E_DEPRECATED|E_WARNING, 'plugins/libcalendaring');
			ErrorHandler::filter(E_NOTICE|E_WARNING|E_DEPRECATED, self::$_obj->_cnf->getVar(Config::RC_DIR));

			// set log message cods 20301-20400
			Log::getInstance()->setLogMsg([

					// warning messages
					20301 => 'Cannot locate RoundCube file [%s]',
			        20302 => 'Plugin \'%s\' not available - handler disabled',

					// Error reading external contact record [R91919]
					20311 => 'Error reading external %s [%s]',
					// Error adding external address book
					20312 => 'Error adding external %s',
					// Error updating external contact record [R774]
					20313 => 'Error updating external %s [%s]',
					// Error deleting external address book [G383]
					20314 => 'Error deleting external %s [%s]',
					// Record [R774] in adress book is read-only
					20315 => 'Record [%s] in %s is read-only',
					20316 => 'RoundCube authorization failed for user (%s) (Error code: %d)',
					20317 => 'Record [%s] in %s not found - if you\'re debugging please check synchonization status in RoundCube',

					// error messages
					20350 => 'No %s enabled for synchronization [%s]',
					20351 => 'MySQL error: %s in %s driver in line %d',
					20352 => ' set_include_path() failed',
			]);

			// data base enabled?
			if (strpos($be = self::$_obj->_cnf->getVar(Config::DATABASE), 'roundcube') === null &&
				strcmp($be, 'mail') === null)
				return self::$_obj;

			// roundcube ini file name
			$path = self::$_obj->_cnf->getVar(Config::RC_DIR).'/';
			// required for roundcube
			if (!defined('INSTALL_PATH'))
		    	define('INSTALL_PATH', $path);

		    // check file names
		    if (!file_exists($ini = $path.'program/include/iniset.php')) {

		        Log::getInstance()->logMsg(Log::ERR, 20301, $ini);
		        ErrorHandler::resetReporting();
 		        return self::$_obj;
		    }
		    if (!file_exists($mail = $path.'program/include/rcmail.php')) {

		        Log::getInstance()->logMsg(Log::ERR, 20301, $mail);
		        ErrorHandler::resetReporting();
 		        return self::$_obj;
		    }

			// include ./program/include in loading
			if (!strpos($i = ini_get('include_path'), INSTALL_PATH)) {

	            $i = INSTALL_PATH . 'program/include/'.$i;
	            if (!set_include_path($i)) {

		        	Log::getInstance()->logMsg(Log::ERR, 20352);
		       		return self::$_obj;
	            }
			}

	        // startup RoundCube environment
	        require_once($ini);
			require_once($mail);

			// reset max. execution timeout
			@set_time_limit(self::$_obj->_cnf->getVar(Config::EXECUTION));

			// get instance
			self::$_obj->RCube = rcmail::get_instance();

	        // initialize parent handler
	        self::$_obj->_hd[DataStore::SYSTEM] = parent::getInstance();

			// check main plugin
			if ((!$a = self::$_obj->RCube->plugins->get_info(self::PLUGIN[0])) ||
				version_compare(self::PLUGIN[1], $a['version']) > 0) {

	        	Log::getInstance()->logMsg(Log::WARN, 20302, self::PLUGIN[0]);
		        ErrorHandler::resetReporting();
 	        	return self::$_obj;
	    	}

	        // check and allocate handlers
	        foreach ([ DataStore::CALENDAR, DataStore::CONTACT, DataStore::NOTE,
	        		   DataStore::TASK, DataStore::MAIL] as $hid) {

	 	    	// no plugin required
	        	if ($hid & DataStore::CONTACT)
	   	       		$class = 'Contact';
	        	elseif ($hid & DataStore::CALENDAR)
	    	       	$class  = 'Calendar';
			    elseif ($hid & DataStore::TASK)
	    	       	$class  = 'Task';
			    elseif ($hid & DataStore::NOTE)
		   	       	$class  = 'Note';

	             // get handler file name
		       	$file = $class.'.php';
				if (!file_exists(Config::getInstance()->getVar(Config::ROOT).'roundcube-bundle/src/'.$file))
					continue;

				// allocate handler
				$class = 'syncgw\\interface\\roundcube\\'.$class;
				if (!(self::$_obj->_hd[$hid] = $class::getInstance(self::$_obj))) {

					unset(self::$_obj->_hd[$hid]);
	       	   		Log::getInstance()->logMsg(Log::WARN, 20302, Util::HID(Util::HID_ENAME, $hid));
					Msg::InfoMsg('Enabling data store handler "'.$file.'"');
				}
	        }

			// register shutdown function
			Server::getInstance()->regShutdown(__CLASS__);

	        ErrorHandler::resetReporting();
		}

		return self::$_obj;
	}

	/**
	 * 	Shutdown function
	 */
	public function delInstance(): void {

		// save synchronization preferences
		if (!self::$_obj)
			return;

		self::$_obj->_hd[DataStore::SYSTEM]->delInstance();

		// reset error reporting
		ErrorHandler::resetReporting();

		self::$_obj = null;
	}

    /**
	 * 	Collect information about class
	 *
	 * 	@param 	- Object to store information
  	 */
	public function getInfo(XML &$xml): void {

		$xml->addVar('Name', 'RoundCube data base handler');

		$xml->addVar('Opt', 'RoundCube');
		$xml->addVar('Stat',  'v'.RCMAIL_VERSION);

		$xml->addVar('Opt', 'Status');
		if (($be = $this->_cnf->getVar(Config::DATABASE)) == 'roundcube')
			$xml->addVar('Stat', 'Enabled');
		elseif ($be == 'mail')
			$xml->addVar('Stat', 'Sustentative');
		else {

			$xml->addVar('Stat', 'Disabled');
			return;
		}

		$xml->addVar('Opt', 'Application root directory');
		$p = $this->_cnf->getVar(Config::RC_DIR);
		if (!file_exists($p.'/program')) {

			$xml->addVar('Stat', '+++ ERROR: Base directory not found!');
			returnn;
		}
		$xml->addVar('Stat', '"'.$p.'"');

		$xml->addVar('Opt', 'Connected to RoundCube located in');
		$xml->addVar('Stat', (count($this->_hd) ? 'v'.' '.RCMAIL_VERSION : 'n/a'));

		$xml->addVar('Opt', 'Interface handler');
		if (!count($this->_hd)) {

			$xml->addVar('Stat', '+++ ERROR: Initialization failed!');
			return;
		}
		$xml->addVar('Stat', 'Initialized');

		$p = self::PLUGIN[0];
		$i = $this->RCube->plugins->get_info($p);
		$a = $this->RCube->plugins->active_plugins;
		$xml->addVar('Opt', '<a href="https://plugins.roundcube.net/#/packages/syncgw/roundcube-plugin" '.
					 'target="_blank">'.$p.'</a> plugin');
		if (!in_array($p, $a)) {

			ErrorHandler::resetReporting();
			$xml->addVar('Stat', sprintf('+++ ERROR: "%s" not active!', $p));
		} elseif ($i['version'] != 'dev-master' && version_compare(self::PLUGIN[1], $i['version']) > 0) {

			ErrorHandler::resetReporting();
			$xml->addVar('Stat', sprintf('+++ ERROR: Require plugin version "%s" - "%s" found!',
						  self::PLUGIN[1], $i['version']));
		} else
			$xml->addVar('Stat', 'v'.self::PLUGIN[1]);

		// get handler info
		foreach ($this->_hd as $hd => $obj) {

			if (is_object(($obj)) && $hd != DataStore::SYSTEM)
				$obj->getInfo($xml);
		}

		ErrorHandler::resetReporting();
	}

 	/**
	 * 	Authorize user in external data base
	 *
	 * 	@param	- User name
	 * 	@param 	- Host name
	 * 	@param	- User password
	 * 	@return - true=Ok; false=Not authorized
 	 */
	public function Authorize(string $user, string $host, string $passwd): bool {

		// any use domain specified?
		if (strpos($user, '@'))
			list($user, $host) = explode('@', $user);
		elseif ($dom = $this->RCube->config->get('username_domain')) {

			// force domain?
			if ($this->RCube->config->get('username_domain_forced'))
				$host = is_array($dom) ? $dom[$host] : $dom;
			// add host?
			elseif (!$host)
		        $host = is_array($dom) ? $dom[$host] : $dom;
		}

		// see roundcobe/index.php
	    $auth = $this->RCube->plugins->exec_hook('authenticate', [
       			'host' 		  => $this->RCube->autoselect_host(),
       			'user' 		  => $user.($host ? '@'.$host : ''),
       			'pass' 		  => $passwd,
       			'cookiecheck' => false,
       			'valid'       => 1,
	    		'error' 	  => null,
        ]);

        // perform real login
   	   	if (!$auth['valid'] || $auth['abort'] || !$this->RCube->login($auth['user'], $auth['pass'],
   	   		$auth['host'], $auth['cookiecheck'])) {

   	   		Log::getInstance()->logMsg(Log::DEBUG, 20316, $auth['user'], $this->RCube->login_error());
			ErrorHandler::resetReporting();
   	   		return false;
   	    }

   	    // set users time zone
   	    $this->_cnf->updVar(Config::TIME_ZONE, $this->RCube->config->get('timezone'));

	    // load internal user object
	    $usr = User::getInstance();
	    $usr->loadUsr($user, $host);

        // set external user id
        $this->RCube->set_user(new rcube_user($this->RCube->get_user_id()));

		// reset error reporting
		ErrorHandler::resetReporting();

		return true;
	}

	/**
	 * 	Perform query on external data base
	 *
	 * 	@param	- Handler ID
	 * 	@param	- Query command:<fieldset>
	 * 			  DataStore::ADD 	  Add record                             $parm= XML object<br>
	 * 			  DataStore::UPD 	  Update record                          $parm= XML object<br>
	 * 			  DataStore::DEL	  Delete record or group (inc. sub-recs) $parm= GUID<br>
	 * 			  DataStore::RGID     Read single record       	             $parm= GUID<br>
	 * 			  DataStore::GRPS     Read all group records                 $parm= None<br>
	 * 			  DataStore::RIDS     Read all records in group              $parm= Group ID or '' for record in base group
	 * 	@return	- According  to input parameter<fieldset>
	 * 			  DataStore::ADD 	  New record ID or false on error<br>
	 * 			  DataStore::UPD 	  true=Ok; false=Error<br>
	 * 			  DataStore::DEL	  true=Ok; false=Error<br>
	 * 			  DataStore::RGID	  XML object; false=Error<br>
	 * 			  DataStore::GRPS	  [ "GUID" => Typ of record ]<br>
	 * 			  DataStore::RIDS     [ "GUID" => Typ of record ]
	 */
	public function Query(int $hid, int $cmd, $parm = '') {

		$rc = false;

		// we don't serve internal calls
		if (!($hid & DataStore::EXT))
			return $this->_hd[DataStore::SYSTEM]->Query($hid, $cmd, $parm);

		$hid &= ~DataStore::EXT;

		// we do not support locking
		if (!isset($this->_hd[$hid]))
			return $rc;

		if (!isset(DB::OPS[$cmd]))
            Msg::ErrMsg(' Unknown query "'.sprintf('0x%04x', $cmd).'" on external data base for handler "'.
            		   Util::HID(Util::HID_ENAME, $hid).'"');
        else {

        	if (!($cmd & (DataStore::GRPS|DataStore::RIDS|DataStore::RNOK|DataStore::ADD))) {
        		$gid = $cmd & DataStore::UPD ? $parm->getVar('extID') : $parm;
            	Msg::InfoMsg('Perform "'.DB::OPS[$cmd].'" query on external data base for handler "'.
            				Util::HID(Util::HID_ENAME, $hid).'" on record ['.$gid.']');
        	} else
        		Msg::InfoMsg('Perform "'.DB::OPS[$cmd].'" query on external data base for handler "'.
            			Util::HID(Util::HID_ENAME, $hid).'"');
        }

        $rc = $this->_hd[$hid]->Query($hid, $cmd, $parm);

		// reset error reporting
		ErrorHandler::resetReporting();

		return $rc;
	}

	/**
	 * 	Get list of supported fields in external data base
	 *
	 * 	@param	- Handler ID
	 * 	@return	- [ field name ]
	 */
	public function getflds(int $hid): array {

		if (!isset($this->_hd[$hid]))
			return [];

		return $this->_hd[$hid]->getflds($hid);
	}

	/**
	 * 	Reload any cached record information in external data base
	 *
	 * 	@param	- Handler ID
	 * 	@return	- true=Ok; false=Error
	 */
	public function Refresh(int $hid): bool {

		if (!isset($this->_hd[$hid]))
			return false;

		return $this->_hd[$hid]->Refresh($hid);
	}

	/**
	 * 	Check trace record references
	 *
	 *	@param 	- Handler ID
	 * 	@param 	- External record array [ GUID ]
	 * 	@param 	- Mapping table [HID => [ GUID => NewGUID ] ]
	 */
	public function chkTrcReferences(int $hid, array $rids, array $maps): void {

		if (isset($this->_hd[$hid & ~DataStore::EXT]))
			$this->_hd[$hid & ~DataStore::EXT]->chkTrcReferences($hid, $rids, $maps);
	}

	/**
	 * 	Convert internal record to MIME
	 *
	 * 	@param	- Internal document
	 * 	@return - MIME message or null
	 */
	public function cnv2MIME(XML &$int): ?string {

		return null;
	}

	/**
	 * 	Convert MIME string to internal record
	 *
	 *	@param 	- External record id
	 * 	@param	- MIME message
	 * 	@return	- Internal record or null
	 */
	public function cnv2Int(string $rid, string $mime): ?XML {

		return null;
	}

	/**
	 * 	Send mail
	 *
	 * 	@param	- true=Save in Sent mail box; false=Only send mail
	 * 	@param	- MIME data OR XML document
	 * 	@return	- Internal XML document or null on error
	 */
	public function sendMail(bool $save, $doc): ?XML {

		return null;
	}

	/**
	 * 	Check Mysql handler
	 *
	 * 	@param 	- Handler ID
	 * 	@param 	- Line number
	 * 	@return - true = Error; false = No error
	 */
	public function chkRetry(int $hid, int $line): bool {

		if ($this->Retry < 1)
			$this->Retry = $this->_cnf->getVar(Config::DB_RETRY);

	    // get databse handler
		$db = $this->RCube->get_dbh();

    	// get error message
    	if (!($err = $db->is_error()))
    		return false;

    	// get error code
		$code = substr($err, 1, 4);

    	// [2006] MySQL server has gone away
    	if ($code == 2006) {

    		if ($this->Retry--) {

    			Util::Sleep(300);
			   	Log::getInstance()->logMsg(Log::DEBUG, 20351, $err, Util::Hid(Util::HID_ENAME, $hid), $line);
				return true;
    		}
    	}

    	Log::getInstance()->logMsg(Log::WARN, 20351, $err, Util::Hid(Util::HID_ENAME, $hid), $line);

		// unrecoverable error
		return false;
	}

}
