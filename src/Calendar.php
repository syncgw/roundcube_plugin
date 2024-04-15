<?php
declare(strict_types=1);

/*
 * 	Calendar handler class
 *
 *	@package	sync*gw
 *	@subpackage	RoundCube data base
 *	@copyright	(c) 2008 - 2024 Florian Daeumling, Germany. All right reserved
 * 	@license 	LGPL-3.0-or-later
 */

namespace syncgw\interface\roundcube;

use syncgw\lib\Attachment;
use syncgw\lib\Config;
use syncgw\lib\DB;
use syncgw\lib\DataStore;
use syncgw\lib\Encoding;
use syncgw\lib\ErrorHandler;
use syncgw\lib\Log;
use syncgw\lib\Msg;
use syncgw\lib\Trace;
use syncgw\lib\User;
use syncgw\lib\Util;
use syncgw\lib\XML;
use calendar_driver;
use database_driver;
use libcalendaring;
use syncgw\document\field\fldUid;
use syncgw\document\field\fldStartTime;
use syncgw\document\field\fldEndTime;
use syncgw\document\field\fldRecurrence;
use syncgw\document\field\fldSummary;
use syncgw\document\field\fldBody;
use syncgw\document\field\fldLocation;
use syncgw\document\field\fldCategories;
use syncgw\document\field\fldConference;
use syncgw\document\field\fldBusyStatus;
use syncgw\document\field\fldPriority;
use syncgw\document\field\fldClass;
use syncgw\document\field\fldStatus;
use syncgw\document\field\fldAttendee;
use syncgw\document\field\fldOrganizer;
use syncgw\document\field\fldAlarm;
use syncgw\document\field\fldAttach;
use syncgw\document\field\fldExceptions;
use syncgw\document\field\fldTrigger;
use syncgw\document\field\fldMailOther;
use syncgw\document\field\fldGroupName;
use syncgw\document\field\fldColor;
use syncgw\document\field\fldAttribute;

class Calendar {

    const MAP 		  	= [
    // ----------------------------------------------------------------------------------------------------------------------------------------------------------
	// fld definitions calendar/drivers/calendar_driver.php + libcalendaring/libvcalendar.php:_to_ical()
    // 	0 - String
    //  1 - String (convert to upper case)
    //  2 - Date
    //  3 - Recurrence array
    //  4 - Attendee array
	//  5 - Organizer array
    //  6 - VALARM
    //  7 - Attachment
    //  8 - Free_busy
    //  9 - Location
    // 10 - Instance id
    // 11 - Skip
    // ----------------------------------------------------------------------------------------------------------------------------------------------------------
 	//  'id'																	// Event ID used for editing
    	'uid'						=> [ 0, fldUid::TAG,	 				],	// Unique identifier of this event
    //	'calendar'																// Calendar identifier to add event to or where the event is stored
    	'start'						=> [ 2, fldStartTime::TAG, 				],	// Event start date/time
    	'end'						=> [ 2, fldEndTime::TAG,	 			],	// Event end date/time
	// 	'allday'																// Boolean flag if this is an all-day event
    //	'changed'																// Last modification date of event
    	'title'						=> [ 0, fldSummary::TAG,	 			],	// Event title/summary
	    'location'					=> [ 9, fldLocation::TAG, 				],	// Location string
    	'description'               => [ 0, fldBody::TAG, 					],	// Event description
    	'url'                       => [ 0, fldConference::TAG, 			],	// URL to more information
	    'recurrence'                => [ 3, fldRecurrence::TAG, 			],	// Recurrence definition according to iCalendar (RFC 2445)
	//	'EXDATE'																// list of DateTime objects of exception
    //	'EXCEPTION'																// list of event objects which denote exceptions in the recurrence chain
    // 	'recurrence_id'															// ID of the recurrence group
   		'_instance'					=> [ 10, fldExceptions::TAG				],	// ID of the recurring instance
		'categories'                => [ 0, fldCategories::TAG, 			],	// Event category
		'free_busy'    				=> [ 8, fldBusyStatus::TAG, 			],	// Show time as
	    'status'                    => [ 0, fldStatus::TAG, 				],	// event status according to RFC 2445
    	'priority'                  => [ 0, fldPriority::TAG, 				],	// Event priority - not used by MAS
	    'sensitivity'               => [ 1, fldClass::TAG, 					],	// Event sensitivity
	//	'alarms'																// DEPRECATED
    	'valarms'                   => [ 6, fldAlarm::TAG, 					],	// List of reminders
		'attendees'                 => [ 4, fldAttendee::TAG, 				],	// list of email addresses to receive alarm messages
    	'organizer'					=> [ 5, fldOrganizer::TAG 				],	// additional tag
    	'attachments'	           	=> [ 7, fldAttach::TAG, 				],	// List of attachments
	//	'deleted_attachments'													// array of attachment identifiers to delete when event is updated
	// 	'_savemode'																// How changes on recurring event should be handled
	//	'_notify'																// whether to notify event attendees about changes
	//	'_fromcalendar'															// Calendar identifier where the event was stored before

    //	'isexception'															// undocumented
    //	'created' 																// undocumented
    //	'sequence										'						// ignored (not supported by insert_event()

    // some fields only included for syncDS() - not part of data record

    	'#trigger'					=> [ 11, fldTrigger::TAG,				],
    	'#mailother'				=> [ 11, fldMailOther::TAG,				],
		'#grp_name'					=> [ 11, fldGroupName::TAG,	 			],
  		'#grp_color'				=> [ 11, fldColor::TAG,					],
		'#grp_attr'					=> [ 11, fldAttribute::TAG,				],

    // ----------------------------------------------------------------------------------------------------------------------------------------------------------
    ];

    // supported busy time (see X-MICROSOFT-CDO-BUSYSTATUS)
    // see database_driver.php:45
	const BUSY        	= [
            'free'          => 'FREE',
		    'busy'          => 'BUSY',
		    'outofoffice'   => 'OOF',
		    'tentative'     => 'TENTATIV',
	];

	// start time window
	const START       	= 'today -10 year 00:00:00';
    // end time window
    const END         	= 'today +10 year 23:59:59';

    // roundcube database table names
	const CALENDARS   	= 0;
    const EVENTS      	= 1;
    const ATTACHMENTS 	= 2;

    const PLUGIN 		= [ 'calendar' => '3.5.11', 'libcalendaring' => '3.5.11' ];

    /**
	 *  RoundCube database table names
	 *  @var array
	 */
	private $_tab;

	/**
	 * 	Calendar table
	 * 	@var array
	 */
	private $_ids = null;

	/**
	 * 	Synchronization preference
	 * 	@var string
	 */
	private $_pref;

	/**
	 *  Pointer to RoundCube main handler
	 *  @var Handler
	 */
	private $_hd;

	/**
     *  Internal default group id
     *  @var string
     */
	private $_gid;

	/**
	 * 	Message output class pointer
	 * 	@var Msg
	 */
	private $_msg;

	/**
	 * 	Configuration class pointer
	 * 	@var Config
	 */
	private $_cnf;

    /**
     * 	Singleton instance of object
     * 	@var Calendar
     */
    static private $_obj = null;

    /**
	 *  Get class instance handler
	 *
	 *  @param  - Pointer to handler class
	 *  @return - Class object
	 */
	public static function getInstance(Handler &$hd): Calendar {

		if (!self::$_obj) {

            self::$_obj = new self();

			self::$_obj->_hd = $hd;
			self::$_obj->_msg = Log::getInstance();
			self::$_obj->_cnf = Config::getInstance();

			// _database_driver.php:__construct()
	        // read database config
	        $db = $hd->RCube->get_dbh();
            self::$_obj->_tab[self::CALENDARS]   = '`'.$hd->RCube->config->get('db_table_tasks', $db->table_name('calendars')).'`';
	        self::$_obj->_tab[self::EVENTS]      = '`'.$hd->RCube->config->get('db_table_lists', $db->table_name('events')).'`';
	        self::$_obj->_tab[self::ATTACHMENTS] = '`'.$hd->RCube->config->get('db_table_tasks', $db->table_name('attachments')).'`';

			// check plugin version
			foreach (self::PLUGIN as $name => $ver) {

	    		$a = $hd->RCube->plugins->get_info($name);
	    		if (version_compare($ver, $a['version']) < 0)
	    			return self::$_obj ;
			}

 		}

		return self::$_obj;
	}

    /**
	 * 	Collect information about class
	 *
	 * 	@param 	- Object to store information
	 */
	public function getInfo(XML &$xml): void {

		$xml->addVar('Name',sprintf('RoundCube %s handler', Util::HID(Util::HID_ENAME, DataStore::CALENDAR)));

		// check plugin version
		foreach (self::PLUGIN as $name => $ver) {

			$i = $this->_hd->RCube->plugins->get_info($name);
			$a = $this->_hd->RCube->plugins->active_plugins;
			$xml->addVar('Opt', '<a href="https://plugins.roundcube.net/#/packages/kolab/'.$name.'" '.
						 'target="_blank">'.$name.'</a>  plugin');
			if (!in_array($name, $a)) {

				ErrorHandler::resetReporting();
				$xml->addVar('Stat', sprintf('+++ ERROR: "%s" not active!', $name));
			} elseif ($i['version'] != 'dev-master' && version_compare($ver, $i['version']) > 0) {

				ErrorHandler::resetReporting();
				$xml->addVar('Stat', sprintf('+++ ERROR: Require plugin version "%s" - "%s" found!',
							  $ver, $i['version']));
			} else
				$xml->addVar('Stat', 'v'.$ver);
		}
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

		// load records?
		if (is_null($this->_ids))
			self::_loadRecs();

		$out = true;

		switch ($cmd) {
		case DataStore::GRPS:
			// build list of records
			$out = [];
			foreach ($this->_ids as $k => $v) {
				if (substr($k, 0, 1) == DataStore::TYP_GROUP)
					$out[$k] = substr($k, 0, 1);
			}

			if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
				Msg::InfoMsg($out, 'All group records');
			break;

		case DataStore::RIDS:

			// find base group?
			if (!$parm) {

				foreach ($this->_ids as $rid => $val) {

					if (!$val[Handler::GROUP]) {

						$parm = $rid;
						break;
					}
				}
			}

			// check group (never should go here)
			if (!isset($this->_ids[$parm])) {

				Log::getInstance()->logMsg(Log::WARN, 20317, $parm, 'calendar');
				return false;
			}

			// late load group?
			if (substr($parm, 0, 1) == DataStore::TYP_GROUP && !$this->_ids[$parm][Handler::LOAD])
				self::_loadRecs($parm);

			// build list of records
			$out = [];
			foreach ($this->_ids as $k => $v) {

				if ($v[Handler::GROUP] == $parm)
					$out[$k] = substr($k, 0, 1);
			}

			if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
				Msg::InfoMsg($out, 'All record ids in group "'.$parm.'"');
			break;

		case DataStore::RGID:
			if (!is_string($parm) || !self::_chkLoad($parm) || !($out = self::_swap2int($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20311, is_string($parm) ? (substr($parm, 0, 1) == DataStore::TYP_DATA ?
						  'calendar record' : 'calendar') : gettype($parm), $parm);
				return false;
			}
			break;

		case DataStore::ADD:

			// adding default group?
			if ($parm->getVar(fldAttribute::TAG) & fldAttribute::DEFAULT) {

				// not possible, so we fake success
				$out = false;
				foreach ($this->_ids as $gid => $t)
					if ($t[Handler::ATTR] & fldAttribute::DEFAULT) {

						$out = $gid;
						break;
					}

				// update defalt group
				if ($out) {

					$parm->updVar('extID', $out);
					self::_upd($parm);
				}
				break;
			}

			// if we have no group, we switch to default group
			if (!($gid = $parm->getVar('extGroup')) || !isset($this->_ids[$gid])) {

				// set default group
				foreach ($this->_ids as $rid => $val) {

					if (substr($rid, 0, 1) == DataStore::TYP_GROUP && ($val[Handler::ATTR] & fldAttribute::EDIT)) {
						$gid = $rid;
				       	break;
					}
				}
			    $parm->updVar('extGroup', $gid);
			}

			// no group found?
			if ($parm->getVar('Type') == DataStore::TYP_DATA && !isset($this->_ids[$gid])) {

				Log::getInstance()->logMsg(Log::WARN, 20317, $gid, 'calendar');
				return false;
			}

			// add external record
			if (!($out = self::_add($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20312, $parm->getVar('Type') == DataStore::TYP_DATA ?
						  'calendar record' : 'calendar');
				return false;
			}
			break;

		case DataStore::UPD:

			$rid = $parm->getVar('extID');

			// be sure to check record is loaded
			if (!self::_chkLoad($rid)) {

				Log::getInstance()->logMsg(Log::WARN, 20313, substr($rid, 0, 1) == DataStore::TYP_DATA ?
						  'calendar record' : 'calendar', $rid);
				if ($this->_cnf->getVar(Config::DBG_LEVEL) == Config::DBG_TRACE)
					Msg::ErrMsg('Update should work - please check if synchronization is turned on!');
				return false;
			}

			// get group ID
			$gid = substr($rid, 0, 1) == DataStore::TYP_GROUP ? $rid : $this->_ids[$rid][Handler::GROUP];

			// does record exist?
			if (!isset($this->_ids[$rid]) ||
				// is record editable?
			   	!($this->_ids[$rid][Handler::ATTR] & fldAttribute::EDIT) ||
				// is group writable?^
				!($this->_ids[$gid][Handler::ATTR] & fldAttribute::WRITE)) {

				Log::getInstance()->logMsg(Log::WARN, 20315, $rid, 'calendar');
				return false;
			}

			// update external record
			if (!($out = self::_upd($parm))) {
				Log::getInstance()->logMsg(Log::WARN, 20313, substr($rid, 0, 1) == DataStore::TYP_DATA ?
						  'calendar record' : 'calendar', $rid);
				return false;
			}
    		break;

		case DataStore::DEL:

			// be sure to check record is loaded
			if (!self::_chkLoad($parm)) {
				Log::getInstance()->logMsg(Log::WARN, 20314, substr($parm, 0, 1) == DataStore::TYP_DATA ?
						  'calendar record' : 'calendar', $parm);
				return false;
			}

			// does record exist?
			if (!isset($this->_ids[$parm]) ||
				// is record a group and is it allowed to delete?
			    (substr($parm, 0, 1) == DataStore::TYP_GROUP && !($this->_ids[$parm][Handler::ATTR] & fldAttribute::DEL) ||
				// is record a data records and is it allowed to delete?
			   	(substr($parm, 0, 1) == DataStore::TYP_DATA &&
			   			!($this->_ids[$this->_ids[$parm][Handler::GROUP]][Handler::ATTR] & fldAttribute::WRITE)))) {

			   	Log::getInstance()->logMsg(Log::WARN, 20315, $parm, 'calendar');
				return false;
			}

			// delete  external record
			if (!($out = self::_del($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20314, substr($parm, 0, 1) == DataStore::TYP_DATA ?
						  'calendar record' : 'calendar', $parm);
				return false;
			}
			break;

		default:
			break;
		}

		return $out;
	}

	/**
	 * 	Get list of supported fields in external data base
	 *
	 * 	@param	- Handler ID
	 * 	@return	- [ field name ]
	 */
	public function getflds(int $hid): array {

		$rc = [];
		foreach (self::MAP as $k => $v)
			if ($v[0] != 11)
				$rc[] = $v[1];
		$k; // disable Eclipse warning

		return $rc;
	}

	/**
	 * 	Reload any cached record information in external data base
	 *
	 * 	@param	- Handler ID
	 * 	@return	- true=Ok; false=Error
	 */
	public function Refresh(int $hid): bool {

	    self::_loadRecs();

	    return true;
	}

	/**
	 * 	Check trace record references
	 *
	 *	@param 	- Handler ID
	 * 	@param 	- External record array [ GUID ]
	 * 	@param 	- Mapping table [HID => [ GUID => NewGUID ] ]
	 */
	public function chkTrcReferences(int $hid, array $rids, array $maps): void {
	}

	/**
	 * 	(Re-) load existing external records
	 *
	 *  @param 	- null= root; else <GID> to load
 	 */
	private function _loadRecs(?string $grp = null): void {

	   	// get synchronization preferences
        $p = $this->_hd->RCube->user->get_prefs();
        $this->_pref = isset($p['syncgw']) ? $p['syncgw'] : '';
        Msg::InfoMsg('Folder to synchronize "'.$this->_pref.'"');

		// set new task to do
    	$this->_hd->RCube->plugins->init($this->_hd->RCube, 'calendar');

        // re-load plugins
		$this->_hd->RCube->plugins->load_plugins($this->_hd->RCube->config->get('plugins'));

	    // get list of all calendards
        $dbh = self::_getHandler();

		// re-create list
		if (!$grp) {

			$this->_ids  = [];

			do {

				if (!($cals = $dbh->list_calendars(calendar_driver::FILTER_ALL)))
    	    		$cals = [];
			} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

    	    if (Config::getInstance()->getVar(Config::DBG_SCRIPT) == 'DBExt')
    	    	Msg::InfoMsg($cals, 'Available calendars');

		} else
			$cals = [[
				'id' => substr($grp, 1),
			]];

    	// process all calendars
		foreach ($cals as $cal) {

			if (!$grp) {

	    		// included in synchronization?
    			if (strpos($this->_pref, Handler::CAL_FULL.$cal['id'].';') === false ||
    				// skip birthday calendar
    				$cal['id'] == calendar_driver::BIRTHDAY_CALENDAR_ID)
    				continue;

		       	// enabled?
    			if (!$cal['active'])
	    	   		continue;

	    		// swap
		       	$this->_ids[$rid = DataStore::TYP_GROUP.$cal['id']] = [
						Handler::GROUP 	=> '',
			            Handler::NAME  	=> $cal['name'],
			            Handler::COLOR 	=> $cal['color'],
		 	       		Handler::ATTR	=> fldAttribute::READ|fldAttribute::WRITE,
		       			Handler::LOAD	=> 0,
    			];
		       	if ($cal['editable'])
		       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::EDIT;
		       	if ($cal['showalarms'])
		       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::ALARM;

		       	// we assume first group is default group
	       		if (count($this->_ids) == 1)
		       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::DEFAULT;
	       		else
			       	$this->_ids[$rid][Handler::ATTR] |= fldAttribute::DEL;

			} else {

	    		$this->_ids[$grp][Handler::LOAD] = 1;

				// walk trough calendar
	    		do {

					$recs = $dbh->load_events(strtotime(self::START), strtotime(self::END), null, $cal['id'], false, null);
				} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

				foreach ($recs as $rec) {

	    			// see calendar_driver.php for a description of event array()
		       		$this->_ids[DataStore::TYP_DATA.$rec['id']] = [
							Handler::GROUP 	=> $grp,
			       			Handler::ATTR	=> fldAttribute::READ|fldAttribute::WRITE|fldAttribute::EDIT|fldAttribute::DEL,
		       		];

		       	}
			}
    	}

    	if (!count($this->_ids))
    		Log::getInstance()->logMsg(Log::ERR, 20350, 'calendar', $this->_pref);

    	if (Config::getInstance()->getVar(Config::DBG_SCRIPT) == 'DBExt') {

        	$ids = $this->_ids;
        	foreach ($this->_ids as $id => $unused)
        		$ids[$id][Handler::ATTR] = fldAttribute::showAttr($ids[$id][Handler::ATTR]);
        	$unused; // disable Eclipse warning
        	Msg::InfoMsg($ids, 'Record mapping table ('.count($this->_ids).')');
    	}
	}

	/**
	 * 	Check record is loadeded
	 *
	 *  @param 	- Record id to load
	 *  @return - true=Ok; false=Error
 	 */
	private function _chkLoad(string $rid): bool {

		// any GUID given?
	    if (!$rid)
	    	return false;

	    // alreay loaded?
		if (!isset($this->_ids[$rid])) {

			foreach ($this->_ids as $id => $parm) {

				if (substr($id, 0, 1) == DataStore::TYP_GROUP && !$parm[Handler::LOAD]) {

					// load group
					self::_loadRecs($id);

					// could we load record?
					if (isset($this->_ids[$rid]))
						return true;
				}
			}

			return false;
		}

		return true;
	}

	/**
	 * 	Get external record
	 *
	 *	@param	- External record ID (string) or record array
	 * 	@return - Internal document or null
	 */
	private function _swap2int($rid): ?XML {

		$db  = DB::getInstance();

		if (!is_array($rid) && substr($rid, 0, 1) == DataStore::TYP_GROUP) {

			$int = $db->mkDoc(DataStore::CALENDAR, [
						'GID' 				 => '',
   						'Typ'   			 => DataStore::TYP_GROUP,
						'extID'				 => $rid,
						'extGroup'			 => $this->_ids[$rid][Handler::GROUP],
						fldGroupName::TAG	 => $this->_ids[$rid][Handler::NAME],
						fldColor::TAG		 => $this->_ids[$rid][Handler::COLOR],
						fldAttribute::TAG	 => $this->_ids[$rid][Handler::ATTR],
			]);

			if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document') {

				$int->updVar(fldAttribute::TAG, fldAttribute::showAttr($this->_ids[$rid][Handler::ATTR]));
				$int->getVar('syncgw');
	            Msg::InfoMsg($int, 'Internal record');
	            $int->updVar(fldAttribute::TAG, strval($this->_ids[$rid][Handler::ATTR]));
			}

			return $int;
		}

		// load external record
		if (is_array($rid)) {

			$rec = $rid;
			$rid = 'R'.$rec['id'];
		} elseif (!($rec = self::_get($rid)))
			return null;

		$att = Attachment::getInstance();
		$int = $db->mkDoc(DataStore::CALENDAR, [
						'GID' 		=> '',
						'extID'		=> $rid,
						'extGroup'	=> $this->_ids[$rid][Handler::GROUP],
		]);

	    // disable attachment size check for WebDAV
		$hack = $this->_cnf->getVar(Config::HACK);
		$this->_cnf->updVar(Config::HACK, $hack | Config::HACK_SIZE);

	    // swap data
		$int->getVar('Data');
		foreach (self::MAP as $key => $tag) {

			// empty field?
			if (!isset($rec[$key]))
			    continue;

	        if (!is_object($val = $rec[$key]) && !intval($val) && (is_string($val) && !strlen($val)))
				continue;

		    switch ($tag[0]) {
			// String
		    case 0:
		        // skip defaults
		        if (($key == 'priority' || $key == 'sequence') && !$val)
		            break;

			   	// special check for birthday event
		        if (!strcmp(strval($val), '[birthdayeventtitle]'))
		            $val = sprintf('Birthday of %s', $rec['_displayname']);
		    	$int->addVar($tag[1], (string)($val), false, $tag[1] == fldBody::TAG ? [ 'X-TYP' => fldBody::TYP_TXT ] : []);
	            break;

			// String (convert to upper case)
	    	case 1:
	    		$int->addVar($tag[1], strtoupper($val));
				break;

	        // Date
			case 2:
				// ensure UTC is set
		        $rec[$key]->setTimezone(new \DateTimeZone('UTC'));
		        // special handling for all day events
		        if ($rec['allday']) {

		        	$v = new \DateTime($rec[$key]->format('d.m.Y').' 00:00');
		        	if ($key == 'end')
		        		$v->add(new \DateInterval('PT24H'));
					$int->addVar($tag[1], $v->format('U'), false, [ 'VALUE' => 'date' ]);
		        } else
					$int->addVar($tag[1], Util::mkTZOffset($val->format('U')));
				break;

			// Recurrence array
			case 3:
			   	$v  = '';
				$ip = $int->savePos();
				$int->addVar($tag[1]);
				foreach ($val as $k => $v) {

					// format date/time
				    if ($v instanceof \DateTime)
	    	    		$v = $v->format(Config::UTC_TIME);

	   		   		if (!isset(fldRecurrence::RFC_SUB[$k])) {

		    			// <Exceptions>
	   		   			if (count($val[$k])) {

	   		   				if (!$int->xpath('//Data/'.fldExceptions::TAG)) {

	   		   					$int->getVar('Data');
		    					$int->addVar(fldExceptions::TAG);
	   		   				} else
	   		   					$int->getItem();
	   		   			}
		    			$p = $int->savePos();
		    			foreach ($val[$k] as $v) {

		    				// <Exception>
		    				$int->addVar(fldExceptions::SUB_TAG[0]);
		    				if ($k == 'EXDATE') {

			   					// <InstanceId> original start time of recurrence event
			   					// don't need to be converted, since it is already UTC time stamp
								$int->addVar(fldExceptions::SUB_TAG[2], $v->format('U'));
								// <Delete>
		    					$int->addVar(fldExceptions::SUB_TAG[1]);

		    				} elseif ($k == 'EXCEPTIONS') {

		    					// load record - we temporary fake an existing record
		    					$this->_ids['X'.$v['id']][Handler::GROUP] = $this->_ids[$rid][Handler::GROUP];
		    					$dl = $this->_cnf->updVar(Config::DBG_LEVEL, Config::DBG_OFF);
		    					if ($x = self::_swap2int('X'.strval($v['id']))) {

		    						// delete dummy record frrom list of available records
		    						unset($this->_ids['X'.$v['id']]);

		    						// delete unsupported <Exception> fields
									$x->xpath('//Data/Exceptions');
									while ($x->getItem() !== null)
										$x->delVar(null, false);

									// swap fields
									$x->getChild('Data');
									while ($x->getItem() !== null)
										$int->append($x, false);
		    					}
		    					$this->_cnf->updVar(Config::DBG_LEVEL, $dl);
		    				} else
								$this->_msg->WarnMsg('+++ Undefined sub field ['.$k.']');
		    			$int->restorePos($p);
		    			}
	   		   		} else {

		   	    		if ($k == 'UNTIL')
		   	    			$v = Util::mkTZOffset(Util::unxTime($v));
			   			$int->addVar(fldRecurrence::RFC_SUB[$k], strval($v));
	   		   		}
	    	    }
	    	    $int->restorePos($ip);
	    	    break;

			//  Attendee array
			case 4:
			// Organizer array
			case 5:
			   	foreach ($val as $val) {

					$a = [];
					$e = null;
					$o = false;
					if (isset($val['cutype']) && $val['cutype'])
	    				$a['CUTYPE'] = $val['cutype'];
				    if (isset($val['status']) && $val['status'])
						$a['PARTSTAT'] = $val['status'];
					$a['RSVP'] = isset($val['rsvp']) ? 'true' : 'false';
					if (isset($val['name']) && $val['name'])
		  			    $a['CN'] = $val['name'];
					$e = $val['email'];
		  			if (isset($val['role']) && $val['role']) {

						if (($a['ROLE'] = $val['role']) == 'ORGANIZER') {

							$e = $val['email'] ? $val['email'] : substr($val['emails'], 1);
							$o = true;
						}
					}
					$int->addVar($o ? fldOrganizer::TAG : $tag[1], 'mailto:'.$e, false,  $a);
				}
				break;

			// VALARM
			case 6:
				$ip = $int->savePos();
		        foreach ($val as $r) {
		           	$p = $int->savePos();
					$int->addVar($tag[1]);
		           	$int->addVar(fldAlarm::SUB_TAG['VCALENDAR/%s/VALARM/ACTION'], $r['action']);
		           	if ($r['trigger'] instanceof \DateTime)
			           	$int->addVar(fldTrigger::TAG, Util::mkTZOffset($r['trigger']->format('U')),
			           				 false, [ 'VALUE' => 'date-time' ]);
		           	else
			           	$int->addVar(fldTrigger::TAG, Util::cnvDuration(true, $r['trigger']), false,
			           		[ 'VALUE' => 'duration', 'RELATED' => isset($r['related']) ? $r['related'] : 'start' ]);

		           	if (isset($r['attachment'])) {

	           			$p = $int->savePos();
		           		$int->addVar(fldAttach::TAG);
	           			$int->addVar(fldAttach::SUB_TAG[1], $att->create($r['attachment']));
	           			$int->restorePos($p);
	           		}
	           		if (isset($r['email']))
		           		$int->addVar(fldMailOther::TAG, $r['email']);
		           	if (isset($r['summary']))
		           		$int->addVar(fldSummary::TAG, $r['summary']);
		           	if (isset($r['description']))
		           		$int->addVar(fldBody::TAG, $r['description'], false, [ 'X-TYP' => fldBody::TYP_TXT ]);
			        $int->restorePos($p);
		        }
		        $int->restorePos($ip);
			   	break;

			// Attachment
			case 7:
		    	foreach ($val as $val) {

		    		$dbh = self::_getHandler($rid);
		    		do {

		    			$v = $dbh->get_attachment_body($val['id'], $rec);
			        } while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

			        if ($this->_hd->Retry) {

		    			$p = $int->savePos();
	            		$int->addVar($tag[1]);
	            		$int->addVar(fldAttach::SUB_TAG[0], $val['name']);
	            		$int->addVar(fldAttach::SUB_TAG[1], $att->create($v, $val['mimetype']));
	            		// normal attachment
	            		$int->addVar('Method', '1');
	            		$int->addVar('EstimatedDataSize', $att->getVar('Size'));
	            		$int->restorePos($p);
		    		}
		    	}
		    	break;

		    // Free_busy
	   		case 8:
	    		if (isset(self::BUSY[$val]))
			    	$int->addVar($tag[1], self::BUSY[$val]);
		    	break;

		    // Location
	   		case 9:
	   			$ip = $int->savePos();
				$int->addVar($tag[1]);
				$int->addVar(fldLocation::SUB_TAG, $val);
				$int->restorePos($ip);
				break;

		    // Instance id
	   		case 10:
	   			// <InstanceId>
				$int->addVar(fldExceptions::SUB_TAG[2], Util::mkTZOffset(Util::unxTime($val)));

	   		default:
				break;
			}
		}

		// enable attachment size check
		$this->_cnf->updVar(Config::HACK, $hack);

		if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document') {

			$int->getVar('syncgw');
            Msg::InfoMsg($int, 'Internal record created');
		}

        return $int;
	}

	/**
	 * 	Swap internal to external record
	 *
	 *	@param 	- Internal document
	 * 	@return - External document
	 */
	private function _swap2ext(XML &$int): array {

		// get record id
		$rid = $int->getVar('extID');

		// is record a calendar?
	    if (substr($rid, 0, 1) == DataStore::TYP_GROUP) {

	    	$attr = $int->getVar(fldAttribute::TAG);
			$rec  = [
					'id'		 => substr($rid, 1),
					'name'		 => $int->getVar(fldGroupName::TAG),
					'color'		 => $int->getVar(fldColor::TAG),
					'showalarms' => $attr & fldAttribute::ALARM,
					'editable'	 => $attr & fldAttribute::EDIT,
			];
	    } else {

	       	// disable attachment size check for WebDAV
			$hack = $this->_cnf->getVar(Config::HACK);
			$this->_cnf->updVar(Config::HACK, $hack | Config::HACK_SIZE);

		    // output record
			$rec = [
						'id'	 		=> substr($rid, 1),
						'event_id' 		=> substr($rid, 1),
						'calendar'		=> substr($int->getVar('extGroup'), 1),
						'allday' 		=> 0,
						'recurrence_id'	=> 0,
						'free_busy'		=> 'free',
			];

			// load list of attachment record ids
			$dbh = self::_getHandler($rid);
			do {

				$arec = $dbh->list_attachments($rec);
			} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));
			$att = Attachment::getInstance();

			$int->getVar('Data');
			foreach (self::MAP as $key => $tag) {

				$ip = $int->savePos();

			    switch ($tag[0]) {
				// String
			    case 0:
			    	if (!$int->xpath($tag[1], false))
			    		break;
		    		$rec[$key] = Encoding::cnvStr($int->getItem(), false);
			    	break;

			    // String (convert to upper case)
	    		case 1:
			    	if (!$int->xpath($tag[1], false))
			    		break;
			    	if ($val = $int->getItem())
	    				$rec[$key] = Encoding::cnvStr(strtolower($val), false);
					break;

			    // Date
			    case 2:
			    	if (!$int->xpath($tag[1], false))
			    		break;
			    	if ($val = $int->getItem()) {
			    		$rec[$key] = new \DateTime(gmdate(Config::UTC_TIME, intval(Util::mkTZOffset($val, true))),
			    					 new \DateTimeZone('UTC'));
			    		if ($int->getAttr('VALUE') == 'date')
			       	        $rec['allday'] = 1;
			    	}
			    	break;

				// Recurrence array
			    case 3:
					$int->xpath($tag[1], false);
					while ($int->getItem() !== null) {

						// be sure to set exceptions array
						if (!isset($rec[$key]))
							$rec[$key] = [ 'EXCEPTIONS' => [] ];
						foreach (fldRecurrence::RFC_SUB as $k => $v) {

							if (substr($k, 0, 2) == 'X-')
								continue;
							$p = $int->savePos();
							$int->xpath($v, false);
							while (($val = $int->getItem()) !== null) {

								if ($k == 'UNTIL')
			    					$val = new \DateTime(gmdate(Config::UTC_TIME,
			    										intval(Util::mkTZOffset($val, true))),
			    										new \DateTimeZone('UTC'));
								$rec[$key][$k] = $val;
							}
							$int->restorePos($p);
						}
					}

					// check for exceptions
					$int->restorePos($ip);
					// <Exceptions><Exception>
					$int->xpath(fldExceptions::TAG.'/'.fldExceptions::SUB_TAG[0], false);
					while ($int->getItem() !== null) {

						$p = $int->savePos();
						// <Delete>
						if ($int->xpath('./'.fldExceptions::SUB_TAG[1], false)) {

							$int->restorePos($p);
							// excluded dates
							$rec[$key]['EXDATE'][] = new \DateTime(gmdate(Config::UTC_TIME,
													 intval($int->getVar(fldExceptions::SUB_TAG[2], false))),
													 new \DateTimeZone('UTC'));
						} else {

							$doc = new XML();
							$doc->loadXML('<syncgw><extID/><extGroup>'.$int->getVar('extGroup').
										  '</extGroup></syncgw>');
							$int->restorePos($p);
							if ($rid)
								$doc->updVar('extID', $rid);
							$doc->getVar('extGroup');
							$doc->append($int, false);
							// <Exception>
							$doc->getVar(fldExceptions::SUB_TAG[0]);
							$doc->setName('Data');
							$doc->getVar('syncgw');
							Msg::InfoMsg($doc, 'Exception document');
							$exr = self::_swap2ext($doc);
							$exr['recurrence_id'] = substr($rid, 1);
							// this will not work so easy...
							$exr['isexception']	  = 1;
							unset($exr['deleted_attachments']);
							$rec[$key]['EXCEPTIONS'][] = $exr;
						}
						$int->restorePos($p);
					}
					break;

	            // Organizer array
			    case 5:
			    	$int->xpath(fldOrganizer::TAG, false);
					while ($val = $int->getItem()) {

			    		if (!isset($rec[$key]))
	                   		$rec[$key] = [];
			    		$a = $int->getAttr();

			    		// plugins/calendar/drivers/kolab/kolab_user_calendar.php
		                $rec[$key][] = [
	        			    'role'   => isset($a['ROLE']) ? $a['ROLE'] : '',
							'emails' => ';'.substr($val, 7),
		                	'email'  => '',
		                	'name'	 => isset($a['CN']) ? Encoding::cnvStr($a['CN'], false) : '',
	        			    'rsvp'   => isset($a['RSVP']) ? $a['RSVP'] == 'true' ? 1 : 0 : '',
		                ];

		                // we need to add organizer to attemdee list
		                $key = 'attendees';
				    	if (!isset($rec[$key]))
		                	$rec[$key] = [];
						$rec[$key][] = [
		    	                'role'   => 'ORGANIZER',
								'cutype' => '',
		        			    'rsvp'   => isset($a['RSVP']) ? $a['RSVP'] == 'true' ? 1 : 0 : 1,
		        			    'email'  => substr($val, 7),
			                	'name'	 => isset($a['CN']) ? Encoding::cnvStr($a['CN'], false) : '',
		        		    	'status' => isset($a['PARTSTAT']) ? $a['PARTSTAT'] : '',
		               		 ];
					}
					break;

				// Attendee array
			    case 4:
					$int->xpath($tag[1], false);
					while ($val = $int->getItem()) {

			    		if (!isset($rec[$key]))
	                   		$rec[$key] = [];
			    		$a = $int->getAttr();
		                $rec[$key][] = [
	    	                'role'   => isset($a['ROLE']) ? $a['ROLE'] : '',
							'cutype' => isset($a['CUTYPE']) ? $a['CUTYPE'] : '',
	        			    'rsvp'   => isset($a['RSVP']) ? $a['RSVP'] == 'true' ? 1 : 0 : '',
	        			    'email'  => substr($val, 7),
		                	'name'	 => isset($a['CN']) ? Encoding::cnvStr($a['CN'], false) : '',
	        		    	'status' => isset($a['PARTSTAT']) ? $a['PARTSTAT'] : '',
	               		 ];
	                }
	                break;

			    // VALARM
			    case 6:
					$int->xpath($tag[1], false);
					$n = -1;
			    	while ($int->getItem() !== null) {

			    		$p = $int->savePos();
	                    if (!isset($rec[$key]))
	                    	$rec[$key] = [];

	                    if ($val = $int->getVar(fldAlarm::SUB_TAG['VCALENDAR/%s/VALARM/ACTION'], false))
		                    $rec[$key][++$n]['action'] = $val;

		                $int->restorePos($p);
	                    if (($val = $int->getVar(fldTrigger::TAG, false)) !== null) {

		                	if ($int->getAttr('VALUE') == 'duration')
	                        	$rec[$key][$n]['trigger'] = Util::cnvDuration(false, $val);
		                    else
		                        $rec[$key][$n]['trigger'] = new \DateTime(gmdate(Config::UTC_TIME,
		                        								intval(Util::mkTZOffset($val, true))),
		                        								new \DateTimeZone('UTC'));
			                if ($v = $int->getAttr('RELATED'))
			                    $rec[$key][$n]['related'] = strtolower($v);
	                    }

		                $int->restorePos($p);
	                    if ($int->getVar(fldAttach::TAG, false) !== null)
	                    	$rec[$key][$n]['attachment'] = $att->read($int->getVar(fldAttach::SUB_TAG[1], false));

		                $int->restorePos($p);
	                    if ($val = $int->getVar(fldMailOther::TAG, false))
		                    $rec[$key][$n]['email'] = Encoding::cnvStr($val, false);

		                $int->restorePos($p);
	                    if ($val = $int->getVar(fldSummary::TAG, false))
		                    $rec[$key][$n]['summary'] = Encoding::cnvStr($val, false);

		                $int->restorePos($p);
	                    if ($val = $int->getVar(fldBody::TAG, false))
		                    $rec[$key][$n]['description'] = Encoding::cnvStr($val, false);

		                $int->restorePos($p);
					}
			    	break;

			    // Attachment
				case 7:
					$int->xpath($tag[1], false);
					while ($int->getItem() !== null) {

						if (!isset($rec[$key]))
	                		$rec[$key] = [];

	                	$p    = $int->savePos();
	                	$data = $att->read($int->getVar(fldAttach::SUB_TAG[1], false));
	                	$mime = $att->getVar('MIME');
	                	$size = $att->getVar('Size');
	                	if ($name = $int->getVar(fldAttach::SUB_TAG[0]))
		                	$name = rawurldecode($name);
	                	else
	                		$name = 'Attachment'.Util::getFileExt($mime);
    		          	$int->restorePos($p);

	                	// find attachment record id
	                	$r = [ 'id' => 0 ];
                		foreach ($arec as $r) {

		                	if (!strcmp($r['name'], $name) && $r['size'] == $size)
		                		break;
						}

	               		$rec[$key][] = [
	               				'id'	   => $r['id'],
	            	            'name'     => $name,
	            		        'mimetype' => $mime,
	               				'size'	   => $size,
	                			'data' 	   => $data,
	               		];
		              	$int->restorePos($p);
					}
	               	break;

			    // Free_busy
			    case 8:
					if (!$int->xpath('//'.$tag[1]))
			    		break;
			    	$a = array_flip(self::BUSY);
			    	if ($val = $int->getItem())
			    	    $rec[$key] = $a[$val];
	    	        break;

			    // Location
			    case 9:
					$int->xpath($tag[1].'/'.fldLocation::SUB_TAG, false);
			    	if ($val = $int->getItem())
	                   	$rec[$key] = $val;
					break;

			    // Instance id
	    		case 10:
			    	if ($int->xpath(fldExceptions::SUB_TAG[2], false))
			    		$rec[$key] = gmdate(Config::UTC_TIME, intval(Util::mkTZOffset($int->getItem(), true)));

			    default:
	               	break;
				}

				$int->restorePos($ip);
			}

			// enable attachment size check
			$this->_cnf->updVar(Config::HACK, $hack);

			// patch missing end time
	        if (!isset($rec['end']) && isset($rec['start']))
	            $rec['end'] = $rec['start'];

	        // check uid
	        if (!isset($rec['uid'])) {

		    	// change UID - calendar.php: generate_uid()
		    	$usr = User::getInstance();
				$rec['uid'] = strtoupper(md5(time().uniqid(strval(rand()))).'-'.substr(md5($usr->getVar('GUID')), 0, 16));
	        }
	    }

	    // show record
		if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document') {

			$xr = $rec;
        	if (isset($xr['attachments']))
        		// replace any binary data
	            foreach ($xr['attachments'] as $k => $v)
    	            $xr['attachments'][$k]['data'] = Trace::BIN_DATA;
 			Msg::InfoMsg($xr, '_swap2ext: External record');
		}

 		return $rec;
	}

	/**
	 * 	Get data base handler
	 *
	 * 	@return - Data base driver
	 */
	private function _getHandler(): database_driver {

	   	// get data base handler -> calendar.php:function load_driver()
   		$cp = $this->_hd->RCube->plugins->get_plugin('calendar');
   		$cp->require_plugin('libcalendaring');

   		// initialize some variables -> calendar.php:setup()
   		$cp->lib             = libcalendaring::get_instance();
   		$cp->timezone        = $cp->lib->timezone;
        $cp->gmt_offset      = $cp->lib->gmt_offset;
        $cp->dst_active      = $cp->lib->dst_active;
        $cp->timezone_offset = $cp->gmt_offset / 3600 - $cp->dst_active;

        // allocate handler -> calendar.php:load_driver()
   		$n = $this->_hd->RCube->config->get('calendar_driver', 'database');
   		$c = $n . '_driver';

   		require_once($cp->home.'/drivers/calendar_driver.php');
        require_once($cp->home.'/drivers/'.$n.'/'.$c.'.php');

        return new $c($cp);
	}

	/**
	 *  Get record
	 *
	 *  @param  - Record Id
	 *  @return - External record or null on error
	 */
	private function _get(string $rid): ?array {

		// get data base handler
		$dbh = self::_getHandler($rid);

		// get record
        do {

        	$rec = $dbh->get_event([
						'id' 			=> substr($rid, 1),
        				'calendar' 		=> substr($this->_ids[$rid][Handler::GROUP], 1),
		        		'recurrence'	=> true,
						], 0, true);
		} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

        if (!$this->_hd->Retry || $rec === false)
        	return null;

		if ($this->_cnf->getVar(Config::DBG_SCRIPT) && $this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
            Msg::InfoMsg($rec, '_get: External record');

        return $rec;
	}

	/**
	 *  Add external record
	 *
	 *  @param  - XML record
	 *  @return - New record Id or null on error
	 */
	private function _add(XML &$int): ?string {

		// create external record
		$rec = self::_swap2ext($int);

		// dates to exclude
		$exdate = [];

		// create a new calendar?
		if ($int->getVar('Type') == DataStore::TYP_GROUP) {

			// get data base handler (for root group id)
			$dbh = self::_getHandler();

			// get properties => database_driver.php:create_calendar()
			do {

				$rid = $dbh->create_calendar($rec);
			} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

	        if (!$this->_hd->Retry)
    	    	return null;

			// enable new calendar for synchronization
			$this->_pref = $this->_pref.Handler::CAL_FULL.$rid.';';
   			$this->_hd->RCube->user->save_prefs([ 'syncgw' => $this->_pref ]);

	    	// add record to internal managament
	     	$rid = DataStore::TYP_GROUP.$rid;
   	    	if ($a = $int->getVar(fldAttribute::TAG))
   	    		$a = intval($a);
    		else {

   	    		$a = fldAttribute::READ|fldAttribute::WRITE;
	       		if ($rec['editable'])
	       			$a |= fldAttribute::EDIT|fldAttribute::DEL;
	       		if ($rec['showalarms'])
	       			$a |= fldAttribute::ALARM;
    		}
    		$this->_ids[$rid] = [
					Handler::GROUP 	=> '',
			        Handler::NAME  	=> $rec['name'],
			        Handler::COLOR 	=> $rec['color'],
	 	       		Handler::ATTR	=> $a,
	   				Handler::LOAD	=> 0,
    		];

			// save record id
			$int->updVar('extGroup', $rid);

		} else {

			// get data base handler (for group id)
			$dbh = self::_getHandler($gid = $int->getVar('extGroup'));

			// save exclude dates
			$exdate = isset($rec['recurrence']['EXDATE']) ? new \ArrayObject($rec['recurrence']['EXDATE']) : [];

			// special check to catch calendar handler error if too much exclude dates has been given
			// (recurrence field is only VARCHAR(256) and cannot be expanded)
			if (count($exdate) > 8) {

				$srec = new \ArrayObject($rec);

				// get end date
				$e = clone $rec['recurrence']['EXDATE'][8];
				$srec['recurrence']['UNTIL'] = $e;
				$t = $e->getTimestamp();
				for ($i=count($srec['recurrence']['EXDATE'])-1; $i; $i--) {

					if ($srec['recurrence']['EXDATE'][$i]->getTimestamp() > $t)
						unset($srec['recurrence']['EXDATE'][$i]);
				}

				// save sub record
				do {

					$id = $dbh->new_event($srec);
				} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

				if (!$this->_hd->Retry)
        			return null;

       			// add records to known list
				$this->_ids[DataStore::TYP_DATA.$id] = [

						Handler::GROUP 	=> $gid,
						Handler::ATTR	=> fldAttribute::READ|fldAttribute::WRITE|fldAttribute::EDIT|fldAttribute::DEL,
				];

        		// modify original record
        		$rec['id'] = $rec['recurrence_id'] = $id;
				$rec['start']->setDate(intval($e->format('Y')), intval($e->format('m')),
									   intval($e->format('d')));
				$rec['end']->setDate(intval($e->format('Y')), intval($e->format('m')),
									   intval($e->format('d')));
				$e->setTime(intval($rec['start']->format('H')), intval($rec['start']->format('i')),
							intval($rec['start']->format('s')));
				$t = $e->getTimestamp();
				for ($i=0; $i < count($rec['recurrence']['EXDATE']); $i++) {

					if ($rec['recurrence']['EXDATE'][$i]->getTimestamp() <= $t)
						unset($rec['recurrence']['EXDATE'][$i]);
				}

				// re-number array
				$i = 0;
				$a = $rec['recurrence']['EXDATE'];
				unset($rec['recurrence']['EXDATE']);
				foreach ($a as $t)
					$rec['recurrence']['EXDATE'][] = $t;

				// get next offset
				if (!($xml = fldRecurrence::regenerate(DataStore::CALENDAR, self::_swap2int($rec))))
					return null;
				$rec = self::_swap2ext($xml);
			} else
				$id = -1;

			do {

				$rid = $dbh->new_event($rec);
			} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

        	if (!$this->_hd->Retry)
        		return null;

			// get table name
		    $tab   = '`'.$this->_hd->RCube->config->get('db_table_lists',
		    											$this->_hd->RCube->db->table_name('events')).'`';

       		// delete exluded dates
        	foreach ($exdate as $ex) {

		        $sql = 'SELECT event_id FROM '.$tab.
	                   ' WHERE recurrence_id in('.$id.','.$rid.') AND start = ? AND end = ?';
			   	$st  = $ex->format('Y-m-d').' '.$rec['start']->format('H:i:s');
			    $et  = $ex->format('Y-m-d').' '.$rec['end']->format('H:i:s');
				do {

					if ($res = $this->_hd->RCube->db->query($sql, $st, $et))
				    	$r = $this->_hd->RCube->db->fetch_assoc($res);
				} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

	    		if (!$this->_hd->Retry)
    	    		return null;

    	    	if ($r) {

    	    		$sql = 'DELETE FROM '.$tab.' WHERE event_id = ?';
    	    		do {

						$this->_hd->RCube->db->query($sql, $r['event_id']);
					} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

	    			if (!$this->_hd->Retry)
    	    			return null;
    	    	}
        	}

		    // because of special design in database_driver.php, we must handle exception records this way
			if (isset($rec['recurrence']['EXCEPTIONS'])) {

				foreach ($rec['recurrence']['EXCEPTIONS'] as $xrec) {

			        $sql = 'SELECT * FROM '.$tab.
		                   ' WHERE recurrence_id = ? AND start LIKE ? AND end LIKE ?';
			        $st  = isset($xrec['start']) ? $xrec['start']->format('Y-m-d\%') : $rec['start']->format('Y-m-d\%');
			        $et  = isset($xrec['end']) ? $xrec['end']->format('Y-m-d\%') : $rec['end']->format('Y-m-d\%');
					do {

				        if ($res = $this->_hd->RCube->db->query($sql, $rid, $st, $et))
				        	$r = $this->_hd->RCube->db->fetch_assoc($res);
					} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

	    		    if (!$this->_hd->Retry)
    	    			return null;

					// update exception record
    	    		if (is_array($r)) {

    	    			$xrec['id'] 		 	= $r['event_id'];
    	    			$xrec['recurrence_id'] 	= $rid;
						$xrec['_savemode'] 		= 'current';
						do {

	        				$dbh->edit_event($xrec);
						} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));
    	    		}
				}
			}

			// add record to internal managament
	       	$rid = DataStore::TYP_DATA.$rid;

			// add records to known list
			$this->_ids[$rid] = [
					Handler::GROUP 	=> $gid,
					Handler::ATTR	=> fldAttribute::READ|fldAttribute::WRITE|fldAttribute::EDIT|fldAttribute::DEL,
			];

			// save record id
			$int->updVar('extID', $rid);
			$int->updVar('extGroup', $gid);
		}

		$id = $this->_ids[$rid];
        $id[Handler::ATTR] = fldAttribute::showAttr($id[Handler::ATTR]);
		Msg::InfoMsg($id, 'New mapping record "'.$rid.'" ('.count($this->_ids).')');

		return $rid;
	}

	/**
	 *  Update external record
	 *
	 *  @param  - XML record
	 *  @param	- External record
	 *  @return - true or false on error
	 */
	private function _upd(XML &$int): bool {

		// get record id
		$rid = $int->getVar('extID');

		// get data base handler
		$dbh = self::_getHandler($rid);

		// create external record
		$rec = self::_swap2ext($int);

		if (substr($rid, 0, 1) == DataStore::TYP_GROUP) {

			// swap data
            $this->_ids[$rid][Handler::NAME]  	= $rec['name'];
            $this->_ids[$rid][Handler::COLOR] 	= $rec['color'];
	       	if ($this->_ids[$rid][Handler::ATTR] & fldAttribute::DEFAULT)
	            $this->_ids[$rid][Handler::ATTR] = fldAttribute::READ|fldAttribute::WRITE|fldAttribute::DEFAULT;
			else
	            $this->_ids[$rid][Handler::ATTR] = fldAttribute::READ|fldAttribute::WRITE;
	       	if ($rec['editable'])
	       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::EDIT;
	       	if ($rec['showalarms'])
	       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::ALARM;

	       	do {

	       		$dbh->edit_calendar($rec);
			} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

   		    if (!$this->_hd->Retry)
    			return false;

	       	return true;
		}

		// exception records?
   		if (isset($rec['recurrence'])) {

			// get table name
			$tab   = '`'.$this->_hd->RCube->config->get('db_table_lists', $this->_hd->RCube->db->table_name('events')).'`';
		    $rid   = $rec['id'];

   			// because of of special database_driver.php code, we must handle recurrence ourself
	  		if (!empty($rec['recurrence']['EXDATE'])) {

	  			$sql = 'SELECT * FROM '.$tab.' WHERE event_id = ?';
		       	do {

		  			if ($res = $this->_hd->RCube->db->query($sql, $rid))
		  				$r = $this->_hd->RCube->db->fetch_assoc($res);
				} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

   			    if (!$this->_hd->Retry)
    				return false;

	        	// update recurrence
	        	// FREQ=DAILY;COUNT=5;INTERVAL=1;EXDATE=20220405T100000
	        	if (($p = strpos($r['recurrence'], ';EXDATE')) !== false)
	        		$r['recurrence'] = substr($r['recurrence'], 0, $p);
	        	$r['recurrence'] .= ';EXDATE=';
	        	$s   = '';
	        	$sql = 'DELETE FROM '.$tab.
	        		   ' WHERE recurrence_id = ? AND instance = ?';
	        	foreach($rec['recurrence']['EXDATE'] as $d) {

	        		$r['recurrence'] .= $s.$d->format(Config::STD_TIME);
	        		$s = ',';
	        		// be sure to delete record if it exist
	        		do {

	        			$this->_hd->RCube->db->query($sql, $rid, $d->format(Config::STD_TIME));
					} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

   				    if (!$this->_hd->Retry)
    					return false;
	        	}

 		       	// update recurrence string
	 	        $sql = 'UPDATE '.$tab.' SET `recurrence` = ?'.
	                   ' WHERE event_id = ?';
	        	do {

	 	        	if ($res = $this->_hd->RCube->db->query($sql, $r['recurrence'], $rid))
	 	        		$this->_hd->RCube->db->affected_rows($res);
				} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

   			    if (!$this->_hd->Retry)
    				return false;
	 	    }

	  		if (!empty($rec['recurrence']['EXCEPTIONS'])) {

	        	// create all exceptions new
	  			foreach ($rec['recurrence']['EXCEPTIONS'] as $xrec) {

		  			// first we delete exception record
		        	$sql = 'DELETE FROM '.$tab.
		        		   ' WHERE recurrence_id = ? AND instance LIKE ?';
	        		do {

			        	$this->_hd->RCube->db->query($sql, $rid, substr($xrec['_instance'], 0, 8).'%');
					} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

   			    	if (!$this->_hd->Retry)
    					return false;

				    $xrec['_savemode'] = 'current';

		        	// we need to reset instance id to value provided
	        		do {

					    $id = $dbh->edit_event($xrec);
					} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

   			    	if (!$this->_hd->Retry)
    					return false;

	        		$sql = 'UPDATE '.$tab.
	        			   ' SET instance = ? WHERE event_id = ?';
	        		do {

	  		      		$this->_hd->RCube->db->query($sql, $xrec['_instance'], $id);
					} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

   			    	if (!$this->_hd->Retry)
    					return false;
        		}
	  		}

   		}

	  	// hack to ensure deletion of existing attachments
	  	$rec['deleted_attachments'] = [];
	   	if (isset($rec['attachments'])) {

	    	foreach ($rec['attachments'] as $a)
	        	$rec['deleted_attachments'][] = $a['id'];
	   	}

	    do {

	    	$dbh->edit_event($rec);
		} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

    	if (!$this->_hd->Retry)
			return false;

	    return true;
 	}

	/**
	 * 	Delete record
	 *
	 * 	@param 	- Record id
	 * 	@return - true=Ok, false=Error
	 */
	private function _del(string $rid): bool {

	   	// get data base handler
		$dbh = self::_getHandler($rid);

 	    // delete calendar
        if (substr($rid, 0, 1) == DataStore::TYP_GROUP) {

			// delete all sub records
           	foreach ($this->_ids as $id => $parms) {

                if ($parms[Handler::GROUP] == $rid)
                    if (!self::_del($id))
   	                	return false;
            }

            do {

            	$dbh->delete_calendar([ 'id' => substr($rid, 1) ]);
			} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

    		if (!$this->_hd->Retry)
				return false;

	       	// disable calendar for synchronization
            $this->_pref = str_replace(Handler::CAL_FULL.substr($rid, 1).';', '', $this->_pref);
   			$this->_hd->RCube->user->save_prefs([ 'syncgw' => $this->_pref ]);

        } else {

       		// get record
       		$rec = self::_get($rid);

		    // delete any existing attachments
       		do {

       			foreach ($dbh->list_attachments($rec) as $a)
        			$rec['deleted_attachments'][] = $a['id'];
			} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

    		if (!$this->_hd->Retry)
				return false;

 			// delete record (including any exception)
			do {

				$dbh->remove_event($rec);
			} while ($this->_hd->chkRetry(DataStore::CALENDAR, __LINE__));

    		if (!$this->_hd->Retry)
				return false;
		}

 		// remove record from list
      	unset($this->_ids[$rid]);

      	return true;
	}

}
